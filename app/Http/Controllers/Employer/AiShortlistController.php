<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\JobListing;
use App\Services\DatabaseShortlist;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "AI shortlist from database" on the applicants page ({@see DatabaseShortlist}).
 */
class AiShortlistController extends Controller
{
    public function store(Request $request, JobListing $job, DatabaseShortlist $shortlist): RedirectResponse
    {
        $this->authorize('update', $job);

        $data = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:'.DatabaseShortlist::MAX_PICKS],
            'auto_call' => ['nullable', 'boolean'],
            'call_at' => ['nullable', 'date', 'after:now', 'before:+15 days'],
        ]);

        if (! $job->isOpenForApplications()) {
            return back()->with('toast', ['type' => 'error', 'message' => __('This job is not taking applications, so nobody can be invited to it.')]);
        }

        $picks = $shortlist->pick(
            $job,
            $request->user(),
            (int) ($data['count'] ?? 5),
            (bool) ($data['auto_call'] ?? true),
            isset($data['call_at']) ? CarbonImmutable::parse($data['call_at'], config('app.display_timezone')) : null,
        );

        return back()->with('toast', $picks === []
            ? ['type' => 'error', 'message' => __('No good matches in the database for this job right now.')]
            : ['type' => 'success', 'message' => trans_choice('Invited :count karigar picked by AI. They are shortlisted when they accept.|Invited :count karigars picked by AI. They are shortlisted when they accept.', count($picks), ['count' => count($picks)])]);
    }
}
