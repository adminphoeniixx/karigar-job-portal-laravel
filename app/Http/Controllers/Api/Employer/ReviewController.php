<?php

namespace App\Http\Controllers\Api\Employer;

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
     * Reviews workers left for this employer, with a rating summary.
     */
    public function received(Request $request): AnonymousResourceCollection
    {
        $account = $request->user()->employerAccount();

        $reviews = $account->reviewsReceived()
            ->with('reviewer:id,name', 'job:id,title')
            ->latest()
            ->paginate(15);

        return ReviewResource::collection($reviews)->additional([
            'summary' => [
                'average' => $account->averageRating(),
                'count' => $account->reviewsReceived()->count(),
            ],
        ]);
    }

    /**
     * Employer rates the worker of an accepted (hired) application. Mirrors the
     * web ReviewController — either party may review once the app is accepted.
     */
    public function store(Request $request, JobApplication $application): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $application->loadMissing('job');

        abort_unless($application->status === ApplicationStatus::Accepted, 403);
        // The reviewer must own the job the application belongs to.
        abort_unless($request->user()->employerAccount()->id === $application->job->employer_id, 403);

        $revieweeId = $application->worker_id;

        if (Review::where('reviewer_id', $request->user()->id)
            ->where('reviewee_id', $revieweeId)
            ->where('job_listing_id', $application->job_listing_id)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'rating' => __('You have already reviewed this person for this job.'),
            ]);
        }

        $review = Review::create([
            'reviewer_id' => $request->user()->id,
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
