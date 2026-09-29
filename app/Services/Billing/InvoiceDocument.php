<?php

namespace App\Services\Billing;

use App\Models\Subscription;
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
    public function __construct(private Subscription $subscription) {}

    public static function for(Subscription $subscription): self
    {
        return new self($subscription->loadMissing('plan', 'coupon', 'employer.employerProfile'));
    }

    /**
     * @return array{invoice: array<string, mixed>, seller: array<string, mixed>, buyer: array<string, mixed>}
     */
    public function data(): array
    {
        $s = $this->subscription;
        $account = $s->employer;
        $profile = $account->employerProfile;
        $money = fn ($v) => $v !== null ? (float) $v : null;

        return [
            'invoice' => [
                'number' => $s->invoice_number,
                'date' => $s->invoiced_at?->format('d M Y'),
                'plan' => [
                    'name' => $s->plan->name,
                    'interval' => $s->plan->interval,
                    // What the plan cost on this invoice, not what it costs now.
                    'price' => (float) $s->subtotal_amount + (float) ($s->discount_amount ?? 0),
                ],
                'coupon_code' => $s->coupon?->code,
                'discount' => $money($s->discount_amount),
                'subtotal' => $money($s->subtotal_amount),
                'gst_percent' => $money($s->gst_percent),
                'gst_amount' => $money($s->gst_amount),
                // Older invoices predate the split; they print the GST as one line.
                'cgst_amount' => $money($s->cgst_amount),
                'sgst_amount' => $money($s->sgst_amount),
                'igst_amount' => $money($s->igst_amount),
                'place_of_supply' => $s->place_of_supply,
                'sac' => $s->sac_code,
                'total' => $money($s->total_amount),
                'period' => [
                    'from' => $s->starts_at?->format('d M Y'),
                    'to' => $s->ends_at?->format('d M Y'),
                ],
                'payment_ref' => $s->razorpay_subscription_id,
            ],
            // The GSTIN the invoice was issued under, even if it has changed since.
            'seller' => [...Gst::seller(), 'gstin' => $s->seller_gstin ?: Gst::seller()['gstin']],
            'buyer' => [
                'name' => $profile?->company_name ?: $account->name,
                'address' => trim(implode(', ', array_filter([
                    $profile?->address, $profile?->city, $profile?->state,
                ]))),
                'gstin' => $profile?->gstin,
                'email' => $account->email,
                'phone' => $account->phone ?? $profile?->phone,
            ],
        ];
    }

    public function filename(): string
    {
        return "Invoice-{$this->subscription->invoice_number}.pdf";
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
     * Email the invoice to the employer, PDF attached. Sent once, when the
     * payment that created the invoice lands. A PDF that fails to render still
     * lets the email go, with the link to the invoice page in it.
     */
    public function email(): void
    {
        $s = $this->subscription;
        $data = $this->data();

        try {
            $attachments = [$this->filename() => $this->pdf()];
        } catch (Throwable $e) {
            report($e);
            $attachments = [];
        }

        $inr = fn ($v) => '₹'.number_format((float) $v, 2);

        TemplatedMailer::send('payment_received', $s->employer->email, [
            'employer_name' => $data['buyer']['name'],
            'plan_name' => $s->plan->name,
            'invoice_number' => (string) $s->invoice_number,
            'invoice_date' => (string) $data['invoice']['date'],
            'amount_before_tax' => $inr($s->subtotal_amount),
            'gst_breakup' => $this->gstLine(),
            'total_paid' => $inr($s->total_amount),
            'valid_until' => $data['invoice']['period']['to'] ?? '—',
            'action_url' => route('subscription.invoice', $s),
        ], $attachments);
    }

    /**
     * "CGST 9% ₹44.91 + SGST 9% ₹44.91", or the IGST equivalent, for the email.
     */
    private function gstLine(): string
    {
        $s = $this->subscription;
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
