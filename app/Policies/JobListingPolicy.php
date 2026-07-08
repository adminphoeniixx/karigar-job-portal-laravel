<?php

namespace App\Policies;

use App\Models\JobListing;
use App\Models\User;

class JobListingPolicy
{
    public function create(User $user): bool
    {
        return $user->isEmployer();
    }

    public function update(User $user, JobListing $job): bool
    {
        return $user->isEmployer() && $user->id === $job->employer_id;
    }

    public function delete(User $user, JobListing $job): bool
    {
        return $this->update($user, $job);
    }
}
