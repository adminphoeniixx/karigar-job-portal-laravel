<?php

namespace App\Http\Controllers\Admin;

use App\Enums\JobStatus;
use App\Http\Controllers\Controller;
use App\Models\JobListing;
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
            'status' => ['nullable', 'string', 'in:draft,active,closed'],
        ]);

        $jobs = JobListing::query()
            ->with('employer:id,name')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('title', 'ilike', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
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
            ]);

        return Inertia::render('admin/Jobs', [
            'jobs' => $jobs,
            'filters' => $filters,
        ]);
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
