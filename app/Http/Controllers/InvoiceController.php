<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\InvoiceDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceController extends Controller
{
    /**
     * Printable tax invoice for one payment.
     */
    public function show(Request $request, Invoice $invoice): Response
    {
        $this->authorizeInvoice($request, $invoice);

        return Inertia::render('subscription/Invoice', [
            ...InvoiceDocument::for($invoice)->data(),
            'pdfUrl' => route('invoices.pdf', $invoice),
        ]);
    }

    /**
     * The same invoice as a PDF, the file the payment email attaches.
     */
    public function pdf(Request $request, Invoice $invoice): HttpResponse
    {
        $this->authorizeInvoice($request, $invoice);

        $document = InvoiceDocument::for($invoice);

        return response($document->pdf(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document->filename().'"',
        ]);
    }

    /**
     * Invoice links from before a subscription could have several invoices,
     * still sitting in employers' inboxes: they open its first one.
     */
    public function legacy(Request $request, Subscription $subscription): RedirectResponse
    {
        $invoice = $subscription->invoices()->where('cycle', 1)->first();
        abort_if($invoice === null, 404);
        $this->authorizeInvoice($request, $invoice);

        return to_route($request->routeIs('subscription.invoice.pdf') ? 'invoices.pdf' : 'invoices.show', $invoice);
    }

    private function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        abort_unless($invoice->employer_id === $request->user()->employerAccount()->id, 403);
    }
}
