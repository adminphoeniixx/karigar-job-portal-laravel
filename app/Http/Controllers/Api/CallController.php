<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\CallSessionResource;
use App\Models\CallSession;
use App\Services\Calling\CallDenied;
use App\Services\Calling\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * In-app voice calls between employers and workers — the "Call" button in both
 * apps. Numbers are never exchanged: each side gets a short-lived token for a
 * single-use audio channel, and the callee's phone is rung with a data push
 * that the app turns into a CallKit / ConnectionService incoming-call screen.
 *
 * The rules live in App\Services\Calling\CallService; see docs/calling.md for
 * the Flutter side of the contract.
 */
class CallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    /**
     * Ring someone. Employers pass `worker_id`, workers pass `employer_id`.
     * Returns the call plus the caller's own join credentials.
     */
    public function initiate(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'worker_id' => ['required_without:employer_id', 'integer', 'exists:users,id'],
            'employer_id' => ['required_without:worker_id', 'integer', 'exists:users,id'],
            'job_application_id' => ['nullable', 'integer', 'exists:job_applications,id'],
        ]);

        // Each side names the *other* participant.
        $counterpart = $user->isWorker() ? ($data['employer_id'] ?? null) : ($data['worker_id'] ?? null);

        if ($counterpart === null) {
            return response()->json([
                'message' => $user->isWorker() ? __('employer_id is required.') : __('worker_id is required.'),
            ], 422);
        }

        try {
            $call = $this->calls->dial($user, (int) $counterpart, $data['job_application_id'] ?? null);
        } catch (CallDenied $e) {
            return $this->denied($e);
        }

        $call->load('caller', 'callee');

        return response()->json([
            'call' => new CallSessionResource($call),
            'credentials' => $this->calls->credentialsFor($call, $user)->toArray(),
        ], 201);
    }

    /**
     * Pick up a ringing call. Only the person who was rung may answer, and the
     * join credentials are handed over here rather than in the push.
     */
    public function answer(Request $request, CallSession $call): JsonResponse
    {
        $user = $request->user();
        abort_unless($call->callee_id === $user->id, 403);

        try {
            $call = $this->calls->answer($call, $user);
        } catch (CallDenied $e) {
            return $this->denied($e);
        }

        $call->load('caller', 'callee');

        return response()->json([
            'call' => new CallSessionResource($call),
            'credentials' => $this->calls->credentialsFor($call, $user)->toArray(),
        ]);
    }

    /**
     * Decline a ringing call.
     */
    public function reject(Request $request, CallSession $call): JsonResponse
    {
        $user = $request->user();
        abort_unless($call->callee_id === $user->id, 403);

        $call = $this->calls->reject($call, $user);
        $call->load('caller', 'callee');

        return response()->json(['call' => new CallSessionResource($call)]);
    }

    /**
     * Hang up. Either side may end a call; the caller doing this while it is
     * still ringing cancels it, which the other side logs as a missed call.
     */
    public function end(Request $request, CallSession $call): JsonResponse
    {
        $user = $request->user();
        abort_unless($call->isParticipant($user), 403);

        $call = $this->calls->hangUp($call, $user);
        $call->load('caller', 'callee');

        return response()->json(['call' => new CallSessionResource($call)]);
    }

    /**
     * Re-issue join credentials for a call already in progress — used when the
     * SDK reports the token is about to expire on a long call.
     */
    public function refresh(Request $request, CallSession $call): JsonResponse
    {
        $user = $request->user();
        abort_unless($call->isParticipant($user), 403);

        if (! $call->status->isOpen()) {
            return $this->denied(CallDenied::notOpen());
        }

        return response()->json([
            'credentials' => $this->calls->credentialsFor($call, $user)->toArray(),
        ]);
    }

    /**
     * This user's call log, newest first — the Recents screen.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $this->calls->sweepStale();

        $calls = CallSession::forUser($user)
            ->with('caller.workerProfile', 'caller.employerProfile', 'callee.workerProfile', 'callee.employerProfile')
            ->latest('id')
            ->paginate(20);

        return CallSessionResource::collection($calls);
    }

    private function denied(CallDenied $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->reason,
        ], $e->status);
    }
}
