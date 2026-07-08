<?php

namespace App\Http\Controllers;

use App\Enums\KycStatus;
use App\Http\Requests\KycSubmitRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class KycController extends Controller
{
    public function show(Request $request): Response
    {
        $kyc = $request->user()->kyc;

        return Inertia::render('kyc/Submit', [
            'kyc' => $kyc, // masked accessors only; raw numbers/paths are hidden
        ]);
    }

    public function store(KycSubmitRequest $request): RedirectResponse
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

        // A fresh/re-submission always returns to pending review.
        $kyc->status = KycStatus::Pending;
        $kyc->reviewed_by = null;
        $kyc->reviewed_at = null;
        $kyc->remarks = null;
        $kyc->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('KYC submitted for review.')]);

        return to_route('kyc.show');
    }

    private function replaceFile(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }
}
