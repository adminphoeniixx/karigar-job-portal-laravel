<?php

namespace App\Services;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Exceptions\AccountDeletionRefused;
use App\Jobs\DeleteStoredFiles;
use App\Models\Invoice;
use App\Models\ScreeningCall;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deleting an account, the same way from the apps, the website and the admin
 * panel.
 *
 * The user row goes, and the foreign keys take what hangs off it: profile,
 * jobs and their applicants, applications, chats, KYC, plans, AI credits,
 * team links. Around that, this does what a cascade cannot:
 *
 * - stops every Razorpay subscription that could still charge, before
 *   anything is deleted, so a deleted employer is never charged again;
 * - keeps the invoices (tax law), with the buyer's details copied onto them;
 * - keeps the reviews the user wrote, without their name (the key is nulled);
 * - takes their jobs or karigar profile out of search;
 * - deletes their files: logo, photo, résumé, KYC documents, call recordings.
 *
 * The public /delete-account page promises exactly this; change both together.
 */
class AccountDeletion
{
    /** Plans Razorpay can still charge on its own: a mandate is in place. */
    private const CHARGEABLE = [
        SubscriptionStatus::Authenticated, SubscriptionStatus::Active,
        SubscriptionStatus::Pending, SubscriptionStatus::Halted,
    ];

    public function __construct(
        private RazorpayService $razorpay,
        private Msg91Service $otp,
    ) {}

    /**
     * Why the owner cannot delete this account themselves, or null.
     *
     * `test_account`: one of the shared app-testing logins (AUTH_TEST_PHONE);
     * deleting it would wipe the demo data everyone tests with.
     * `admin_account`: admins are removed by hand.
     */
    public function blocker(User $user): ?string
    {
        return match (true) {
            $user->role === UserRole::Admin => 'admin_account',
            filled($user->phone) && $this->otp->isTestPhone((string) $user->phone) => 'test_account',
            default => null,
        };
    }

    public static function blockerMessage(string $reason): string
    {
        return match ($reason) {
            'test_account' => __('This is a shared test account, so it cannot be deleted. Sign up with your own number to try deleting an account.'),
            default => __('This account cannot be deleted from here.'),
        };
    }

    /**
     * What deleting the account takes with it, for the confirm sheet. A team
     * member deletes only their own login: the company's plan, jobs and
     * credits belong to the owner and are left out.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $blocker = $this->blocker($user);
        $employer = $user->role === UserRole::Employer;
        $teamMember = $employer && $user->teamMembership()->exists();
        $owner = $employer && ! $teamMember;

        $plans = $owner
            ? Subscription::where('employer_id', $user->id)->entitled()->with('plan')->get()
                ->map(fn (Subscription $s) => [
                    'name' => $s->plan?->name,
                    'type' => $s->plan?->type,
                    'ends_at' => $s->ends_at?->toIso8601String(),
                    // A cancelled plan runs out without charging again.
                    'auto_renew' => in_array($s->status, SubscriptionStatus::entitled(), true),
                ])->values()->all()
            : [];

        $credits = $owner ? (int) $user->ai_credits : null;
        $jobs = $owner ? ['total' => $user->jobListings()->count(), 'live' => $user->jobListings()->open()->count()] : null;
        $team = $owner ? $user->teamMembers()->count() : null;
        $applications = $user->role === UserRole::Worker ? $user->applications()->count() : null;
        $invoices = $owner ? Invoice::where('employer_id', $user->id)->count() : 0;

        $warnings = [];

        if ($teamMember) {
            $warnings[] = __('Only your own login is deleted. The company account, its plan and its jobs stay with the owner.');
        }

        foreach ($plans as $plan) {
            $warnings[] = $plan['auto_renew']
                ? __('Your :plan plan stops now and its AutoPay is cancelled. The days left are not refunded.', ['plan' => $plan['name']])
                : __('Your :plan plan stops now. The days left are not refunded.', ['plan' => $plan['name']]);
        }

        if ($credits > 0) {
            $warnings[] = trans_choice('{1} Your 1 AI call credit is lost.|[2,*] Your :count AI call credits are lost.', $credits, ['count' => $credits]);
        }

        if (($jobs['total'] ?? 0) > 0) {
            $warnings[] = trans_choice('{1} Your job post and its applicants are deleted.|[2,*] Your :count job posts and their applicants are deleted.', $jobs['total'], ['count' => $jobs['total']]);
        }

        if ($team > 0) {
            $warnings[] = trans_choice('{1} 1 team member loses access.|[2,*] :count team members lose access.', $team, ['count' => $team]);
        }

        if ($applications > 0) {
            $warnings[] = trans_choice('{1} Your job application is deleted.|[2,*] Your :count job applications are deleted.', $applications, ['count' => $applications]);
        }

        $warnings[] = __('Your profile, messages and documents are deleted, and you are signed out everywhere. This cannot be undone.');

        if ($invoices > 0) {
            $warnings[] = __('Your invoices are kept, because tax law requires it.');
        }

        return [
            'role' => $user->role->value,
            'can_delete' => $blocker === null,
            'blocked_reason' => $blocker,
            'blocked_message' => $blocker !== null ? self::blockerMessage($blocker) : null,
            'team_member' => $teamMember,
            'plans' => $plans,
            'ai_credits' => $credits,
            'jobs' => $jobs,
            'team_members' => $team,
            'applications' => $applications,
            'invoices_kept' => $invoices,
            'warnings' => $warnings,
        ];
    }

    /**
     * Delete the account. When a plan's AutoPay cannot be stopped, nothing is
     * deleted.
     *
     * @throws AccountDeletionRefused
     */
    public function delete(User $user): void
    {
        $this->stopPlans($user);

        // Read before the rows go: after the cascade nothing points at these.
        $files = $this->storedFiles($user);
        $jobs = $user->jobListings()->get();
        $workerProfile = $user->workerProfile;

        DB::transaction(function () use ($user) {
            // The phone and email go with the account; the invoice keeps who it was made out to.
            $buyer = Arr::only(InvoiceDocument::buyerFor($user), ['name', 'address', 'gstin']);
            Invoice::where('employer_id', $user->id)->get()->each->update(['buyer' => $buyer]);

            $user->notifications()->delete();
            $user->tokens()->delete();
            $user->delete();
        });

        // The cascade happens in the database, where Scout never hears of it.
        try {
            $jobs->unsearchable();
            $workerProfile?->unsearchable();
        } catch (Throwable $e) {
            report($e);
        }

        if ($files['private'] !== [] || $files['public'] !== [] || $files['recordings'] !== []) {
            DeleteStoredFiles::dispatch($files['private'], $files['public'], $files['recordings']);
        }
    }

    /**
     * Stop every plan Razorpay could still charge. One that will not stop
     * keeps the account: deleting it would leave a mandate charging someone
     * who has no account left to show for it.
     *
     * @throws AccountDeletionRefused
     */
    private function stopPlans(User $user): void
    {
        $subscriptions = Subscription::where('employer_id', $user->id)
            ->whereNotNull('razorpay_subscription_id')
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, [SubscriptionStatus::Created, ...self::CHARGEABLE]))
            ->get();

        if ($subscriptions->isEmpty() || ! $this->razorpay->configured()) {
            return;
        }

        foreach ($subscriptions as $subscription) {
            $id = $subscription->razorpay_subscription_id;

            try {
                $this->razorpay->cancelSubscription($id, atCycleEnd: false);
            } catch (Throwable $e) {
                // Razorpay will not cancel one that has already stopped.
                $status = rescue(fn () => $this->razorpay->subscriptionStatus($id), report: false);
                $stopped = in_array($status, ['cancelled', 'completed', 'expired'], true);

                // A checkout nobody paid for holds no mandate, so it cannot charge on its own.
                if (! $stopped && $subscription->status !== SubscriptionStatus::Created) {
                    report($e);

                    throw new AccountDeletionRefused('plan_cancel_failed', __("The plan's AutoPay could not be stopped at Razorpay, so nothing was deleted. Please try again in a few minutes."));
                }
            }

            // Should a later plan refuse, the account stays with the days already paid for.
            $subscription->update(['status' => SubscriptionStatus::Cancelled]);
        }
    }

    /**
     * The account's files, by where they are kept.
     *
     * @return array{private: list<string>, public: list<string>, recordings: list<array{id: int, path: string}>}
     */
    private function storedFiles(User $user): array
    {
        $kyc = $user->kyc;
        $worker = $user->workerProfile;
        $employer = $user->employerProfile;

        // The karigar's own screening calls, or every call on the employer's jobs.
        $recordings = ScreeningCall::query()
            ->whereNotNull('recording_path')
            ->where(fn (Builder $q) => $q
                ->where('worker_id', $user->id)
                ->orWhereHas('application.job', fn (Builder $job) => $job->where('employer_id', $user->id)))
            ->get(['id', 'recording_path'])
            ->map(fn (ScreeningCall $call) => ['id' => $call->id, 'path' => $call->recording_path])
            ->values()
            ->all();

        return [
            'private' => array_values(array_filter([
                ...($kyc !== null ? KycSubmission::storedPaths($kyc) : []),
                $worker?->resume_path,
            ])),
            'public' => array_values(array_filter([$worker?->avatar_path, $employer?->logo_path])),
            'recordings' => $recordings,
        ];
    }
}
