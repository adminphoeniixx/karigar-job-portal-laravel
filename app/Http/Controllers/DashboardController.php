<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\KycStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\ContactUnlocks;
use App\Support\JobFeed;
use App\Support\LocalTime;
use App\Support\Verification;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $payload = match ($user->role) {
            UserRole::Employer => $this->employer($user),
            UserRole::Admin => $this->admin(),
            default => $this->worker($user),
        };

        return Inertia::render('Dashboard', [
            'greeting' => $user->name,
            'role' => $user->role->value,
            'activity' => $this->activity($user),
            'snapshot' => $this->snapshot($user),
            ...$payload,
        ]);
    }

    /**
     * Real application-activity time series for the overview chart, scoped to
     * the user's role and pre-computed for every range so the Daily/Weekly/
     * Monthly toggle switches instantly on the client.
     *
     * Series: Applications (received) · In progress (shortlisted) · Closed (decided).
     *
     * @return array<string, array{labels: array<int, string>, series: array<int, array<int, int>>}>
     */
    private function activity(User $user): array
    {
        $scope = $this->applicationScope($user);

        $ranges = [
            'Daily' => ['count' => 14, 'unit' => 'day'],
            'Weekly' => ['count' => 12, 'unit' => 'week'],
            'Monthly' => ['count' => 12, 'unit' => 'month'],
        ];

        $decided = [ApplicationStatus::Accepted->value, ApplicationStatus::Rejected->value, ApplicationStatus::Withdrawn->value];
        $out = [];

        foreach ($ranges as $name => $cfg) {
            [$keys, $labels, $start] = $this->buckets($cfg['count'], $cfg['unit']);

            $apps = $this->bucketCounts($scope(), 'created_at', $cfg['unit'], $start);
            $progress = $this->bucketCounts($scope()->whereNotNull('shortlisted_at'), 'shortlisted_at', $cfg['unit'], $start);
            $closed = $this->bucketCounts($scope()->whereIn('status', $decided)->whereNotNull('status_changed_at'), 'status_changed_at', $cfg['unit'], $start);

            $out[$name] = [
                'labels' => $labels,
                'series' => [
                    array_map(fn ($k) => $apps[$k] ?? 0, $keys),
                    array_map(fn ($k) => $progress[$k] ?? 0, $keys),
                    array_map(fn ($k) => $closed[$k] ?? 0, $keys),
                ],
            ];
        }

        return $out;
    }

    /**
     * The applications this user's dashboard counts: an employer's are the
     * ones to their jobs, a karigar's are their own, the admin's are all.
     * Returns a factory, so every aggregate starts from a fresh query.
     *
     * @return Closure(): Builder<JobApplication>
     */
    private function applicationScope(User $user): Closure
    {
        if ($user->role === UserRole::Employer) {
            $jobIds = $user->jobListings()->pluck('id')->all();

            return fn () => JobApplication::whereIn('job_listing_id', $jobIds);
        }

        return $user->role === UserRole::Admin
            ? fn () => JobApplication::query()
            : fn () => JobApplication::where('worker_id', $user->id);
    }

    /**
     * Where the same applications stand today, for the donut beside the
     * chart. The parts add up to the total, which the three stat tiles (a
     * count, a status and a plan name) never did.
     *
     * @return array{total: int, segments: array<int, array{label: string, value: int}>}
     */
    private function snapshot(User $user): array
    {
        $counts = $this->applicationScope($user)()
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $segments = array_map(fn (ApplicationStatus $status) => [
            'label' => $status->label(),
            'value' => (int) ($counts[$status->value] ?? 0),
        ], ApplicationStatus::cases());

        return ['total' => array_sum(array_column($segments, 'value')), 'segments' => $segments];
    }

    /**
     * Build the ordered bucket keys, human labels, and window start for a range.
     * Periods are the ones on the Indian calendar, not UTC's.
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: CarbonInterface}
     */
    private function buckets(int $count, string $unit): array
    {
        $keys = [];
        $labels = [];

        $now = LocalTime::now();
        // Step back from the start of the current period: on the 31st,
        // now()->subMonths(1) overflows into this month again.
        $period = fn (int $back) => match ($unit) {
            'week' => $now->startOfWeek(CarbonInterface::MONDAY)->subWeeks($back),
            'month' => $now->startOfMonth()->subMonths($back),
            default => $now->startOfDay()->subDays($back),
        };

        for ($i = $count - 1; $i >= 0; $i--) {
            $date = $period($i);

            $keys[] = match ($unit) {
                'month' => $date->format('Y-m'),
                default => $date->format('Y-m-d'),
            };
            $labels[] = match ($unit) {
                'month' => $date->format('M'),
                'week' => $date->format('j M'),
                default => $date->format('j M'),
            };
        }

        // The window opens at local midnight; the column holds UTC.
        return [$keys, $labels, $period($count - 1)->utc()];
    }

    /**
     * Count rows per local day, week or month, keyed by bucket.
     *
     * @param  Builder<JobApplication>  $query
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, string $column, string $unit, CarbonInterface $start): array
    {
        $expr = LocalTime::bucket($column, $unit);

        return $query->toBase()->where($column, '>=', $start)
            ->selectRaw("$expr as bucket, count(*) as total")
            ->groupByRaw($expr)
            ->pluck('total', 'bucket')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function worker(User $user): array
    {
        $kyc = $user->kyc;
        // The same list the app opens with: own trades, nearest first. Empty
        // while the karigar is not available for work.
        $feed = $user->isUnavailableWorker() ? null : JobFeed::for($user)->query();

        return [
            // The KYC tile drops out entirely while verification is switched off.
            // Each tile opens the page it counts; `state` colours the KYC tile
            // (verified green, pending amber, rejected red).
            'stats' => array_values(array_filter([
                Verification::forWorkers()
                    ? ['label' => 'KYC Status', 'value' => $kyc?->status->label() ?? 'Not submitted', 'hint' => 'Verification', 'tone' => 'amber', 'href' => '/kyc', 'state' => match ($kyc?->status) {
                        KycStatus::Verified => 'good',
                        KycStatus::Pending => 'warn',
                        KycStatus::Rejected => 'bad',
                        null => null,
                    }]
                    : null,
                ['label' => 'Available Jobs', 'value' => (string) ($feed ? (clone $feed)->reorder()->count() : 0), 'hint' => 'Near you', 'tone' => 'emerald', 'href' => '/worker/jobs'],
                ['label' => 'Profile', 'value' => $user->workerProfile?->skills ? 'Active' : 'Incomplete', 'hint' => 'Skills', 'tone' => 'violet', 'href' => '/worker/profile'],
            ])),
            'table' => [
                'title' => 'Jobs for you',
                'columns' => ['Title', 'Location', 'Wage', 'Category'],
                'rows' => ($feed ? $feed->limit(8)->get() : collect())->map(fn ($j) => [
                    $j->title,
                    collect([$j->city, $j->state])->filter()->join(', ') ?: '—',
                    $j->wage_min ? '₹'.number_format((float) $j->wage_min).'/month' : '—',
                    $j->category ?? '—',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function employer(User $user): array
    {
        $sub = $user->activeSubscription();
        // A plan that ran out leaves the jobs "active" but paused: out of search
        // and closed to applications. The tiles must not call them live.
        $lapsed = $sub === null && $user->jobPlanLapsed();

        return [
            'stats' => [
                ['label' => 'My Jobs', 'value' => (string) $user->jobListings()->count(), 'hint' => 'Total posted', 'tone' => 'emerald', 'href' => '/employer/jobs'],
                ['label' => 'Active Jobs', 'value' => (string) $user->jobListings()->where('status', 'active')->count(), 'hint' => $lapsed ? 'Paused' : 'Live now', 'tone' => 'amber', 'href' => '/employer/jobs?status=active'],
                ['label' => 'Subscription', 'value' => $sub?->plan->name ?? ($lapsed ? 'Expired' : 'None'), 'hint' => $sub ? 'Active' : ($lapsed ? 'Renew' : 'Subscribe'), 'tone' => 'violet', 'href' => '/subscription'],
            ],
            // The "Worker Database" card, the same one the employer app shows.
            'database' => ContactUnlocks::for($user)->database(),
            'table' => [
                'title' => 'Your recent jobs',
                'columns' => ['Title', 'Status', 'Location', 'Vacancies'],
                'rows' => $user->jobListings()->latest()->limit(8)->get()->map(fn ($j) => [
                    $j->title,
                    $j->status->label(),
                    collect([$j->city, $j->state])->filter()->join(', ') ?: '—',
                    (string) $j->vacancies,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function admin(): array
    {
        return [
            // KYC Review opens on the pending submissions by default.
            'stats' => [
                ['label' => 'Pending KYC', 'value' => (string) KycDocument::where('status', 'pending')->count(), 'hint' => 'Needs review', 'tone' => 'amber', 'href' => '/admin/kyc'],
                ['label' => 'Karigars', 'value' => (string) User::where('role', 'worker')->count(), 'hint' => 'Registered', 'tone' => 'emerald', 'href' => '/admin/karigars'],
                ['label' => 'Employers', 'value' => (string) User::where('role', 'employer')->count(), 'hint' => 'Registered', 'tone' => 'violet', 'href' => '/admin/employers'],
            ],
            'table' => [
                'title' => 'Pending KYC submissions',
                'columns' => ['User', 'Role', 'Submitted'],
                'rows' => KycDocument::with('user:id,name,role')->where('status', 'pending')->latest()->limit(8)->get()->map(fn ($k) => [
                    $k->user->name ?? '—',
                    $k->user->role->value ?? '—',
                    $k->created_at->diffForHumans(),
                ]),
            ],
        ];
    }
}
