<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Models\JobApplication;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * Leave a review for the other party of an accepted application.
     * Either the employer (reviewing the worker) or the worker
     * (reviewing the employer) may post, once the application is accepted.
     */
    public function store(Request $request, JobApplication $application): RedirectResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        $application->loadMissing('job');

        abort_unless($application->status === ApplicationStatus::Accepted, 403);

        $isEmployer = $user->id === $application->job->employer_id;
        $isWorker = $user->id === $application->worker_id;
        abort_unless($isEmployer || $isWorker, 403);

        $revieweeId = $isEmployer ? $application->worker_id : $application->job->employer_id;

        if (Review::where('reviewer_id', $user->id)
            ->where('reviewee_id', $revieweeId)
            ->where('job_listing_id', $application->job_listing_id)
            ->exists()
        ) {
            throw ValidationException::withMessages([
                'rating' => __('You have already reviewed this person for this job.'),
            ]);
        }

        Review::create([
            'reviewer_id' => $user->id,
            'reviewee_id' => $revieweeId,
            'job_listing_id' => $application->job_listing_id,
            'rating' => $data['rating'],
            'comment' => $data['comment'] ?? null,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Review submitted.')]);
    }

    /**
     * Public rating summary + latest reviews for a user (used on profiles).
     *
     * @return array<string, mixed>
     */
    public static function summaryFor(User $user): array
    {
        return [
            'average' => $user->averageRating(),
            'count' => $user->reviewsReceived()->count(),
            'items' => $user->reviewsReceived()
                ->with('reviewer:id,name')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (Review $r) => [
                    'rating' => $r->rating,
                    'comment' => $r->comment,
                    'reviewer' => $r->reviewer->name,
                    'created_at' => $r->created_at?->diffForHumans(),
                ]),
        ];
    }
}
