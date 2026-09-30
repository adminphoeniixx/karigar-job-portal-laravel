<?php

namespace App\Http\Middleware;

use App\Models\JobApplication;
use App\Services\ApplicantAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps employer routes on one applicant ({application}) to the applicants
 * the employer may see: released in a batch under an active job plan, or
 * shortlisted and hired ({@see ApplicantAccess}). The lists already leave the
 * others out; this stops an id from being opened directly. Routes without an
 * {application} pass straight through.
 */
class EnsureApplicantVisible
{
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->route('application');

        if ($application !== null && ! $application instanceof JobApplication) {
            $application = JobApplication::find($application);
        }

        if ($application instanceof JobApplication
            && $request->user()?->isEmployer()
            && $application->job?->employer_id === $request->user()->employerAccount()->id
            && ! ApplicantAccess::for($request->user())->isVisible($application)) {
            abort(403, __('This applicant is not visible on your plan yet.'));
        }

        return $next($request);
    }
}
