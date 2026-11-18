<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\ScreeningCall;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin reports with date/location/category filters and CSV export.
 */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$filters, $from, $to] = $this->filters($request);

        $jobs = $this->jobQuery($filters, $from, $to);

        // Every invoiced payment, renewals and AI credit packs included.
        $revenue = Invoice::whereBetween('issued_at', [$from, $to]);

        return Inertia::render('admin/Reports', [
            'filters' => $filters,
            'tiles' => [
                'workers' => $this->userQuery('worker', $filters, $from, $to)->count(),
                'employers' => $this->userQuery('employer', $filters, $from, $to)->count(),
                'jobs' => (clone $jobs)->count(),
                'applications' => $this->applicationQuery($filters)->whereBetween('created_at', [$from, $to])->count(),
                // Hired in the range, whenever they applied.
                'hires' => $this->hireQuery($filters, $from, $to)->count(),
                'aiCalls' => ScreeningCall::whereBetween('created_at', [$from, $to])
                    ->whereHas('application', fn ($q) => $q->whereHas('job', fn ($j) => $this->applyJobFilters($j, $filters)))
                    ->count(),
                'revenue' => (clone $revenue)->sum('total_amount'),
                'gst' => (clone $revenue)->sum('gst_amount'),
            ],
            'monthly' => $this->monthlyTrend($filters, $from, $to),
            'topCities' => (clone $jobs)
                ->selectRaw("coalesce(city, 'Unknown') as label, count(*) as total")
                ->groupBy('label')->orderByDesc('total')->limit(8)->get(),
            'topCategories' => (clone $jobs)
                ->selectRaw("coalesce(category, 'Other') as label, count(*) as total")
                ->groupBy('label')->orderByDesc('total')->limit(8)->get(),
        ]);
    }

    /**
     * Download the filtered report as CSV (jobs | workers | employers | revenue).
     */
    public function export(Request $request): StreamedResponse
    {
        [$filters, $from, $to] = $this->filters($request);
        $type = $request->validate(['type' => ['required', 'in:jobs,workers,employers,revenue']])['type'];

        $filename = sprintf('%s-report-%s-to-%s.csv', $type, str_replace('-', '', $filters['from']), str_replace('-', '', $filters['to']));

        return response()->streamDownload(function () use ($type, $filters, $from, $to) {
            $out = fopen('php://output', 'w');

            match ($type) {
                'jobs' => $this->exportJobs($out, $filters, $from, $to),
                'workers' => $this->exportUsers($out, 'worker', $filters, $from, $to),
                'employers' => $this->exportUsers($out, 'employer', $filters, $from, $to),
                'revenue' => $this->exportRevenue($out, $from, $to),
            };

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The filters, and the range as the UTC instants the columns hold. The
     * admin picks Indian calendar days: "17 Nov" runs from 00:00 to 23:59 IST.
     *
     * @return array{0: array<string, mixed>, 1: CarbonInterface, 2: CarbonInterface}
     */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'state' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
        ]);

        $zone = LocalTime::zone();
        $from = isset($filters['from']) ? CarbonImmutable::parse($filters['from'], $zone)->startOfDay() : LocalTime::now()->subMonths(3)->startOfDay();
        $to = isset($filters['to']) ? CarbonImmutable::parse($filters['to'], $zone)->endOfDay() : LocalTime::now()->endOfDay();

        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        return [$filters, $from->utc(), $to->utc()];
    }

    /**
     * @param  Builder<JobListing>  $q
     * @param  array<string, mixed>  $filters
     * @return Builder<JobListing>
     */
    private function applyJobFilters(Builder $q, array $filters): Builder
    {
        return $q
            ->when($filters['state'] ?? null, fn ($qq, $state) => $qq->where('state', $state))
            ->when($filters['category'] ?? null, fn ($qq, $cat) => $qq->where('category', $cat));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<JobListing>
     */
    private function jobQuery(array $filters, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $this->applyJobFilters(JobListing::query(), $filters)
            ->whereBetween('created_at', [$from, $to]);
    }

    /**
     * Applications to the jobs the state and category filters leave.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JobApplication>
     */
    private function applicationQuery(array $filters): Builder
    {
        return JobApplication::whereHas('job', fn ($q) => $this->applyJobFilters($q, $filters));
    }

    /**
     * Karigars taken on in the range: the application was accepted then.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<JobApplication>
     */
    private function hireQuery(array $filters, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $this->applicationQuery($filters)
            ->where('status', ApplicationStatus::Accepted->value)
            ->whereBetween('status_changed_at', [$from, $to]);
    }

    /**
     * Accounts of one role that joined in the range. The state filter reads
     * the profile's state, the same as the CSV, so the tile and the download
     * agree. Category does not apply to people.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    private function userQuery(string $role, array $filters, CarbonInterface $from, CarbonInterface $to): Builder
    {
        $relation = $role === 'worker' ? 'workerProfile' : 'employerProfile';

        return User::where('role', $role)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->whereHas($relation, fn ($p) => $p->where('state', $state)));
    }

    /**
     * Month by month within the range, on the Indian calendar. At most the
     * latest 12 months of a longer range.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function monthlyTrend(array $filters, CarbonInterface $from, CarbonInterface $to): array
    {
        $zone = LocalTime::zone();
        $months = [];
        $cursor = CarbonImmutable::instance($to)->timezone($zone)->startOfMonth();
        $first = CarbonImmutable::instance($from)->timezone($zone)->startOfMonth();

        while ($cursor >= $first && count($months) < 12) {
            $start = max($cursor->utc(), CarbonImmutable::instance($from));
            $end = min($cursor->endOfMonth()->utc(), CarbonImmutable::instance($to));

            $months[] = [
                'label' => $cursor->format('M y'),
                'jobs' => $this->jobQuery($filters, $start, $end)->count(),
                'workers' => $this->userQuery('worker', $filters, $start, $end)->count(),
                'employers' => $this->userQuery('employer', $filters, $start, $end)->count(),
                'applications' => $this->applicationQuery($filters)->whereBetween('created_at', [$start, $end])->count(),
                'hires' => $this->hireQuery($filters, $start, $end)->count(),
                'revenue' => (float) Invoice::whereBetween('issued_at', [$start, $end])->sum('total_amount'),
            ];

            $cursor = $cursor->subMonth();
        }

        return array_reverse($months);
    }

    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $filters
     */
    private function exportJobs($out, array $filters, CarbonInterface $from, CarbonInterface $to): void
    {
        fputcsv($out, ['ID', 'Title', 'Category', 'City', 'State', 'Status', 'Vacancies', 'Employer', 'Posted']);

        $this->jobQuery($filters, $from, $to)->with('employer:id,name')->orderBy('id')
            ->chunk(500, function ($jobs) use ($out) {
                foreach ($jobs as $j) {
                    fputcsv($out, [
                        $j->id, $j->title, $j->category, $j->city, $j->state,
                        $j->status->value, $j->vacancies, $j->employer?->name,
                        $j->created_at?->timezone(LocalTime::zone())->format('Y-m-d'),
                    ]);
                }
            });
    }

    /**
     * @param  resource  $out
     * @param  array<string, mixed>  $filters
     */
    private function exportUsers($out, string $role, array $filters, CarbonInterface $from, CarbonInterface $to): void
    {
        fputcsv($out, ['ID', 'Name', 'Phone', 'Email', 'City', 'State', 'Joined']);

        $relation = $role === 'worker' ? 'workerProfile' : 'employerProfile';
        $zone = LocalTime::zone();

        $this->userQuery($role, $filters, $from, $to)
            ->with($relation)
            ->orderBy('id')
            ->chunk(500, function ($users) use ($out, $relation, $zone) {
                foreach ($users as $u) {
                    fputcsv($out, [
                        $u->id, $u->name, $u->phone, $u->email,
                        $u->{$relation}?->city, $u->{$relation}?->state,
                        $u->created_at?->timezone($zone)->format('Y-m-d'),
                    ]);
                }
            });
    }

    /**
     * @param  resource  $out
     */
    private function exportRevenue($out, CarbonInterface $from, CarbonInterface $to): void
    {
        fputcsv($out, ['Invoice', 'Date', 'Employer', 'Buyer GSTIN', 'Plan', 'Payment', 'Place of supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'GST', 'Total']);

        Invoice::whereBetween('issued_at', [$from, $to])
            ->with('employer:id,name', 'employer.employerProfile:id,user_id,company_name,gstin')
            ->orderBy('issued_at')
            ->orderBy('id')
            ->chunk(500, function ($invoices) use ($out) {
                foreach ($invoices as $i) {
                    fputcsv($out, [
                        $i->number, $i->issued_at->timezone(config('app.display_timezone'))->format('Y-m-d'),
                        $i->employer?->employerProfile?->company_name ?: $i->employer?->name,
                        $i->employer?->employerProfile?->gstin,
                        $i->plan_name, $i->cycle > 1 ? 'Renewal' : 'First payment', $i->place_of_supply,
                        $i->subtotal_amount, $i->cgst_amount, $i->sgst_amount, $i->igst_amount, $i->gst_amount, $i->total_amount,
                    ]);
                }
            });
    }
}
