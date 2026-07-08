<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KycController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status', KycStatus::Pending->value);

        $documents = KycDocument::with('user:id,name,email,role')
            ->when(in_array($status, array_column(KycStatus::cases(), 'value'), true),
                fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('admin/Kyc', [
            'documents' => $documents,
            'filterStatus' => $status,
        ]);
    }

    public function approve(KycDocument $kyc, Request $request): RedirectResponse
    {
        $kyc->update([
            'status' => KycStatus::Verified,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'remarks' => $request->string('remarks')->trim()->value() ?: null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('KYC verified.')]);

        return back();
    }

    public function reject(KycDocument $kyc, Request $request): RedirectResponse
    {
        $request->validate(['remarks' => ['required', 'string', 'max:500']]);

        $kyc->update([
            'status' => KycStatus::Rejected,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'remarks' => $request->string('remarks')->trim()->value(),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('KYC rejected.')]);

        return back();
    }

    public function document(KycDocument $kyc, string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['pan', 'aadhaar'], true), 404);

        $path = $type === 'pan' ? $kyc->pan_doc_path : $kyc->aadhaar_doc_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
