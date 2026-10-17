<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Employer\AiCreditController as WebAiCreditController;
use App\Models\AiCreditEntry;
use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Services\AiCredits;
use App\Services\Billing\AiCreditCheckout;
use App\Services\Billing\SubscriptionCheckout;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * AI call credits for the employer app: the balance, the packs, the Razorpay
 * order hand-off and the ledger. Same rules as the web page
 * (Employer\AiCreditController); this returns what the mobile Razorpay SDK
 * needs.
 */
class AiCreditController extends Controller
{
    /**
     * Balance, packs to buy and the "how credits work" lines.
     */
    public function index(Request $request, RazorpayService $razorpay): JsonResponse
    {
        $account = $request->user()->employerAccount();

        return response()->json([
            'credits' => AiCredits::for($account)->summary(),
            'packs' => AiCredits::packs(),
            'rules' => AiCredits::rules(),
            'totals' => WebAiCreditController::totals($account),
            // Only the account owner buys; team members see the balance.
            'can_buy' => $request->user()->id === $account->id && $razorpay->configured(),
            'billing_email' => $account->contactEmail(),
            'payment' => [
                'configured' => $razorpay->configured(),
                'key' => config('services.razorpay.key'),
            ],
        ]);
    }

    /**
     * The ledger, newest first. ?filter=all|added|spent, paginated.
     */
    public function ledger(Request $request): JsonResponse
    {
        $account = $request->user()->employerAccount();
        $filter = in_array($request->query('filter'), ['added', 'spent'], true) ? $request->query('filter') : 'all';

        $page = WebAiCreditController::ledgerQuery($account, $filter)->paginate(min(50, max(5, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (AiCreditEntry $entry) => $entry->toRow(api: true))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'balance' => AiCredits::for($account)->balance(),
        ]);
    }

    /**
     * Open a Razorpay order for a pack; the app hands `razorpay_order_id` to
     * the Razorpay checkout SDK, then sends the result to `callback`.
     */
    public function checkout(Request $request, AiCreditPack $pack, RazorpayService $razorpay, AiCreditCheckout $checkout): JsonResponse
    {
        $account = $request->user()->employerAccount();
        abort_unless($request->user()->id === $account->id, 403, __('Only the account owner can recharge credits.'));
        abort_unless($pack->is_active, 404);

        if (! $razorpay->configured()) {
            return response()->json(['message' => __('Payments are not configured yet. Please try again later.'), 'code' => 'payments_off'], 422);
        }

        // `email`: where the GST invoice goes, as on plan checkout.
        $data = $request->validate([
            'email' => SubscriptionCheckout::emailRules($account),
        ]);

        SubscriptionCheckout::saveBillingEmail($account, $data['email'] ?? null);

        try {
            $purchase = $checkout->start($account, $pack, $request->user());
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => __('Could not start the payment. Please try again in a few minutes.'),
                'code' => 'checkout_failed',
            ], 502);
        }

        return response()->json([
            'purchase_id' => $purchase->id,
            'razorpay_order_id' => $purchase->razorpay_order_id,
            'razorpay_key' => config('services.razorpay.key'),
            // In paise, for the SDK's `amount`.
            'amount_paise' => (int) round((float) $purchase->total_amount * 100),
            'currency' => 'INR',
            'pack' => $pack->toCard(),
            'amounts' => AiCreditCheckout::amounts($purchase),
            'billing_email' => $account->fresh()->contactEmail(),
        ], 201);
    }

    /**
     * Confirm a recharge paid in the app's Razorpay checkout.
     */
    public function callback(Request $request, RazorpayService $razorpay, AiCreditCheckout $checkout): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $account = $request->user()->employerAccount();

        $purchase = AiCreditPurchase::where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('employer_id', $account->id)
            ->firstOrFail();

        if (! $razorpay->verifyPaymentSignature($data)) {
            return response()->json(['message' => __('Payment verification failed.'), 'code' => 'verification_failed'], 422);
        }

        $checkout->confirm($purchase, $data['razorpay_payment_id']);

        return response()->json([
            'message' => __(':credits AI call credits added.', ['credits' => $purchase->totalCredits()]),
            'credits' => AiCredits::for($account)->summary(),
            'invoice_id' => $purchase->fresh()->invoice?->id,
        ]);
    }
}
