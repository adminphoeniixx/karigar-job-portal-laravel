<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\Billing\InvoiceDocument;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceController extends Controller
{
    /**
     * Printable tax invoice for a paid subscription.
     */
    public function show(Request $request, Subscription $subscription): Response
    {
        $this->authorizeInvoice($request, $subscription);

        return Inertia::render('subscription/Invoice', InvoiceDocument::for($subscription)->data());
    }

    /**
     * The same invoice as a PDF, the file the payment email attaches.
     */
    public function pdf(Request $request, Subscription $subscription): HttpResponse
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
        abort_unless($subscription->employer_id === $request->user()->employerAccount()->id, 403);
        abort_if($subscription->invoice_number === null, 404);
    }
}
