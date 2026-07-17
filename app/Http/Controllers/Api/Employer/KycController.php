<?php

namespace App\Http\Controllers\Api\Employer;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\EmployerKycRequest;
use App\Http\Resources\Api\KycResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Business verification for the employer app. GSTIN is stored on the employer
 * profile; the business PAN + proof docs reuse the shared KycDocument record
 * (its aadhaar_doc_path slot holds the GST certificate for businesses).
 */
class KycController extends Controller
{
    /**
     * Current verification status (masked), plus the saved GSTIN.
     */
    public function show(Request $request): JsonResponse
    {
        $account = $request->user()->employerAccount();
        $kyc = $account->kyc;

        return response()->json([
            'gstin' => $account->employerProfile?->gstin,
            'kyc' => $kyc ? new KycResource($kyc) : null,
        ]);
    }

    /**
     * Submit / re-submit GSTIN + business PAN for verification.
     */
    public function store(EmployerKycRequest $request): JsonResponse
    {
        $account = $request->user()->employerAccount();

        // GSTIN lives on the public employer profile.
        $account->employerProfile()->firstOrCreate([])->update([
            'gstin' => $request->validated('gstin'),
        ]);

        $kyc = $account->kyc()->firstOrNew([]);
        $kyc->pan_number = $request->validated('pan_number');

        if ($request->hasFile('pan_doc')) {
            $this->replaceFile($kyc->pan_doc_path);
            $kyc->pan_doc_path = $request->file('pan_doc')->store('kyc', 'local');
        }

        if ($request->hasFile('gst_doc')) {
            $this->replaceFile($kyc->aadhaar_doc_path);
            $kyc->aadhaar_doc_path = $request->file('gst_doc')->store('kyc', 'local');
        }

        $kyc->status = KycStatus::Pending;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = null;
        $kyc->remarks = null;
        $kyc->save();

        return response()->json([
            'message' => __('Business verification submitted for review.'),
            'gstin' => $request->validated('gstin'),
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
