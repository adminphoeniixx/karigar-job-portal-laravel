<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\KycDocument;
use App\Models\User;
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
            ...$payload,
        ]);
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
