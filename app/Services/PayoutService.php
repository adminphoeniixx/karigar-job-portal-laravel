<?php

namespace App\Services;

use App\Models\Escrow;
use App\Models\WorkerProfile;
use Razorpay\Api\Api;
use RuntimeException;

/**
 * RazorpayX payouts (money-out) for releasing escrow funds to workers.
 *
 * This is intentionally gated: it only runs when RazorpayX is configured, and
 * is only ever invoked from an explicit admin action — never automatically.
 * A live RazorpayX account + payout API access is required.
 */
class PayoutService
{
    public function configured(): bool
    {
        return ! empty(config('services.razorpay.key'))
            && ! empty(config('services.razorpay.secret'))
            && ! empty(config('services.razorpayx.account_number'));
    }

    protected function api(): Api
    {
        if (! $this->configured()) {
            throw new RuntimeException('RazorpayX payouts are not configured. Set RAZORPAYX_ACCOUNT_NUMBER and Razorpay keys.');
        }

        return new Api(config('services.razorpay.key'), config('services.razorpay.secret'));
    }

    /**
     * Ensure the worker has a RazorpayX fund account for their UPI id, creating
     * (and caching) a contact + fund account if needed. Returns fund account id.
     */
    public function ensureFundAccount(WorkerProfile $profile): string
    {
        if (! empty($profile->razorpayx_fund_account_id)) {
            return $profile->razorpayx_fund_account_id;
        }

        if (empty($profile->payout_upi)) {
            throw new RuntimeException('Worker has not added a payout UPI id.');
        }

        $contact = $this->api()->contact->create([
            'name' => $profile->user?->name ?? 'Worker',
            'type' => 'employee',
            'reference_id' => 'worker_'.$profile->user_id,
        ]);

        $fundAccount = $this->api()->fundAccount->create([
            'contact_id' => $contact['id'],
            'account_type' => 'vpa',
            'vpa' => ['address' => $profile->payout_upi],
        ]);

        $profile->forceFill(['razorpayx_fund_account_id' => $fundAccount['id']])->save();

        return $fundAccount['id'];
    }

    /**
     * Execute the real payout for a funded escrow. Returns the payout id.
     */
    public function payout(Escrow $escrow): string
    {
        $profile = $escrow->worker?->workerProfile;

        if ($profile === null) {
            throw new RuntimeException('Worker profile not found.');
        }

        $fundAccountId = $this->ensureFundAccount($profile);

        $payout = $this->api()->payout->create([
            'account_number' => config('services.razorpayx.account_number'),
            'fund_account_id' => $fundAccountId,
            'amount' => (int) round((float) $escrow->payout_amount * 100), // paise
            'currency' => $escrow->currency ?? 'INR',
            'mode' => 'UPI',
            'purpose' => 'payout',
            'queue_if_low_balance' => true,
            'reference_id' => 'escrow_'.$escrow->id,
            'narration' => 'Karigar job payout',
        ]);

        return $payout['id'];
    }
}
