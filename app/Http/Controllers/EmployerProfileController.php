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

        return Inertia::render('profile/Employer', [
            'profile' => $profile,
        ]);
    }

    public function update(EmployerProfileUpdateRequest $request): RedirectResponse
    {
        $profile = $request->user()->employerProfile()->firstOrCreate([]);
        $profile->fill($request->safe()->except('logo'));

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
