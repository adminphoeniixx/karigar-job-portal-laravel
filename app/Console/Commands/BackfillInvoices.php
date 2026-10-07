<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Services\Billing\Gst;
use Illuminate\Console\Command;

/**
 * Copies the invoices issued before the invoices table (one number stored on
 * the subscription) into it, under their original numbers. Safe to run any
 * number of times; run it once after the deploy that adds the table, since
 * the old code keeps issuing onto subscriptions until then.
 */
class BackfillInvoices extends Command
{
    protected $signature = 'billing:backfill-invoices';

    protected $description = 'Copy invoice numbers stored on subscriptions into the invoices table';

    public function handle(): int
    {
        $subscriptions = Subscription::whereNotNull('invoice_number')
            ->whereDoesntHave('invoices', fn ($q) => $q->where('cycle', 1))
            ->with('plan')
            ->get();

        foreach ($subscriptions as $s) {
            $start = $s->starts_at ?? $s->invoiced_at;

            Invoice::create([
                ...Gst::invoiceColumns($s),
                'subscription_id' => $s->id,
                'employer_id' => $s->employer_id,
                'cycle' => 1,
                'number' => $s->invoice_number,
                'issued_at' => $s->invoiced_at ?? $s->created_at,
                'plan_name' => $s->plan->name,
                'period_start' => $start,
                'period_end' => $start === null ? null
                    : ($s->plan->interval === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth()),
            ]);

            $this->line("  <info>✓</info> {$s->invoice_number}");
        }

        $this->info($subscriptions->isEmpty() ? 'Nothing to copy.' : "Copied {$subscriptions->count()} invoice(s).");

        return self::SUCCESS;
    }
}
