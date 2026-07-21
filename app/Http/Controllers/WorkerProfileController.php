<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkerProfileUpdateRequest;
use App\Services\BunnyCdn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkerProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        return Inertia::render('profile/Worker', [
            'profile' => $profile,
        ]);
    }

    public function update(WorkerProfileUpdateRequest $request): RedirectResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $profile->fill($request->safe()->except('avatar'));

        if ($request->hasFile('avatar')) {
            if ($profile->avatar_path) {
                BunnyCdn::delete($profile->avatar_path);
            }
            $profile->avatar_path = BunnyCdn::upload($request->file('avatar'), 'avatars');
        }

        $profile->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('worker.profile.edit');
    }
}
