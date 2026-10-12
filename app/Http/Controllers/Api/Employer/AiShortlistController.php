<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\JobListing;
use App\Services\DatabaseShortlist;
use App\Support\KarigarLocation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "AI shortlist from database": the AI picks the karigars who fit a job and
 * invites them; those who apply are shortlisted and, if asked, get an AI
 * screening call booked ({@see DatabaseShortlist}).
 */
class AiShortlistController extends Controller
{
    public function store(Request $request, JobListing $job, DatabaseShortlist $shortlist): JsonResponse
    {
        $this->authorize('update', $job);

        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:'.DatabaseShortlist::MAX_PICKS],
            'auto_call' => ['nullable', 'boolean'],
            // When to ring the ones who accept; Indian time when no offset is given.
            'call_at' => ['nullable', 'date', 'after:now', 'before:+15 days'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        if (! $job->isOpenForApplications()) {
            return response()->json(['message' => __('This job is not taking applications, so nobody can be invited to it.'), 'code' => 'job_not_hiring'], 422);
        }

        $picks = $shortlist->pick(
            $job,
            $request->user(),
            (int) ($data['count'] ?? 5),
            (bool) ($data['auto_call'] ?? true),
            isset($data['call_at']) ? CarbonImmutable::parse($data['call_at'], config('app.display_timezone')) : null,
            $data['message'] ?? null,
        );

        return response()->json([
            'message' => $picks === []
                ? __('No good matches in the database for this job right now.')
                : trans_choice('Invited :count karigar picked by AI.|Invited :count karigars picked by AI.', count($picks), ['count' => count($picks)]),
            'picked' => collect($picks)->map(fn (array $pick) => [
                'worker_id' => $pick['worker']->id,
                'name' => $pick['worker']->name,
                'location_label' => KarigarLocation::label($pick['worker']->workerProfile),
                'score' => $pick['score'],
                'summary' => $pick['summary'],
            ])->all(),
        ], $picks === [] ? 200 : 201);
    }
}
