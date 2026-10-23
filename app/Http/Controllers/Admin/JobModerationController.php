<?php

namespace App\Http\Controllers\Admin;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\JobListing;
use App\Notifications\JobModerationNotification;
use App\Support\JobModeration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JobModerationController extends Controller
{
    /**
     * All job listings across employers, for moderation.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:draft,active,closed,review,checking'],
        ]);

        $jobs = JobListing::query()
            ->with('employer:id,name')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('title', 'ilike', "%{$term}%"))
            // "review": held by the safety check, waiting for a decision;
            // "checking": the queued check has not decided yet.
            ->when($filters['status'] ?? null, fn ($q, $status) => match ($status) {
                'review' => $q->where('moderation_status', JobModeration::PENDING),
                'checking' => $q->where('moderation_status', JobModeration::CHECKING),
                default => $q->where('status', $status),
            })
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (JobListing $j) => [
                'id' => $j->id,
                'title' => $j->title,
                'employer' => $j->employer?->name,
                'city' => $j->city,
                'state' => $j->state,
                'status' => $j->status->value,
                'created_at' => $j->created_at?->diffForHumans(),
                'description' => str($j->description)->limit(300)->value(),
                'moderation_status' => $j->moderation_status,
                'moderation_reasons' => $j->moderation_reasons ?? [],
                'moderation_note' => $j->moderation_note,
                // ai | phrases (the model was down: basic check only) | null
                'moderation_method' => $j->moderation_method,
                'moderated_by_admin' => $j->moderated_by !== null,
            ]);

        return Inertia::render('admin/Jobs', [
            'jobs' => $jobs,
            'filters' => $filters,
            'reviewCount' => JobListing::where('moderation_status', JobModeration::PENDING)->count(),
            'checkingCount' => JobListing::where('moderation_status', JobModeration::CHECKING)->count(),
        ]);
    }

    /**
     * A job the safety check held is fine: it goes live now, and if it never
     * was, karigars are alerted. Plan limits were checked when the employer
     * posted it.
     */
    public function approve(Request $request, JobListing $job): RedirectResponse
    {
        abort_unless($job->moderation_status === JobModeration::PENDING, 404);

        JobModeration::approve($job, $request->user());

        return back()->with('success', __('Job ":title" approved and live.', ['title' => $job->title]));
    }

    /**
     * A held job really is harmful: it stays off the site, and the employer
     * is told why.
     */
    public function reject(Request $request, JobListing $job): RedirectResponse
    {
        abort_unless($job->moderation_status === JobModeration::PENDING, 404);

        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        $job->update([
            'status' => JobStatus::Draft,
            'moderation_status' => JobModeration::REJECTED,
            'moderation_note' => $note,
            'moderated_at' => now(),
            'moderated_by' => $request->user()->id,
        ]);
        $job->employer?->notify(new JobModerationNotification($job, 'rejected'));

        return back()->with('success', __('Job ":title" rejected.', ['title' => $job->title]));
    }

    /**
     * Take a job down (close) or restore it (active). A draft is the
     * employer's unfinished job: restoring it would publish it for them,
     * past the plan and verification checks, so drafts are left alone.
     */
    public function toggle(JobListing $job): RedirectResponse
    {
        if ($job->status === JobStatus::Draft) {
            return back()->with('error', __('This job is a draft. Only the employer can publish it.'));
        }

        $job->status = $job->status === JobStatus::Active ? JobStatus::Closed : JobStatus::Active;
        $job->save();

        $label = $job->status === JobStatus::Active ? __('restored') : __('taken down');

        return back()->with('success', __('Job ":title" was :label.', ['title' => $job->title, 'label' => $label]));
    }
}
