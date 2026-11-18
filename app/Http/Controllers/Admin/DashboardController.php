<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\KycDocument;
use App\Models\Subscription;
use App\Models\User;
use App\Support\JobModeration;
use App\Support\LocalTime;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Admin overview: platform-wide counts, revenue, and recent signups.
     */
    public function index(): Response
    {
        // Plans being used right now. A row can still say "active" long after
        // its period ended, so the status alone is not enough: entitled() also
        // looks at ends_at, the same rule that decides what an employer can do.
        $live = Subscription::query()->entitled()->with('plan:id,price,duration_days')->get();

        // What those plans bring in per 30 days, before GST. A 15-day plan
        // counts double and a 90-day plan a third, so the plans compare.
        $mrr = $live->sum(fn (Subscription $sub) => (float) $sub->plan?->price * 30 / max(1, (int) ($sub->plan?->duration_days ?: 30)));

        $weekAgo = now()->subDays(7);
        $thisMonth = LocalTime::now()->startOfMonth();

        return Inertia::render('admin/Dashboard', [
            'stats' => [
                'workers' => User::where('role', UserRole::Worker->value)->count(),
                'employers' => User::where('role', UserRole::Employer->value)->count(),
                'suspended' => User::whereNotNull('suspended_at')->count(),
                'activeJobs' => JobListing::where('status', JobStatus::Active->value)->count(),
                // Active and open to applications: the employer's plan has not run out.
                'hiringJobs' => JobListing::active()->hiring()->count(),
                'totalJobs' => JobListing::count(),
                'applications' => JobApplication::count(),
                'activeSubscriptions' => $live->count(),
                'pendingKyc' => KycDocument::where('status', KycStatus::Pending->value)->count(),
                'jobsInReview' => JobListing::where('moderation_status', JobModeration::PENDING)->count(),
                'mrr' => round($mrr, 2),
                // Money that actually came in: every invoice, renewals and AI
                // credit packs included, GST included.
                'collectedThisMonth' => (float) Invoice::where('issued_at', '>=', $thisMonth->utc())->sum('total_amount'),
                'collectedLastMonth' => (float) Invoice::where('issued_at', '>=', $thisMonth->subMonth()->utc())
                    ->where('issued_at', '<', $thisMonth->utc())->sum('total_amount'),
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
            'recentUsers' => User::latest()->limit(8)->get(['id', 'name', 'email', 'phone', 'role', 'created_at', 'suspended_at'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    // OTP accounts carry a placeholder address; their number is the contact.
                    'contact' => str_ends_with((string) $u->email, '@phone.karigar') ? ($u->phone ?: '—') : $u->email,
                    'role' => $u->role->value,
                    'created_at' => $u->created_at,
                    'suspended_at' => $u->suspended_at,
                ]),
        ]);
    }

    /**
     * New users per day for the last 14 days, zero-filled. Days are Indian
     * days: a signup at 01:00 IST counts for that date.
     *
     * @return array<int, array{date: string, count: int}>
     */
    private function signupTrend(): array
    {
        $today = LocalTime::now()->startOfDay();
        $day = LocalTime::bucket('created_at', 'day');

        $rows = User::query()->toBase()
            ->where('created_at', '>=', $today->subDays(13)->utc())
            ->selectRaw("$day as day, count(*) as total")
            ->groupByRaw($day)
            ->pluck('total', 'day');

        $series = [];
        for ($i = 13; $i >= 0; $i--) {
            $date = $today->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'count' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }
}
