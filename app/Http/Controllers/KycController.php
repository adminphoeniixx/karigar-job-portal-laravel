<?php

namespace App\Http\Controllers;

use App\Http\Requests\KycSubmitRequest;
use App\Http\Resources\Api\KycResource;
use App\Services\KycSubmission;
use App\Support\EmployerVerification;
use App\Support\KycRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verification on the web: Aadhaar + PAN for a worker, business details and
 * the documents for its business type for an employer. Any document can be
 * swapped for an alternate ID the admin checks by hand.
 */
class KycController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        $account = $user->isEmployer() ? $user->employerAccount() : $user;
        $profile = $user->isEmployer() ? $account->employerProfile : null;
        $kyc = $account->kyc;

        return Inertia::render('kyc/Submit', [
            'role' => $user->isEmployer() ? 'employer' : 'worker',
            // Masked values only; raw numbers and file paths never leave the server.
            'kyc' => $kyc ? (new KycResource($kyc))->resolve($request) : null,
            'business' => $profile ? [
                'business_type' => $profile->business_type,
                'legal_name' => $profile->legal_name,
                'registered_address' => $profile->registered_address ?? $profile->address,
            ] : null,
            'verification' => $user->isEmployer() ? EmployerVerification::summary($account) : null,
            'reference' => KycRequirements::reference(),
        ]);
    }

    public function store(KycSubmitRequest $request): RedirectResponse
    {
        $user = $request->user();
        KycSubmission::save($request, $user->isEmployer() ? $user->employerAccount() : $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Submitted for review.')]);

        return to_route('kyc.show');
    }
}
