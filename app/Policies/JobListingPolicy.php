<?php

namespace App\Policies;

use App\Models\JobListing;
use App\Models\User;

class JobListingPolicy
{
    public function create(User $user): bool
    {
        // Recruiters can work applicants but not post or edit jobs.
        return $user->isEmployer() && $user->canManageJobs();
    }

    /**
     * Any team member (incl. recruiters) may view the job's applicants.
     */
    public function view(User $user, JobListing $job): bool
    {
        return $user->isEmployer() && $user->employerAccount()->id === $job->employer_id;
    }

    public function update(User $user, JobListing $job): bool
    {
        return $this->view($user, $job) && $user->canManageJobs();
    }

    public function delete(User $user, JobListing $job): bool
    {
        return $this->update($user, $job);
    }
}
