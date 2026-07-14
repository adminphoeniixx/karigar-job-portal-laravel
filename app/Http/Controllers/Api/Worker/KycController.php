<?php

namespace App\Http\Controllers\Api\Worker;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\KycSubmitRequest;
use App\Http\Resources\Api\KycResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * KYC is optional for workers — they can use the app fully without it, but
 * verified workers get a badge and more employer responses.
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
        ]);
    }

    /**
     * Submit / re-submit PAN + Aadhaar for verification.
     */
    public function store(KycSubmitRequest $request): JsonResponse
    {
        $user = $request->user();
        $kyc = $user->kyc()->firstOrNew([]);

        $kyc->pan_number = $request->validated('pan_number');
        $kyc->aadhaar_number = $request->validated('aadhaar_number');
        $kyc->aadhaar_hash = hash('sha256', $request->validated('aadhaar_number'));

        if ($request->hasFile('pan_doc')) {
            $this->replaceFile($kyc->pan_doc_path);
            $kyc->pan_doc_path = $request->file('pan_doc')->store('kyc', 'local');
        }

        if ($request->hasFile('aadhaar_doc')) {
            $this->replaceFile($kyc->aadhaar_doc_path);
            $kyc->aadhaar_doc_path = $request->file('aadhaar_doc')->store('kyc', 'local');
        }

        $kyc->status = KycStatus::Pending;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = null;
        $kyc->remarks = null;
        $kyc->save();

        return response()->json([
            'message' => __('KYC submitted for review.'),
            'kyc' => new KycResource($kyc),
        ], 201);
    }

    private function replaceFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
