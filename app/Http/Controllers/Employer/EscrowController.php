<?php

namespace App\Http\Controllers\Employer;

use App\Enums\ApplicationStatus;
use App\Http\Controllers\Controller;
use App\Models\Escrow;
use App\Models\JobApplication;
use App\Services\EscrowService;
use App\Services\RazorpayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EscrowController extends Controller
{
    public function __construct(
        private EscrowService $escrows,
        private RazorpayService $razorpay,
    ) {
    }

    /**
     * Employer funds an escrow for an accepted application: create the escrow
     * and a Razorpay order, then render the checkout page (mirrors the
     * subscription checkout flow).
     */
    public function fund(Request $request, JobApplication $application): RedirectResponse|Response
    {
        $this->authorizeApplication($request, $application);

        abort_unless($application->status === ApplicationStatus::Accepted, 422, 'You can only fund accepted applications.');

        if (! $this->razorpay->configured()) {
            return back()->with('error', __('Payments are not configured yet.'));
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000'],
        ]);

        $escrow = $this->escrows->createFor($application, (float) $data['amount']);

        if ($escrow->status->value !== 'pending') {
            return back()->with('error', __('This job is already funded.'));
        }

        $order = $this->razorpay->createOrder((float) $escrow->amount, 'escrow_'.$escrow->id, $escrow->currency);
        $escrow->forceFill(['razorpay_order_id' => $order['id']])->save();

        return Inertia::render('employer/EscrowCheckout', [
            'razorpayKey' => config('services.razorpay.key'),
            'escrowId' => $escrow->id,
            'jobId' => $application->job->id,
            'orderId' => $order['id'],
            'amount' => $order['amount'],
            'currency' => $order['currency'],
            'workerName' => $application->worker->name,
        ]);
    }

    /**
     * Razorpay Checkout success callback: verify the signature and hold funds.
     */
    public function callback(Request $request, Escrow $escrow): RedirectResponse
    {
        $this->authorizeEscrow($request, $escrow);

        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        if (! $this->razorpay->verifyPaymentSignature($data)) {
            return back()->with('error', __('Payment verification failed.'));
        }

        $this->escrows->markFunded($escrow, $data['razorpay_payment_id']);

        return redirect("/employer/jobs/{$escrow->application->job->id}/applicants")
            ->with('success', __('Payment secured in escrow. Release it once the work is done.'));
    }

    /**
     * Employer confirms the work is complete; queues the payout for admin release.
     */
    public function release(Request $request, Escrow $escrow): RedirectResponse
    {
        $this->authorizeEscrow($request, $escrow);

        $this->escrows->requestRelease($escrow);

        return back()->with('success', __('Release requested — the payout will be sent to the worker shortly.'));
    }

    private function authorizeApplication(Request $request, JobApplication $application): void
    {
        abort_unless($application->job->employer_id === $request->user()->id, 403);
    }

    private function authorizeEscrow(Request $request, Escrow $escrow): void
    {
        abort_unless($escrow->employer_id === $request->user()->id, 403);
    }
}
