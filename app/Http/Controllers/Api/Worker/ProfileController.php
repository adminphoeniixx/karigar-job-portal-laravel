<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\WorkerProfileRequest;
use App\Http\Resources\Api\WorkerProfileResource;
use App\Services\BunnyCdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * The authenticated worker's profile.
     */
    public function show(Request $request): WorkerProfileResource
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $profile->setRelation('user', $request->user());

        return new WorkerProfileResource($profile);
    }

    /**
     * Create/update the profile — also used to complete registration.
     */
    public function update(WorkerProfileRequest $request): WorkerProfileResource
    {
        $user = $request->user();
        $profile = $user->workerProfile()->firstOrCreate([]);

        // The display name and email live on the user record, not the profile.
        if ($request->filled('name')) {
            $user->update(['name' => $request->validated('name')]);
        }

        if ($request->filled('email')) {
            $user->update(['email' => $request->validated('email')]);
        }

        $profile->fill($request->safe()->except('name', 'email'));
        $profile->save();
        $profile->setRelation('user', $user->fresh());

        return new WorkerProfileResource($profile);
    }

    /**
     * Upload/replace the profile photo (multipart).
     */
    public function avatar(Request $request): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        if ($profile->avatar_path) {
            BunnyCdn::delete($profile->avatar_path);
        }

        $profile->avatar_path = BunnyCdn::upload($request->file('avatar'), 'avatars');
        $profile->save();

        return response()->json(['avatar_url' => $profile->avatar_url]);
    }

    /**
     * Quick availability toggle for the home screen.
     */
    public function availability(Request $request): JsonResponse
    {
        $data = $request->validate(['available' => ['required', 'boolean']]);

        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $profile->update(['available' => $data['available']]);

        return response()->json(['available' => (bool) $profile->available]);
    }
}
