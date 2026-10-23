<?php

namespace App\Support;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\CheckJobSafety;
use App\Models\JobListing;
use App\Models\User;
use App\Notifications\JobHeldForReviewNotification;
use App\Notifications\JobModerationNotification;
use App\Services\JobModerator;
use App\Services\JobPostingGate;
use Illuminate\Support\Facades\Notification;

/**
 * The safety check where a job goes live, shared by the website and the
 * employer app.
 *
 * A job going live (or a live job whose words change) is saved as a draft
 * marked "checking" and checked on the queue ({@see CheckJobSafety}). A clean
 * job then goes live, karigars are alerted, and the employer is told. One
 * that looks harmful ({@see JobModerator}) stays a draft held for review:
 * nothing of the plan is spent, no karigar is alerted, and an admin approves
 * (it goes live) or rejects it.
 *
 * moderation_status: checking → approved | pending → approved | rejected.
 * Null when the check was switched off, or for jobs from before it.
 */
final class JobModeration
{
    public const CHECKING = 'checking';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    /** Fields whose change on a live job is checked again. */
    private const TEXT = ['title', 'description', 'category', 'skills', 'perks', 'requires_worker_fee', 'worker_fee_amount'];

    /**
     * Whether saving $data puts text a reader has not checked yet in front of
     * karigars: publishing, or changing a live job's words. Never while the
     * admin has the check switched off.
     *
     * @param  array<string, mixed>  $data
     */
    public static function needsCheck(array $data, ?JobListing $existing = null): bool
    {
        if (! JobModerator::enabled()) {
            return false;
        }

        if (($data['status'] ?? $existing?->status?->value) !== JobStatus::Active->value) {
            return false;
        }

        if ($existing === null || $existing->status !== JobStatus::Active) {
            return true;
        }

        foreach (self::TEXT as $field) {
            if (array_key_exists($field, $data) && $data[$field] != $existing->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn the input of a job about to go live into a draft waiting for its
     * check. Save it, then {@see self::queue()} the check.
     *
     * @param  array<string, mixed>  $data  the validated input, changed in place
     */
    public static function begin(array &$data): void
    {
        $data['status'] = JobStatus::Draft->value;
        $data['moderation_status'] = self::CHECKING;
        $data['moderation_reasons'] = null;
        $data['moderation_note'] = null;
        $data['moderation_method'] = null;
        $data['moderated_at'] = null;
        $data['moderated_by'] = null;
    }

    public static function queue(JobListing $job): void
    {
        CheckJobSafety::dispatch($job->id);
    }

    public static function isChecking(JobListing $job): bool
    {
        return $job->moderation_status === self::CHECKING;
    }

    /**
     * The job's text as the moderator reads it.
     *
     * @return array<string, mixed>
     */
    public static function fields(JobListing $job): array
    {
        return $job->only([...self::TEXT, 'wage_min', 'wage_max', 'wage_type']);
    }

    /**
     * The check found nothing: the job goes (back) live.
     */
    public static function pass(JobListing $job, string $method): void
    {
        $firstTime = self::goLive($job, ['moderation_method' => $method, 'moderated_by' => null]);

        $job->employer?->notify(new JobModerationNotification($job, $firstTime ? 'passed' : 'updated'));
    }

    /**
     * An admin cleared a held job: it goes live.
     */
    public static function approve(JobListing $job, User $admin): void
    {
        self::goLive($job, ['moderated_by' => $admin->id]);

        $job->employer?->notify(new JobModerationNotification($job, 'approved'));
    }

    /**
     * Hold a job for an admin, and tell the employer and the admins.
     *
     * @param  list<string>  $reasons
     * @param  string|null  $method  how the check was made; null when it could not run
     */
    public static function hold(JobListing $job, array $reasons, ?string $method): void
    {
        $job->update([
            'status' => JobStatus::Draft,
            'moderation_status' => self::PENDING,
            'moderation_reasons' => $reasons,
            'moderation_note' => null,
            'moderation_method' => $method,
            'moderated_at' => now(),
            'moderated_by' => null,
        ]);

        $job->employer?->notify(new JobModerationNotification($job, 'held'));

        $admins = User::where('role', UserRole::Admin->value)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new JobHeldForReviewNotification($job));
        }
    }

    /**
     * Put the job live. The first time it goes live is when the plan's free
     * post is spent and karigars are alerted; a live job coming back from a
     * re-check after an edit is neither.
     *
     * @param  array<string, mixed>  $attributes
     * @return bool whether this was the first time
     */
    private static function goLive(JobListing $job, array $attributes): bool
    {
        $firstTime = $job->published_at === null;

        $job->update([
            'status' => JobStatus::Active,
            'moderation_status' => self::APPROVED,
            'moderation_reasons' => null,
            'moderated_at' => now(),
            ...$attributes,
        ]);

        if ($firstTime) {
            $account = $job->employer;

            // Posted on the free first post (no plan): spent now that it is live.
            if ($account !== null && $account->activeSubscription() === null && $account->employerProfile?->free_post_used_at === null) {
                JobPostingGate::consumeFreePost($account);
            }

            JobAnnouncer::announce($job);
        }

        return $firstTime;
    }

    /**
     * What the employer's screens show about the check, or null when the
     * job was never checked.
     *
     * @return array{status: string, reasons: list<string>, note: string|null, method: string|null, by_admin: bool, checked_at: string|null}|null
     */
    public static function summary(JobListing $job): ?array
    {
        if ($job->moderation_status === null) {
            return null;
        }

        return [
            'status' => $job->moderation_status,
            'reasons' => $job->moderation_status === self::APPROVED ? [] : ($job->moderation_reasons ?? []),
            'note' => $job->moderation_note,
            'method' => $job->moderation_method,
            'by_admin' => $job->moderated_by !== null,
            'checked_at' => $job->moderated_at?->toIso8601String(),
        ];
    }

    /**
     * What a save that queued a check answers with, read off the job as it
     * now stands: on a real queue it is still checking, on the sync queue the
     * check has already decided. Null message: it went live, say so as usual.
     *
     * @return array{message: string|null, code: string|null}
     */
    public static function outcome(JobListing $job): array
    {
        return match ($job->moderation_status) {
            self::CHECKING => ['message' => self::checkingMessage(), 'code' => 'job_checking'],
            self::PENDING => ['message' => self::heldMessage(), 'code' => 'job_under_review'],
            default => ['message' => null, 'code' => null],
        };
    }

    public static function checkingMessage(): string
    {
        return __('We are running a quick safety check on your job. It goes live in a minute or two, and we will let you know.');
    }

    public static function heldMessage(): string
    {
        return __('Your job needs a quick check by our team before it goes live, usually within a working day. Nothing from your plan has been used.');
    }

    public static function busyMessage(): string
    {
        return __('This job is being checked right now. Please try again in a minute.');
    }
}
