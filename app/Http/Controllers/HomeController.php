<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(): Response
    {
        // The landing page is designed light and carries no dark: variants, so
        // it must not inherit a visitor's dark appearance. Overrides the value
        // HandleAppearance shared, for this response only.
        View::share('appearance', 'light');

        // "Open right now" sits directly under the crafts index, so it has to
        // draw from the same list of crafts. Without this it showed whatever
        // was posted most recently — which on a seeded database means
        // plumbing and electrical work left over from before the catalogue was
        // narrowed to handmade crafts, read as jobs in crafts we do not offer.
        //
        // Filtering here rather than fixing the rows means the section stays
        // honest whatever ends up in the table. If nothing matches, the
        // section already has its own empty state.
        $latestJobs = JobListing::active()
            ->whereIn('category', Category::cachedActiveNames())
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
