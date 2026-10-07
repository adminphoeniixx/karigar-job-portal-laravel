<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Support\TemplatedMailer;
use Barryvdh\DomPDF\Facade\Pdf;
use Throwable;

/**
 * One tax invoice, in every shape it is needed: the data the web page and the
 * app render, the PDF, and the email that carries the PDF to the employer.
 * Keeping them in one place is what keeps the three from drifting apart.
 */
class InvoiceDocument
{
    public function __construct(private Invoice $invoice) {}

    public static function for(Invoice $invoice): self
    {
        return new self($invoice->loadMissing('subscription.plan', 'subscription.coupon', 'employer.employerProfile'));
    }

    /**
     * @return array{invoice: array<string, mixed>, seller: array<string, mixed>, buyer: array<string, mixed>}
     */
    public function data(): array
    {
        $i = $this->invoice;
        $s = $i->subscription;
        $account = $i->employer;
        $profile = $account->employerProfile;
        $money = fn ($v) => $v !== null ? (float) $v : null;
        $local = fn ($at) => $at?->timezone(config('app.display_timezone'))->format('d M Y');

        return [
            'invoice' => [
                'number' => $i->number,
                'date' => $local($i->issued_at),
                // 1 is the first payment; 2 onwards are monthly/yearly renewals.
                'cycle' => $i->cycle,
                'plan' => [
                    'name' => $i->plan_name,
                    'interval' => $s->plan->interval,
                    // What the plan cost on this invoice, not what it costs now.
                    'price' => (float) $i->subtotal_amount + (float) ($i->discount_amount ?? 0),
                ],
                'coupon_code' => $i->discount_amount > 0 ? $s->coupon?->code : null,
                'discount' => $money($i->discount_amount),
                'subtotal' => $money($i->subtotal_amount),
                'gst_percent' => $money($i->gst_percent),
                'gst_amount' => $money($i->gst_amount),
                // Older invoices predate the split; they print the GST as one line.
                'cgst_amount' => $money($i->cgst_amount),
                'sgst_amount' => $money($i->sgst_amount),
                'igst_amount' => $money($i->igst_amount),
                'place_of_supply' => $i->place_of_supply,
                'sac' => $i->sac_code,
                'total' => $money($i->total_amount),
                'period' => [
                    'from' => $local($i->period_start),
                    'to' => $local($i->period_end),
                ],
                'payment_ref' => $i->razorpay_payment_id ?: $s->razorpay_subscription_id,
            ],
            // The GSTIN the invoice was issued under, even if it has changed since.
            'seller' => [...Gst::seller(), 'gstin' => $i->seller_gstin ?: Gst::seller()['gstin']],
            'buyer' => [
                'name' => $profile?->company_name ?: $account->name,
                'address' => trim(implode(', ', array_filter([
                    $profile?->address, $profile?->city, $profile?->state,
                ]))),
                'gstin' => $profile?->gstin,
                'email' => $account->contactEmail(),
                'phone' => $account->phone ?? $profile?->phone,
            ],
        ];
    }

    public function filename(): string
    {
        return $this->invoice->filename();
    }

    /**
     * The invoice as PDF bytes.
     */
    public function pdf(): string
    {
        return Pdf::loadView('invoices.pdf', [
            ...$this->data(),
            'logo' => public_path('images/brand/wordmark.png'),
        ])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();
    }

    /**
     * Email the invoice to the employer, PDF attached, and a copy to the
     * address set in Admin → Settings → Billing & GST. Sent once, when the
     * payment that created the invoice lands. A PDF that fails to render still
     * lets the email go, with the link to the invoice page in it.
     *
     * The copy goes even when the employer has no real inbox (a phone-OTP
     * account), so the company still has every invoice it issued.
     */
    public function email(): void
    {
        $i = $this->invoice;
        $data = $this->data();

        try {
            $attachments = [$this->filename() => $this->pdf()];
        } catch (Throwable $e) {
            report($e);
            $attachments = [];
        }

        $inr = fn ($v) => '₹'.number_format((float) $v, 2);

        $fields = [
            'employer_name' => $data['buyer']['name'],
            'plan_name' => $i->plan_name,
            'invoice_number' => $i->number,
            'invoice_date' => (string) $data['invoice']['date'],
            'amount_before_tax' => $inr($i->subtotal_amount),
            'gst_breakup' => $this->gstLine(),
            'total_paid' => $inr($i->total_amount),
            'valid_until' => $data['invoice']['period']['to'] ?? '—',
            'action_url' => route('invoices.show', $i),
        ];

        $to = $i->employer->contactEmail();

        TemplatedMailer::send('payment_received', $to, $fields, $attachments);

        $copyTo = Gst::invoiceCopyTo();

        if ($copyTo !== null && strcasecmp($copyTo, (string) $to) !== 0) {
            TemplatedMailer::send('payment_received', $copyTo, $fields, $attachments);
        }
    }

    /**
     * "CGST 9% ₹44.91 + SGST 9% ₹44.91", or the IGST equivalent, for the email.
     */
    private function gstLine(): string
    {
        $s = $this->invoice;
        $inr = fn ($v) => '₹'.number_format((float) $v, 2);
        $rate = (float) $s->gst_percent;
        $half = rtrim(rtrim(number_format($rate / 2, 2), '0'), '.');
        $full = rtrim(rtrim(number_format($rate, 2), '0'), '.');

        return match (true) {
            (float) $s->gst_amount <= 0 => __('No GST'),
            (float) $s->cgst_amount > 0 => "CGST {$half}% {$inr($s->cgst_amount)} + SGST {$half}% {$inr($s->sgst_amount)}",
            (float) $s->igst_amount > 0 => "IGST {$full}% {$inr($s->igst_amount)}",
            default => "GST {$full}% {$inr($s->gst_amount)}",
        };
    }
}
