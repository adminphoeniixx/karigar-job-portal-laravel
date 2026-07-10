<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Separate admin directories for employers and karigars (workers),
 * with search and location-based filters.
 */
class DirectoryController extends Controller
{
    public function employers(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,suspended'],
        ]);

        $employers = User::where('role', 'employer')
            ->whereDoesntHave('teamMembership') // owners only, not staff accounts
            ->with('employerProfile:id,user_id,company_name,city,state,phone', 'kyc:id,user_id,status')
            ->withCount('jobListings')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($sub) => $sub
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhereHas('employerProfile', fn ($p) => $p->where('company_name', 'ilike', "%{$term}%"))))
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->whereHas('employerProfile', fn ($p) => $p->where('state', $state)))
            ->when($filters['city'] ?? null, fn ($q, $city) => $q->whereHas('employerProfile', fn ($p) => $p->where('city', $city)))
            ->when(($filters['status'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'company' => $u->employerProfile?->company_name,
                'phone' => $u->phone ?? $u->employerProfile?->phone,
                'email' => $u->email,
                'city' => $u->employerProfile?->city,
                'state' => $u->employerProfile?->state,
                'jobs' => $u->job_listings_count,
                'plan' => $u->activeSubscription()?->plan->name,
                'kyc' => $u->kyc?->status->value,
                'suspended' => $u->suspended_at !== null,
                'joined' => $u->created_at?->format('d M Y'),
            ]);

        return Inertia::render('admin/Employers', [
            'employers' => $employers,
            'filters' => $filters,
        ]);
    }

    public function karigars(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:60'],
            'kyc' => ['nullable', 'string', 'in:verified,pending,none'],
            'available' => ['nullable', 'boolean'],
        ]);

        $workers = User::where('role', 'worker')
            ->with('workerProfile:id,user_id,skills,city,state,phone,available,experience_years', 'kyc:id,user_id,status')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($sub) => $sub
                ->where('name', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")))
            ->when($filters['state'] ?? null, fn ($q, $state) => $q->whereHas('workerProfile', fn ($p) => $p->where('state', $state)))
            ->when($filters['city'] ?? null, fn ($q, $city) => $q->whereHas('workerProfile', fn ($p) => $p->where('city', $city)))
            ->when($filters['skill'] ?? null, fn ($q, $skill) => $q->whereHas('workerProfile', fn ($p) => $p->whereJsonContains('skills', $skill)))
            ->when(($filters['kyc'] ?? null) === 'verified', fn ($q) => $q->whereHas('kyc', fn ($k) => $k->where('status', 'verified')))
            ->when(($filters['kyc'] ?? null) === 'pending', fn ($q) => $q->whereHas('kyc', fn ($k) => $k->where('status', 'pending')))
            ->when(($filters['kyc'] ?? null) === 'none', fn ($q) => $q->whereDoesntHave('kyc'))
            ->when(isset($filters['available']), fn ($q) => $q->whereHas('workerProfile', fn ($p) => $p->where('available', $filters['available'])))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'phone' => $u->phone ?? $u->workerProfile?->phone,
                'email' => $u->email,
                'skills' => $u->workerProfile?->skills ?? [],
                'city' => $u->workerProfile?->city,
                'state' => $u->workerProfile?->state,
                'experience' => $u->workerProfile?->experience_years,
                'available' => (bool) ($u->workerProfile?->available ?? false),
                'kyc' => $u->kyc?->status->value,
                'suspended' => $u->suspended_at !== null,
                'joined' => $u->created_at?->format('d M Y'),
            ]);

        return Inertia::render('admin/Karigars', [
            'workers' => $workers,
            'filters' => $filters,
        ]);
    }
}
