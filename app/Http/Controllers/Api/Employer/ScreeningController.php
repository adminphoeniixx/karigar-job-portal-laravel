<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApplicantResource;
use App\Http\Resources\Api\ScreeningCallResource;
use App\Jobs\PlaceScreeningCall;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Services\Screening\ScreeningService;
use App\Support\ReferenceData;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The employer's side of automated screening calls — "Call & schedule" on an
 * applicant, and confirming the interview time the worker offered.
 *
 * The agent only ever collects a preference. Nothing lands on the applicant's
 * record until the employer confirms here, because the agent has no idea what
 * the employer's week looks like.
 */
class ScreeningController extends Controller
{
    public function __construct(private readonly ScreeningService $screening) {}

    /**
     * Screening calls placed for one applicant, newest first.
     */
    public function index(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('view', $application->job);

        return response()->json([
            'calls' => ScreeningCallResource::collection(
                $application->screeningCalls()->latest('id')->get()
            ),
            'can_call' => $this->screening->blocker($application) === null,
            'blocked_because' => $this->screening->blocker($application),
        ]);
    }

    /**
     * Queue a screening call to this applicant. Held until the permitted
     * daytime calling window if placed outside it.
     */
    public function store(Request $request, JobApplication $application): JsonResponse
    {
        $this->authorize('update', $application->job);

        $blocker = $this->screening->blocker($application);

        if ($blocker !== null) {
            return response()->json([
                'message' => __('This applicant cannot be called right now.'),
                'code' => $blocker,
            ], 422);
        }

        PlaceScreeningCall::dispatch($application->id);

        $callableAt = $this->screening->nextCallableTime();

        return response()->json([
            'message' => $callableAt->isFuture()
                ? __('Call queued for :time.', ['time' => $callableAt->format('d M, g:i A')])
                : __('Calling the worker now.'),
            'calling_at' => $callableAt->toIso8601String(),
        ], 202);
    }

    /**
     * Accept the slot the worker proposed — optionally moving it — which books
     * the interview and notifies the worker.
     */
    public function confirm(Request $request, ScreeningCall $call): JsonResponse
    {
        $application = $call->application;
        abort_if($application === null, 404);
        $this->authorize('update', $application->job);

        $data = $request->validate([
            'interview_at' => ['nullable', 'date', 'after:now'],
            'mode' => ['nullable', 'string', 'in:'.implode(',', ReferenceData::INTERVIEW_MODES)],
        ]);

        $application = $this->screening->confirm(
            $call,
            isset($data['interview_at']) ? CarbonImmutable::parse($data['interview_at']) : null,
            $data['mode'] ?? null,
        );

        if ($application === null) {
            return response()->json([
                'message' => __('This call has no interview time to confirm.'),
                'code' => 'no_proposed_slot',
            ], 422);
        }

        $application->loadMissing('worker.workerProfile', 'worker.kyc', 'job:id,title');

        return response()->json([
            'message' => __('Interview confirmed — the worker has been notified.'),
            'applicant' => new ApplicantResource($application),
            'call' => new ScreeningCallResource($call->refresh()),
        ]);
    }
}
