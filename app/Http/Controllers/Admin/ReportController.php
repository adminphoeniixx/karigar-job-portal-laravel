<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
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

        $revenue = Subscription::whereNotNull('invoice_number')
            ->whereBetween('invoiced_at', [$from, $to]);

        $monthly = $this->monthlyTrend($filters, $from, $to);

        return Inertia::render('admin/Reports', [
            'filters' => $filters,
            'tiles' => [
                'workers' => User::where('role', 'worker')->whereBetween('created_at', [$from, $to])->count(),
                'employers' => User::where('role', 'employer')->whereBetween('created_at', [$from, $to])->count(),
                'jobs' => (clone $jobs)->count(),
                'applications' => JobApplication::whereBetween('created_at', [$from, $to])
                    ->whereHas('job', fn ($q) => $this->applyJobFilters($q, $filters))
                    ->count(),
                'revenue' => (clone $revenue)->sum('total_amount'),
                'gst' => (clone $revenue)->sum('gst_amount'),
            ],
            'monthly' => $monthly,
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

        $filename = sprintf('%s-report-%s-to-%s.csv', $type, $from->format('Ymd'), $to->format('Ymd'));

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

        $from = isset($filters['from']) ? Date::parse($filters['from'])->startOfDay() : now()->subMonths(3)->startOfDay();
        $to = isset($filters['to']) ? Date::parse($filters['to'])->endOfDay() : now()->endOfDay();

        $filters['from'] = $from->toDateString();
        $filters['to'] = $to->toDateString();

        return [$filters, $from, $to];
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
     * Month-by-month signups and job posts within the range.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function monthlyTrend(array $filters, CarbonInterface $from, CarbonInterface $to): array
    {
        $months = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor <= $to && count($months) < 12) {
            $start = $cursor->copy();
            $end = $cursor->copy()->endOfMonth();
            $end = $end->gt($to) ? $to : $end;

            $months[] = [
                'label' => $start->format('M y'),
                'jobs' => $this->jobQuery($filters, $start, $end)->count(),
                'workers' => User::where('role', 'worker')->whereBetween('created_at', [$start, $end])->count(),
                'employers' => User::where('role', 'employer')->whereBetween('created_at', [$start, $end])->count(),
            ];

            $cursor = $cursor->copy()->addMonth();
        }

        return $months;
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
                        $j->created_at?->format('Y-m-d'),
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

        User::where('role', $role)
            ->whereBetween('created_at', [$from, $to])
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->whereHas($relation, fn ($p) => $p->where('state', $state)))
            ->with($relation)
            ->orderBy('id')
            ->chunk(500, function ($users) use ($out, $relation) {
                foreach ($users as $u) {
                    fputcsv($out, [
                        $u->id, $u->name, $u->phone, $u->email,
                        $u->{$relation}?->city, $u->{$relation}?->state,
                        $u->created_at?->format('Y-m-d'),
                    ]);
                }
            });
    }

    /**
     * @param  resource  $out
     */
    private function exportRevenue($out, CarbonInterface $from, CarbonInterface $to): void
    {
        fputcsv($out, ['Invoice', 'Date', 'Employer', 'Plan', 'Subtotal', 'GST', 'Total']);

        Subscription::whereNotNull('invoice_number')
            ->whereBetween('invoiced_at', [$from, $to])
            ->with('employer:id,name', 'plan:id,name')
            ->orderBy('invoiced_at')
            ->chunk(500, function ($subs) use ($out) {
                foreach ($subs as $s) {
                    fputcsv($out, [
                        $s->invoice_number, $s->invoiced_at?->format('Y-m-d'),
                        $s->employer?->name, $s->plan?->name,
                        $s->subtotal_amount, $s->gst_amount, $s->total_amount,
                    ]);
                }
            });
    }
}
