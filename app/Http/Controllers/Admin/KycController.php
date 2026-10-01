<?php

namespace App\Http\Controllers\Admin;

use App\Enums\KycStatus;
use App\Http\Controllers\Controller;
use App\Models\KycDocument;
use App\Notifications\KycReviewedNotification;
use App\Support\KycRequirements;
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
        $role = $request->query('role');
        $missingOnly = $request->boolean('missing');

        $documents = KycDocument::with('user:id,name,email,phone,role', 'user.employerProfile')
            ->when(in_array($status, array_column(KycStatus::cases(), 'value'), true),
                fn ($q) => $q->where('status', $status))
            ->when(in_array($role, ['worker', 'employer'], true),
                fn ($q) => $q->whereHas('user', fn ($u) => $u->where('role', $role)))
            ->when($missingOnly, fn ($q) => $q->where('has_missing_documents', true))
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (KycDocument $kyc) => $this->row($kyc));

        return Inertia::render('admin/Kyc', [
            'documents' => $documents,
            'filterStatus' => $status,
            'filterRole' => in_array($role, ['worker', 'employer'], true) ? $role : 'all',
            'filterMissing' => $missingOnly,
            'missingPending' => KycDocument::where('status', KycStatus::Pending)
                ->where('has_missing_documents', true)->count(),
        ]);
    }

    /**
     * One submission as the review page shows it. Admins see full PAN, GSTIN
     * and alternate-ID numbers to check them by hand; Aadhaar stays masked
     * (its photo is one click away).
     *
     * @return array<string, mixed>
     */
    private function row(KycDocument $kyc): array
    {
        $user = $kyc->user;
        $profile = $user?->employerProfile;

        return [
            'id' => $kyc->id,
            'status' => $kyc->status->value,
            'remarks' => $kyc->remarks,
            'submitted_at' => $kyc->updated_at?->toIso8601String(),
            'reviewed_at' => $kyc->reviewed_at?->toIso8601String(),
            'has_missing_documents' => $kyc->has_missing_documents,
            'user' => [
                'id' => $user?->id,
                'name' => $user?->name,
                'email' => $user?->email,
                'phone' => $user?->phone,
                'role' => $user?->role->value,
            ],
            'business' => $user?->isEmployer() ? [
                'type' => $kyc->business_type,
                'type_label' => KycRequirements::BUSINESS_TYPES[$kyc->business_type] ?? null,
                'company_name' => $profile?->company_name,
                'legal_name' => $profile?->legal_name,
                'registered_address' => $profile?->registered_address,
            ] : null,
            'documents' => $kyc->documentsSummary($profile?->gstin, forAdmin: true),
        ];
    }

    public function approve(KycDocument $kyc, Request $request): RedirectResponse
    {
        $kyc->update([
            'status' => KycStatus::Verified,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'remarks' => $request->string('remarks')->trim()->value() ?: null,
        ]);

        $this->reindexWorker($kyc);
        $kyc->user?->notify(new KycReviewedNotification($kyc));

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

        $this->reindexWorker($kyc);
        $kyc->user?->notify(new KycReviewedNotification($kyc));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('KYC rejected.')]);

        return back();
    }

    /**
     * The worker directory indexes a `verified` flag, so a KYC decision has to
     * push the profile back into the search index.
     */
    private function reindexWorker(KycDocument $kyc): void
    {
        $kyc->user?->workerProfile?->searchable();
    }

    /**
     * Streams a proof file: `pan`, `aadhaar` or `gst`, or `alt-{doc}` for the
     * alternate ID sent in place of a missing one.
     */
    public function document(KycDocument $kyc, string $type): StreamedResponse
    {
        $doc = str_starts_with($type, 'alt-') ? substr($type, 4) : $type;
        abort_unless(array_key_exists($doc, KycRequirements::DOCUMENTS), 404);

        $path = str_starts_with($type, 'alt-')
            ? ($kyc->missing_documents[$doc]['doc_path'] ?? null)
            : KycDocument::pathFor($kyc, $doc);

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
