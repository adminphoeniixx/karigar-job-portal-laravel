<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationCall;
use App\Services\Screening\VoiceAgent;
use App\Services\Verification\VerificationCalls;
use App\Support\Verification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → AI Verification Calls: who still has KYC to do, a button to have the
 * AI explain it to them, and how each call went.
 */
class VerificationCallController extends Controller
{
    public function index(Request $request, VoiceAgent $agent): Response
    {
        $tab = $request->query('tab') === 'calls' ? 'calls' : 'accounts';
        $role = in_array($request->query('role'), ['worker', 'employer'], true) ? $request->query('role') : null;
        $search = trim((string) $request->query('q'));

        return Inertia::render('admin/VerificationCalls', [
            'tab' => $tab,
            'filterRole' => $role ?? 'all',
            'search' => $search,
            'accounts' => $tab === 'accounts' ? $this->accounts($role, $search) : null,
            'calls' => $tab === 'calls' ? $this->calls($role, $search) : null,
            'auto' => [
                'enabled' => VerificationCalls::autoEnabled(),
                'since' => VerificationCalls::since()?->toIso8601String(),
            ],
            // The stub "works" but rings nobody: say so on the page.
            'dialling' => $agent->configured() && $agent->name() !== 'stub',
            'verificationEnabled' => Verification::enabled(),
        ]);
    }

    public function store(Request $request, User $user, VerificationCalls $calls): RedirectResponse
    {
        $call = $calls->call($user, 'admin', $request->user());

        if ($call === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => VerificationCalls::blockerLabel((string) $calls->blocker($user)),
            ]);
        }

        $message = $call->status === ScreeningCallStatus::Scheduled
            ? __('Outside calling hours: the call is booked for :time.', [
                'time' => $call->scheduled_at?->timezone(config('app.display_timezone'))->format('j M, g:i A'),
            ])
            : __('Calling :name now.', ['name' => $user->name]);

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }

    /**
     * Karigars and employers with KYC still to do: never sent, or turned down.
     * Staff accounts are left out, since only the owner verifies a business.
     */
    private function accounts(?string $role, string $search): mixed
    {
        $roles = match (true) {
            ! Verification::enabled() => [],
            ! Verification::forWorkers() => [UserRole::Employer->value],
            default => [UserRole::Worker->value, UserRole::Employer->value],
        };

        if ($role !== null) {
            $roles = array_values(array_intersect($roles, [$role]));
        }

        return User::query()
            ->with([
                'kyc:id,user_id,status,remarks,reviewed_at',
                'workerProfile:id,user_id,phone,screening_calls_opted_out',
                'verificationCalls' => fn ($q) => $q->latest()->limit(1),
            ])
            ->whereIn('role', $roles === [] ? ['none'] : $roles)
            ->whereDoesntHave('teamMembership')
            ->where(fn (Builder $q) => $q
                ->whereDoesntHave('kyc')
                ->orWhereHas('kyc', fn (Builder $k) => $k->where('status', KycStatus::Rejected->value)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('phone', 'like', "%{$search}%")))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(function (User $user) {
                $last = $user->verificationCalls->first();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->workerProfile?->phone ?: $user->phone,
                    'role' => $user->role->value,
                    'state' => $user->kyc?->status === KycStatus::Rejected ? 'rejected' : 'not_started',
                    'remarks' => $user->kyc?->remarks,
                    'opted_out' => (bool) $user->workerProfile?->screening_calls_opted_out,
                    'signed_up_at' => $user->created_at?->toIso8601String(),
                    'last_call' => $last ? $this->callRow($last, withUser: false) : null,
                ];
            });
    }

    private function calls(?string $role, string $search): mixed
    {
        return VerificationCall::with('user:id,name,phone,role', 'requester:id,name')
            ->when($role, fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('role', $role)))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u
                ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('phone', 'like', "%{$search}%")))
            ->latest()
            ->paginate(25)
            ->withQueryString()
            ->through(fn (VerificationCall $call) => $this->callRow($call));
    }

    /**
     * @return array<string, mixed>
     */
    private function callRow(VerificationCall $call, bool $withUser = true): array
    {
        return [
            'id' => $call->id,
            'status' => $call->status->value,
            'outcome' => $call->outcome,
            'kyc_state' => $call->kyc_state,
            'source' => $call->source,
            'requested_by' => $call->requester?->name,
            'attempt' => $call->attempt,
            'language' => $call->language,
            'summary' => $call->summary,
            'transcript' => $call->transcript,
            'failure_reason' => $call->failure_reason,
            'duration_seconds' => $call->duration_seconds,
            'scheduled_at' => $call->scheduled_at?->toIso8601String(),
            'created_at' => $call->created_at?->toIso8601String(),
            'user' => $withUser && $call->user ? [
                'id' => $call->user->id,
                'name' => $call->user->name,
                'phone' => $call->user->phone,
                'role' => $call->user->role->value,
            ] : null,
        ];
    }
}
