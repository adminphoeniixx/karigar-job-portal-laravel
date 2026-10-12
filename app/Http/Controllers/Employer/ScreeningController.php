<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Jobs\PlaceScreeningCall;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Services\Screening\ScreeningService;
use App\Support\ReferenceData;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Automated screening calls on the web — the same two actions the app has
 * ({@see \App\Http\Controllers\Api\Employer\ScreeningController}): ring an
 * applicant, and confirm the interview time they offered.
 *
 * The agent only ever collects a preference. Nothing lands on the applicant's
 * record until the employer confirms here, because the agent cannot see what
 * the employer's week looks like.
 */
class ScreeningController extends Controller
{
    public function __construct(private readonly ScreeningService $screening) {}

    /**
     * Queue a screening call to this applicant. Held until the permitted
     * daytime calling window if asked for outside it.
     */
    public function store(Request $request, JobApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->job);

        // From the popup: "Call now", or a date and time (Indian time).
        $data = $request->validate([
            'at' => ['nullable', 'date', 'after:now', 'before:+15 days'],
        ]);

        $blocker = $this->screening->blocker($application);

        if ($blocker !== null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => ScreeningService::blockerLabel($blocker),
            ]);
        }

        if (! empty($data['at'])) {
            $call = $this->screening->schedule($application, CarbonImmutable::parse($data['at'], config('app.display_timezone')), 'manual', $request->user());

            return back()->with('toast', [
                'type' => 'success',
                'message' => __('AI call booked for :time.', ['time' => $call->scheduled_at->timezone(config('app.display_timezone'))->format('d M, g:i A')]),
            ]);
        }

        PlaceScreeningCall::dispatch($application->id);

        $callableAt = $this->screening->nextCallableTime();

        return back()->with('toast', [
            'type' => 'success',
            'message' => $callableAt->isFuture()
                ? __('Call queued for :time.', ['time' => $callableAt->format('d M, g:i A')])
                : __('Calling the worker now.'),
        ]);
    }

    /**
     * Take back a booked call that has not rung yet.
     */
    public function destroy(ScreeningCall $call): RedirectResponse
    {
        $this->authorize('update', $call->application->job);

        return back()->with('toast', $this->screening->cancel($call)
            ? ['type' => 'success', 'message' => __('AI call cancelled.')]
            : ['type' => 'error', 'message' => __('Only a call that has not rung yet can be cancelled.')]);
    }

    /**
     * Accept the slot the worker proposed — optionally moving it — which books
     * the interview and notifies the worker.
     */
    public function confirm(Request $request, ScreeningCall $call): RedirectResponse
    {
        $application = $call->application;
        abort_if($application === null, 404);
        $this->authorize('update', $application->job);

        $data = $request->validate([
            'interview_at' => ['nullable', 'date', 'after:now'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', ReferenceData::INTERVIEW_MODES)],
        ]);

        $confirmed = $this->screening->confirm(
            $call,
            isset($data['interview_at']) ? CarbonImmutable::parse($data['interview_at']) : null,
            $data['mode'] ?? null,
        );

        if ($confirmed === null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => __('This call has no interview time to confirm.'),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Interview confirmed — the worker has been notified.'),
        ]);
    }
}
