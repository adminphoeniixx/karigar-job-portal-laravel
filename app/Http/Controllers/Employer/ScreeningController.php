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
    public function store(JobApplication $application): RedirectResponse
    {
        $this->authorize('update', $application->job);

        $blocker = $this->screening->blocker($application);

        if ($blocker !== null) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => ScreeningService::blockerLabel($blocker),
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
