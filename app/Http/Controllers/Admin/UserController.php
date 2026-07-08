<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * Paginated, searchable user list with role & status filters.
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', 'in:worker,employer,admin'],
            'status' => ['nullable', 'string', 'in:active,suspended'],
        ]);

        $users = User::query()
            ->with('employerProfile:id,user_id,contact_quota_bonus')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(
                fn ($sub) => $sub->where('name', 'ilike', "%{$term}%")->orWhere('email', 'ilike', "%{$term}%")
            ))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($filters['status'] ?? null) === 'suspended', fn ($q) => $q->whereNotNull('suspended_at'))
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->whereNull('suspended_at'))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(function (User $u) {
                $isEmployer = $u->role === UserRole::Employer;

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->role->value,
                    'suspended' => $u->suspended_at !== null,
                    'created_at' => $u->created_at?->diffForHumans(),
                    'is_employer' => $isEmployer,
                    'quota_bonus' => $isEmployer ? (int) ($u->employerProfile?->contact_quota_bonus ?? 0) : null,
                    'quota_total' => $isEmployer ? $u->contactDatabaseQuota() : null,
                ];
            });

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => $filters,
        ]);
    }

    public function suspend(User $user, Request $request): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', __('You cannot suspend your own account.'));
        }

        if ($user->role === UserRole::Admin) {
            return back()->with('error', __('Admin accounts cannot be suspended.'));
        }

        $user->forceFill(['suspended_at' => now()])->save();

        return back()->with('success', __(':name has been suspended.', ['name' => $user->name]));
    }

    public function unsuspend(User $user): RedirectResponse
    {
        $user->forceFill(['suspended_at' => null])->save();

        return back()->with('success', __(':name has been reinstated.', ['name' => $user->name]));
    }

    /**
     * Grant/adjust an employer's bonus worker-database contacts (on top of plan quota).
     */
    public function updateQuota(User $user, Request $request): RedirectResponse
    {
        abort_unless($user->role === UserRole::Employer, 403);

        $data = $request->validate([
            'contact_quota_bonus' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $user->employerProfile()->firstOrCreate([])->update([
            'contact_quota_bonus' => $data['contact_quota_bonus'],
        ]);

        return back()->with('success', __('Contact quota updated for :name.', ['name' => $user->name]));
    }
}
