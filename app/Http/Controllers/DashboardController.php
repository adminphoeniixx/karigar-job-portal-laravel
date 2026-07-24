<?php

namespace App\Http\Controllers;

use App\Enums\ApplicationStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\KycDocument;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder;
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
        // A fresh, role-scoped JobApplication query for each aggregate.
        $scope = match ($user->role) {
            UserRole::Employer => (function () use ($user): callable {
                $jobIds = $user->jobListings()->pluck('id')->all();

                return fn () => JobApplication::whereIn('job_listing_id', $jobIds);
            })(),
            UserRole::Admin => fn () => JobApplication::query(),
            default => fn () => JobApplication::where('worker_id', $user->id),
        };

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
     * Build the ordered bucket keys, human labels, and window start for a range.
     *
     * @return array{0: array<int, string>, 1: array<int, string>, 2: CarbonInterface}
     */
    private function buckets(int $count, string $unit): array
    {
        $keys = [];
        $labels = [];

        for ($i = $count - 1; $i >= 0; $i--) {
            $date = match ($unit) {
                'week' => now()->subWeeks($i)->startOfWeek(CarbonInterface::MONDAY),
                'month' => now()->subMonths($i)->startOfMonth(),
                default => now()->subDays($i)->startOfDay(),
            };

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

        $start = match ($unit) {
            'week' => now()->subWeeks($count - 1)->startOfWeek(CarbonInterface::MONDAY),
            'month' => now()->subMonths($count - 1)->startOfMonth(),
            default => now()->subDays($count - 1)->startOfDay(),
        };

        return [$keys, $labels, $start];
    }

    /**
     * Count rows per truncated time bucket (Postgres date_trunc), keyed by bucket.
     *
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, string $column, string $unit, CarbonInterface $start): array
    {
        $expr = match ($unit) {
            'month' => "to_char($column, 'YYYY-MM')",
            'week' => "to_char(date_trunc('week', $column), 'YYYY-MM-DD')",
            default => "to_char($column, 'YYYY-MM-DD')",
        };

        return $query->where($column, '>=', $start)
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

        return [
            'stats' => [
                ['label' => 'KYC Status', 'value' => $kyc?->status->label() ?? 'Not submitted', 'hint' => 'Verification', 'tone' => 'amber'],
                ['label' => 'Available Jobs', 'value' => (string) JobListing::active()->count(), 'hint' => 'Near you', 'tone' => 'emerald'],
                ['label' => 'Profile', 'value' => $user->workerProfile?->skills ? 'Active' : 'Incomplete', 'hint' => 'Skills', 'tone' => 'violet'],
            ],
            'table' => [
                'title' => 'Latest jobs',
                'columns' => ['Title', 'Location', 'Wage', 'Category'],
                'rows' => JobListing::active()->latest()->limit(8)->get()->map(fn ($j) => [
                    $j->title,
                    collect([$j->city, $j->state])->filter()->join(', ') ?: '—',
                    $j->wage_min ? '₹'.$j->wage_min : '—',
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

        return [
            'stats' => [
                ['label' => 'My Jobs', 'value' => (string) $user->jobListings()->count(), 'hint' => 'Total posted', 'tone' => 'emerald'],
                ['label' => 'Active Jobs', 'value' => (string) $user->jobListings()->where('status', 'active')->count(), 'hint' => 'Live now', 'tone' => 'amber'],
                ['label' => 'Subscription', 'value' => $sub?->plan->name ?? 'None', 'hint' => $sub ? 'Active' : 'Subscribe', 'tone' => 'violet'],
            ],
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
            'stats' => [
                ['label' => 'Pending KYC', 'value' => (string) KycDocument::where('status', 'pending')->count(), 'hint' => 'Needs review', 'tone' => 'amber'],
                ['label' => 'Workers', 'value' => (string) User::where('role', 'worker')->count(), 'hint' => 'Registered', 'tone' => 'emerald'],
                ['label' => 'Employers', 'value' => (string) User::where('role', 'employer')->count(), 'hint' => 'Registered', 'tone' => 'violet'],
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
