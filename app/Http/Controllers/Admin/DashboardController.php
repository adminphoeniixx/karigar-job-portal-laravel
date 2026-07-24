<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\KycStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\KycDocument;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Admin overview: platform-wide counts, revenue, and recent signups.
     */
    public function index(): Response
    {
        $entitled = array_map(fn ($s) => $s->value, SubscriptionStatus::entitled());

        // Monthly recurring revenue = sum of plan prices for currently-entitled subs.
        $mrr = (float) Subscription::query()
            ->whereIn('subscriptions.status', $entitled)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price');

        $weekAgo = now()->subDays(7);

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'workers' => User::where('role', UserRole::Worker->value)->count(),
                'employers' => User::where('role', UserRole::Employer->value)->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
                'activeJobs' => JobListing::where('status', JobStatus::Active->value)->count(),
                'totalJobs' => JobListing::count(),
                'applications' => JobApplication::count(),
                'activeSubscriptions' => Subscription::whereIn('status', $entitled)->count(),
                'pendingKyc' => KycDocument::where('status', KycStatus::Pending->value)->count(),
                'mrr' => $mrr,
            ],
            // Real "new in the last 7 days" deltas shown as trend chips on the tiles.
            'deltas' => [
                'workers' => User::where('role', UserRole::Worker->value)->where('created_at', '>=', $weekAgo)->count(),
                'employers' => User::where('role', UserRole::Employer->value)->where('created_at', '>=', $weekAgo)->count(),
                'activeJobs' => JobListing::where('status', JobStatus::Active->value)->where('created_at', '>=', $weekAgo)->count(),
                'applications' => JobApplication::where('created_at', '>=', $weekAgo)->count(),
            ],
            // Application funnel breakdown for the composition bar.
            'applicationBreakdown' => [
                'pending' => JobApplication::where('status', ApplicationStatus::Pending->value)->count(),
                'accepted' => JobApplication::where('status', ApplicationStatus::Accepted->value)->count(),
                'rejected' => JobApplication::where('status', ApplicationStatus::Rejected->value)->count(),
                'withdrawn' => JobApplication::where('status', ApplicationStatus::Withdrawn->value)->count(),
            ],
            'signups' => $this->signupTrend(),
            'recentUsers' => User::latest()->limit(8)->get(['id', 'name', 'email', 'role', 'created_at', 'suspended_at']),
        ]);
    }

    /**
     * New users per day for the last 14 days, zero-filled.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function signupTrend(): array
    {
        $rows = User::query()
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get([DB::raw('DATE(created_at) as day'), DB::raw('COUNT(*) as count')])
            ->keyBy('day');

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[] = ['date' => $day, 'count' => (int) ($rows[$day]->count ?? 0)];
        }

        return $series;
    }
}
