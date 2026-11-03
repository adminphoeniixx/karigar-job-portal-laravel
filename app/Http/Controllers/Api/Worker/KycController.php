<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KycSubmitRequest;
use App\Http\Resources\Api\KycResource;
use App\Services\Kyc\KycAutoVerifier;
use App\Services\KycSubmission;
use App\Support\KycRequirements;
use App\Support\WorkerVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Karigar KYC: Aadhaar only, no PAN. A verified KYC is needed to apply to jobs
 * (while the admin rule is on) and earns the verified badge. An Aadhaar from
 * DigiLocker verifies the karigar on the spot (finishing DigiLocker alone does
 * it, no submit needed); one typed in, or an alternate ID sent instead, waits
 * for an admin.
 */
class KycController extends Controller
{
    /**
     * Current KYC status (masked values only), or null if never submitted.
     */
    public function show(Request $request): JsonResponse
    {
        $kyc = $request->user()->kyc;

        return response()->json([
            'kyc' => $kyc ? new KycResource($kyc) : null,
            'required_documents' => KycRequirements::documentsFor('worker'),
            'digilocker' => KycAutoVerifier::digilockerSummary($request->user()),
            'auto_verification_enabled' => KycAutoVerifier::enabled(),
            'verification' => WorkerVerification::summary($request->user()),
        ]);
    }

    /**
     * Submit / re-submit Aadhaar (or an alternate ID) for verification.
     */
    public function store(KycSubmitRequest $request): JsonResponse
    {
        $kyc = KycSubmission::save($request, $request->user());

        return response()->json([
            'message' => $kyc->status === KycStatus::Verified
                ? __('Your KYC is verified. You can apply to jobs now.')
                : __('KYC submitted for review.'),
            'kyc' => new KycResource($kyc),
            'verification' => WorkerVerification::summary($request->user()->fresh()),
        ], 201);
    }
}
