<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\Billing\InvoiceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tax invoice for one payment (a first payment or a renewal), as data rather than a rendered page —
 * the app lays it out itself and can share or print from there. `pdf` is the
 * same invoice as a file, for sharing.
 *
 * The web equivalent renders the identical fields into an Inertia page.
 *
 * @see \App\Http\Controllers\InvoiceController
 */
class InvoiceController extends Controller
{
    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorizeInvoice($request, $invoice);

        return response()->json([
            ...InvoiceDocument::for($invoice)->data(),
            'pdf_url' => route('api.employer.invoices.pdf', $invoice),
        ]);
    }

    public function pdf(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);

        $document = InvoiceDocument::for($invoice);

        return response($document->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename().'"',
        ]);
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        // Team members bill under the owner's account, so compare against that
        // rather than the signed-in user.
        abort_unless($invoice->employer_id === $request->user()->employerAccount()->id, 403);
    }
}
