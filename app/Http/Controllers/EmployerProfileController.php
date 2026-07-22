<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployerProfileUpdateRequest;
use App\Services\BunnyCdn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployerProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->employerProfile()->firstOrCreate([]);
        $email = $request->user()->email;

        return Inertia::render('profile/Employer', [
            'profile' => $profile,
            // Hide the phone-OTP placeholder so the field reads as "not set yet".
            'email' => str_ends_with((string) $email, '@phone.karigar') ? null : $email,
        ]);
    }

    public function update(EmployerProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $profile = $user->employerProfile()->firstOrCreate([]);
        $profile->fill($request->safe()->except('logo', 'email'));

        if ($request->filled('email')) {
            $user->email = $request->validated('email');
            $user->save();
        }

        if ($request->hasFile('logo')) {
            if ($profile->logo_path) {
                BunnyCdn::delete($profile->logo_path);
            }
            $profile->logo_path = BunnyCdn::upload($request->file('logo'), 'logos');
        }

        $profile->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('employer.profile.edit');
    }
}
