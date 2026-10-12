<?php

namespace App\Http\Controllers;

use App\Enums\KycStatus;
use App\Http\Requests\KycSubmitRequest;
use App\Http\Resources\Api\KycResource;
use App\Services\Kyc\KycAutoVerifier;
use App\Services\KycSubmission;
use App\Support\EmployerVerification;
use App\Support\KycRequirements;
use App\Support\WorkerVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Verification on the web: Aadhaar + PAN for a worker, business details and
 * the documents for its business type for an employer. PAN and GSTIN are
 * checked automatically and Aadhaar can come from DigiLocker; any document
 * can be swapped for an alternate ID the admin checks by hand.
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
            'applyVerification' => $user->isWorker() ? WorkerVerification::summary($account) : null,
            'digilocker' => KycAutoVerifier::digilockerSummary($account),
            'autoVerify' => KycAutoVerifier::enabled(),
            'reference' => KycRequirements::reference(),
        ]);
    }

    public function store(KycSubmitRequest $request): RedirectResponse
    {
        $user = $request->user();
        $kyc = KycSubmission::save($request, $user->isEmployer() ? $user->employerAccount() : $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $kyc->status === KycStatus::Verified
            ? __('You are verified.')
            : __('Submitted for review.')]);

        return to_route('kyc.show');
    }
}
