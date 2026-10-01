<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\KycSubmitRequest;
use App\Http\Resources\Api\KycResource;
use App\Services\KycSubmission;
use App\Support\KycRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * KYC is optional for workers — they can use the app fully without it, but
 * verified workers get a badge and more employer responses. Aadhaar + PAN,
 * either of which can be swapped for an alternate ID the admin checks by hand.
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
        ]);
    }

    /**
     * Submit / re-submit Aadhaar + PAN (or alternates) for verification.
     */
    public function store(KycSubmitRequest $request): JsonResponse
    {
        $kyc = KycSubmission::save($request, $request->user());

        return response()->json([
            'message' => __('KYC submitted for review.'),
            'kyc' => new KycResource($kyc),
        ], 201);
    }
}
