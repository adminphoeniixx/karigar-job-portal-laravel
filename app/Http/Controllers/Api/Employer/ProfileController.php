<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployerProfileUpdateRequest;
use App\Http\Resources\Api\EmployerProfileResource;
use App\Services\BunnyCdn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * The authenticated employer's business profile.
     */
    public function show(Request $request): EmployerProfileResource
    {
        $account = $request->user()->employerAccount();
        $profile = $account->employerProfile()->firstOrCreate([]);
        $profile->setRelation('user', $account);

        return new EmployerProfileResource($profile);
    }

    /**
     * Create/update the business profile — also completes registration.
     */
    public function update(EmployerProfileUpdateRequest $request): EmployerProfileResource
    {
        $account = $request->user()->employerAccount();
        $profile = $account->employerProfile()->firstOrCreate([]);

        // The contact-person name lives on the user record, not the profile.
        if ($request->filled('name')) {
            $account->update(['name' => $request->validated('name')]);
        }

        $profile->fill($request->safe()->except('name', 'logo'));

        if ($request->hasFile('logo')) {
            if ($profile->logo_path) {
                BunnyCdn::delete($profile->logo_path);
            }
            $profile->logo_path = BunnyCdn::upload($request->file('logo'), 'logos');
        }

        $profile->save();
        $profile->setRelation('user', $account->fresh());

        return new EmployerProfileResource($profile);
    }

    /**
     * Upload/replace the company logo (multipart).
     */
    public function logo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'max:2048']]);

        $account = $request->user()->employerAccount();
        $profile = $account->employerProfile()->firstOrCreate([]);

        if ($profile->logo_path) {
            BunnyCdn::delete($profile->logo_path);
        }

        $profile->logo_path = BunnyCdn::upload($request->file('logo'), 'logos');
        $profile->save();

        return response()->json(['logo_url' => $profile->logo_url]);
    }
}
