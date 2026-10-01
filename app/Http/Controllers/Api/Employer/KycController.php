<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmployerKycRequest;
use App\Http\Resources\Api\KycResource;
use App\Models\User;
use App\Services\KycSubmission;
use App\Support\EmployerVerification;
use App\Support\KycRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business verification for the employer app. The documents asked for follow
 * the business type (see KycRequirements); company details and the GSTIN sit
 * on the employer profile, the numbers and proofs on the shared KycDocument.
 * An employer has to be verified before a job can go live.
 */
class KycController extends Controller
{
    /**
     * Company details, current verification status (masked) and what posting needs.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()->employerAccount()));
    }

    /**
     * Submit / re-submit business details + documents for verification.
     */
    public function store(EmployerKycRequest $request): JsonResponse
    {
        $account = $request->user()->employerAccount();
        KycSubmission::save($request, $account);

        return response()->json([
            'message' => __('Business verification submitted for review.'),
            ...$this->payload($account->fresh()),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $account): array
    {
        $profile = $account->employerProfile;
        $kyc = $account->kyc;

        return [
            'business' => [
                'business_type' => $profile?->business_type,
                'legal_name' => $profile?->legal_name,
                'registered_address' => $profile?->registered_address,
                'company_name' => $profile?->company_name,
            ],
            'gstin' => $profile?->gstin,
            'required_documents' => KycRequirements::documentsFor('employer', $profile?->business_type),
            'kyc' => $kyc ? new KycResource($kyc) : null,
            'verification' => EmployerVerification::summary($account),
        ];
    }
}
