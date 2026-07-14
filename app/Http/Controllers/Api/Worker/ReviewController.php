<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ReviewResource;
use App\Models\JobApplication;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * Reviews received by the authenticated worker, with a rating summary.
     */
    public function received(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $reviews = $user->reviewsReceived()
            ->with('reviewer:id,name', 'job:id,title')
            ->latest()
            ->paginate(15);

        return ReviewResource::collection($reviews)->additional([
            'summary' => [
                'average' => $user->averageRating(),
                'count' => $user->reviewsReceived()->count(),
            ],
        ]);
    }

    /**
     * Worker leaves a review for the employer of an accepted application.
     */
    public function store(Request $request, JobApplication $application): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $application->loadMissing('job');

        abort_unless($application->status === ApplicationStatus::Accepted, 403);
        abort_unless($user->id === $application->worker_id, 403);

        $revieweeId = $application->job->employer_id;

        if (Review::where('reviewer_id', $user->id)
            ->where('reviewee_id', $revieweeId)
            ->where('job_listing_id', $application->job_listing_id)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'rating' => __('You have already reviewed this person for this job.'),
            ]);
        }

        $review = Review::create([
            'reviewer_id' => $user->id,
            'reviewee_id' => $revieweeId,
            'job_listing_id' => $application->job_listing_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json([
            'message' => __('Review submitted.'),
            'review' => new ReviewResource($review),
        ], 201);
    }
}
