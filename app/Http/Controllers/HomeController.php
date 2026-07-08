<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        $latestJobs = JobListing::active()
            ->with('employer:id,name')
            ->latest()
            ->limit(6)
            ->get();

        $cities = JobListing::active()
            ->whereNotNull('city')
            ->distinct()
            ->count('city');

        return Inertia::render('Welcome', [
            'stats' => [
                'jobs' => JobListing::active()->count(),
                'workers' => User::where('role', UserRole::Worker->value)->count(),
                'employers' => User::where('role', UserRole::Employer->value)->count(),
                'cities' => $cities,
            ],
            'latestJobs' => $latestJobs,
        ]);
    }
}
