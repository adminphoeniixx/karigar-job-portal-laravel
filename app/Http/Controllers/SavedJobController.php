<?php

namespace App\Http\Controllers;

use App\Models\JobListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SavedJobController extends Controller
{
    public function index(Request $request): Response
    {
        $saved = $request->user()->savedJobs()
            ->with('job:id,title,city,state,category,wage_min,wage_max,wage_type,status')
            ->latest()
            ->paginate(15);

        return Inertia::render('applications/Saved', [
            'saved' => $saved,
        ]);
    }

    /**
     * Toggle a job in the user's saved list.
     */
    public function toggle(Request $request, JobListing $job): RedirectResponse
    {
        $existing = $request->user()->savedJobs()->where('job_listing_id', $job->id)->first();

        if ($existing) {
            $existing->delete();

            return back()->with('toast', ['type' => 'success', 'message' => __('Removed from saved.')]);
        }

        $request->user()->savedJobs()->create(['job_listing_id' => $job->id]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Saved.')]);
    }
}
