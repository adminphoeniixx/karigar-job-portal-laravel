<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Team role management: the account owner invites staff by mobile number
 * and assigns them a role (manager posts & manages jobs, recruiter works
 * applicants only). Members log in with their own mobile OTP.
 */
class TeamController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureOwner($request);

        return Inertia::render('employer/Team', [
            'members' => $request->user()->teamMembers()
                ->with('user:id,name,phone,email')
                ->latest()
                ->get()
                ->map(fn (TeamMember $m) => [
                    'id' => $m->id,
                    'name' => $m->user->name,
                    'phone' => $m->user->phone,
                    'role' => $m->role,
                    'added' => $m->created_at?->diffForHumans(),
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureOwner($request);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^[6-9][0-9]{9}$/'],
            'role' => ['required', Rule::in(TeamMember::ROLES)],
        ], ['phone.regex' => __('Enter a valid 10-digit mobile number.')]);

        $owner = $request->user();

        if ($owner->phone === $data['phone']) {
            return back()->withErrors(['phone' => __('That is your own number.')]);
        }

        $member = User::where('phone', $data['phone'])->first();

        if ($member && ! $member->isEmployer()) {
            return back()->withErrors(['phone' => __('This number belongs to a :role account.', ['role' => $member->role->value])]);
        }

        if ($member && ($member->teamMembership()->exists() || $member->teamMembers()->exists())) {
            return back()->withErrors(['phone' => __('This user already runs their own team or belongs to one.')]);
        }

        if ($member && $member->jobListings()->exists()) {
            return back()->withErrors(['phone' => __('This employer already has their own job posts.')]);
        }

        // New numbers get an account right away; they log in with mobile OTP.
        $member ??= User::create([
            'name' => $data['name'] ?: 'Team member '.substr($data['phone'], -4),
            'email' => $data['phone'].'@phone.karigar',
            'phone' => $data['phone'],
            'password' => Hash::make(Str::random(40)),
            'role' => 'employer',
            'email_verified_at' => now(),
        ]);

        if ($data['name']) {
            $member->update(['name' => $data['name']]);
        }

        $owner->teamMembers()->create([
            'user_id' => $member->id,
            'role' => $data['role'],
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Team member added — they can log in with their mobile number.'),
        ]);
    }

    public function update(Request $request, TeamMember $member): RedirectResponse
    {
        $this->ensureOwner($request);
        abort_unless($member->employer_id === $request->user()->id, 403);

        $data = $request->validate(['role' => ['required', Rule::in(TeamMember::ROLES)]]);

        $member->update(['role' => $data['role']]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Role updated.')]);
    }

    public function destroy(Request $request, TeamMember $member): RedirectResponse
    {
        $this->ensureOwner($request);
        abort_unless($member->employer_id === $request->user()->id, 403);

        $member->delete();

        return back()->with('toast', ['type' => 'success', 'message' => __('Team member removed.')]);
    }

    private function ensureOwner(Request $request): void
    {
        abort_unless($request->user()->teamRole() === 'owner', 403, 'Only the account owner can manage the team.');
    }
}
