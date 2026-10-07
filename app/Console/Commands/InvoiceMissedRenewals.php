<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\Gst;
use App\Services\Billing\InvoiceDocument;
use App\Services\RazorpayService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Issues the tax invoices for renewals Razorpay charged before renewals were
 * invoiced. It asks Razorpay for every payment on each subscription and
 * invoices the cycles that have none, with that payment's amount and billing
 * period. The invoice is dated today: that is when it is issued.
 *
 * Run it on the server, after billing:backfill-invoices, with the live
 * Razorpay keys: only those can see live subscriptions. Safe to run again;
 * an invoiced cycle or payment is never invoiced twice.
 */
class InvoiceMissedRenewals extends Command
{
    protected $signature = 'billing:invoice-missed-renewals
        {--dry-run : List what would be invoiced, change nothing}
        {--no-email : Issue the invoices without emailing them}';

    protected $description = 'Issue tax invoices for Razorpay renewals that were charged but never invoiced';

    public function handle(RazorpayService $razorpay): int
    {
        if (! $razorpay->configured()) {
            $this->error('Razorpay keys are not configured.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $issued = 0;

        $subscriptions = Subscription::whereNotNull('razorpay_subscription_id')
            ->whereNotNull('starts_at')
            ->with('plan', 'invoices')
            ->orderBy('id')
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $charges = $razorpay->subscriptionCharges($subscription->razorpay_subscription_id);
            } catch (Throwable $e) {
                $this->warn("  ! #{$subscription->id} {$subscription->razorpay_subscription_id}: {$e->getMessage()}");

                continue;
            }

            $invoicedPayments = $subscription->invoices->pluck('razorpay_payment_id')->filter()->all();

            foreach ($charges as $index => $charge) {
                $cycle = $index + 1;

                // Cycle 1 is the checkout payment, invoiced when it was made.
                if ($cycle === 1
                    || $subscription->invoices->contains('cycle', $cycle)
                    || in_array($charge['payment_id'], $invoicedPayments, true)) {
                    continue;
                }

                $label = "#{$subscription->id} cycle {$cycle}: ₹".number_format($charge['amount'], 2)
                    .' paid '.($charge['paid_at'] ? Carbon::createFromTimestamp($charge['paid_at'])->timezone(config('app.display_timezone'))->format('d M Y') : '?');

                if ($dryRun) {
                    $this->line("  would invoice {$label}");
                    $issued++;

                    continue;
                }

                $invoice = Invoice::issue(
                    $subscription,
                    cycle: $cycle,
                    amounts: Gst::invoiceColumns($subscription, $charge['amount']),
                    paymentId: $charge['payment_id'],
                    periodStart: $charge['start'] ? Carbon::createFromTimestamp($charge['start']) : null,
                    periodEnd: $charge['end'] ? Carbon::createFromTimestamp($charge['end']) : null,
                );

                if ($invoice === null) {
                    continue;
                }

                if (! $this->option('no-email')) {
                    InvoiceDocument::for($invoice)->email();
                }

                $this->line("  <info>✓</info> {$invoice->number} for {$label}");
                $issued++;
            }
        }

        $this->info(match (true) {
            $issued === 0 => 'No missed renewals.',
            $dryRun => "{$issued} renewal(s) would be invoiced. Run again without --dry-run to issue them.",
            default => "Issued {$issued} invoice(s).",
        });

        return self::SUCCESS;
    }
}
