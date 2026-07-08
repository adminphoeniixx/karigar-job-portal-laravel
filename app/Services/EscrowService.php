<?php

namespace App\Services;

use App\Enums\EscrowStatus;
use App\Models\Escrow;
use App\Models\JobApplication;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EscrowService
{
    public function __construct(private PayoutService $payouts)
    {
    }

    /**
     * Split a gross amount into platform commission and worker payout.
     *
     * @return array{commission: float, payout: float}
     */
    public function split(float $amount): array
    {
        $percent = (float) config('escrow.commission_percent', 10);
        $commission = round($amount * $percent / 100, 2);

        // With the worker bearing the fee, they receive amount - commission.
        // (Employer-borne fees would be handled at funding time instead.)
        $payout = config('escrow.fee_bearer') === 'employer'
            ? $amount
            : round($amount - $commission, 2);

        return ['commission' => $commission, 'payout' => $payout];
    }

    /**
     * Create a pending escrow for an accepted application (idempotent).
     */
    public function createFor(JobApplication $application, float $amount): Escrow
    {
        $split = $this->split($amount);

        return Escrow::firstOrCreate(
            ['job_application_id' => $application->id],
            [
                'employer_id' => $application->job->employer_id,
                'worker_id' => $application->worker_id,
                'amount' => $amount,
                'commission' => $split['commission'],
                'payout_amount' => $split['payout'],
                'currency' => config('escrow.currency', 'INR'),
                'status' => EscrowStatus::Pending,
            ],
        );
    }

    /**
     * Mark an escrow as funded once the employer's payment is captured.
     */
    public function markFunded(Escrow $escrow, string $paymentId): void
    {
        if ($escrow->status !== EscrowStatus::Pending) {
            return; // idempotent — ignore duplicate webhooks
        }

        DB::transaction(function () use ($escrow, $paymentId) {
            $escrow->forceFill([
                'status' => EscrowStatus::Funded,
                'razorpay_payment_id' => $paymentId,
                'funded_at' => now(),
            ])->save();

            $escrow->ledger()->create([
                'type' => 'funded',
                'amount' => $escrow->amount,
                'reference' => $paymentId,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Employer confirms the work is done; queue the payout for admin release.
     */
    public function requestRelease(Escrow $escrow): void
    {
        if ($escrow->status !== EscrowStatus::Funded) {
            throw new RuntimeException('Only funded escrows can be released.');
        }

        $escrow->forceFill([
            'status' => EscrowStatus::ReleaseRequested,
            'release_requested_at' => now(),
        ])->save();
    }

    /**
     * Execute the real payout to the worker. Gated on RazorpayX config and
     * only ever called from an explicit admin action.
     */
    public function release(Escrow $escrow): void
    {
        if (! in_array($escrow->status, [EscrowStatus::Funded, EscrowStatus::ReleaseRequested, EscrowStatus::Disputed], true)) {
            throw new RuntimeException('This escrow cannot be released in its current state.');
        }

        $payoutId = $this->payouts->payout($escrow);

        DB::transaction(function () use ($escrow, $payoutId) {
            $escrow->forceFill([
                'status' => EscrowStatus::Released,
                'razorpay_payout_id' => $payoutId,
                'released_at' => now(),
            ])->save();

            $escrow->ledger()->createMany([
                ['type' => 'released', 'amount' => $escrow->payout_amount, 'reference' => $payoutId, 'created_at' => now()],
                ['type' => 'fee', 'amount' => $escrow->commission, 'created_at' => now()],
            ]);
        });
    }

    /**
     * Refund the held funds back to the employer (admin action).
     * Marks the escrow refunded and records it; the actual Razorpay refund
     * call is left to the admin/ops flow to avoid unattended money movement.
     */
    public function refund(Escrow $escrow, ?string $note = null): void
    {
        if (! in_array($escrow->status, [EscrowStatus::Funded, EscrowStatus::ReleaseRequested, EscrowStatus::Disputed], true)) {
            throw new RuntimeException('This escrow cannot be refunded in its current state.');
        }

        DB::transaction(function () use ($escrow, $note) {
            $escrow->forceFill([
                'status' => EscrowStatus::Refunded,
                'refunded_at' => now(),
                'admin_note' => $note,
            ])->save();

            $escrow->ledger()->create([
                'type' => 'refunded',
                'amount' => $escrow->amount,
                'created_at' => now(),
            ]);
        });
    }
}
