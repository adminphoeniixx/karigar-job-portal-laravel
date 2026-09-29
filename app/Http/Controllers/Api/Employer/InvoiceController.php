<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Billing\InvoiceDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tax invoice for a paid subscription, as data rather than a rendered page —
 * the app lays it out itself and can share or print from there. `pdf` is the
 * same invoice as a file, for sharing.
 *
 * The web equivalent renders the identical fields into an Inertia page.
 *
 * @see \App\Http\Controllers\InvoiceController
 */
class InvoiceController extends Controller
{
    public function show(Request $request, Subscription $subscription): JsonResponse
    {
        $this->authorizeInvoice($request, $subscription);

        return response()->json([
            ...InvoiceDocument::for($subscription)->data(),
            'pdf_url' => route('api.employer.invoices.pdf', $subscription),
        ]);
    }

    public function pdf(Request $request, Subscription $subscription): Response
    {
        $this->authorizeInvoice($request, $subscription);

        $document = InvoiceDocument::for($subscription);

        return response($document->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename().'"',
        ]);
    }

    private function authorizeInvoice(Request $request, Subscription $subscription): void
    {
        // Team members bill under the owner's account, so compare against that
        // rather than the signed-in user.
        abort_unless($subscription->employer_id === $request->user()->employerAccount()->id, 403);
        abort_if($subscription->invoice_number === null, 404);
    }
}
