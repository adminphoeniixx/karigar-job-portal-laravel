<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\AiCreditEntry;
use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Models\User;
use App\Services\AiCredits;
use App\Services\Billing\AiCreditCheckout;
use App\Services\Billing\SubscriptionCheckout;
use App\Services\RazorpayService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The employer's AI call credits on the web: balance, packs to recharge with,
 * and the ledger of every credit added and spent. The app has the same in
 * Api\Employer\AiCreditController.
 */
class AiCreditController extends Controller
{
    public function index(Request $request, RazorpayService $razorpay): Response
    {
        $account = $request->user()->employerAccount();
        $filter = in_array($request->query('filter'), ['added', 'spent'], true) ? $request->query('filter') : 'all';

        $ledger = self::ledgerQuery($account, $filter)
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AiCreditEntry $entry) => $entry->toRow());

        return Inertia::render('employer/AiCredits', [
            'summary' => AiCredits::for($account)->summary(),
            'packs' => AiCredits::packs(),
            'rules' => AiCredits::rules(),
            'ledger' => $ledger,
            'filter' => $filter,
            'totals' => self::totals($account),
            'canBuy' => $request->user()->id === $account->id,
            'paymentsConfigured' => $razorpay->configured(),
            'needsEmail' => $account->contactEmail() === null,
        ]);
    }

    /**
     * Open a Razorpay order for a pack and hand over to the checkout page.
     */
    public function checkout(Request $request, AiCreditPack $pack, RazorpayService $razorpay, AiCreditCheckout $checkout): RedirectResponse|Response
    {
        $account = $request->user()->employerAccount();
        abort_unless($request->user()->id === $account->id, 403, __('Only the account owner can recharge credits.'));
        abort_unless($pack->is_active, 404);

        if (! $razorpay->configured()) {
            return back()->with('toast', ['type' => 'error', 'message' => __('Payments are not configured yet. Please try again later.')]);
        }

        $data = $request->validate([
            'email' => SubscriptionCheckout::emailRules($account),
        ]);

        SubscriptionCheckout::saveBillingEmail($account, $data['email'] ?? null);

        if ($account->fresh()->contactEmail() === null) {
            return back()->withErrors(['email' => __('Enter the email your GST invoice should go to.')]);
        }

        try {
            $purchase = $checkout->start($account, $pack, $request->user());
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'message' => __('Could not start the payment. Please try again in a few minutes.')]);
        }

        return Inertia::render('employer/AiCreditCheckout', [
            'razorpayKey' => config('services.razorpay.key'),
            'orderId' => $purchase->razorpay_order_id,
            'pack' => $pack->toCard(),
            'amounts' => AiCreditCheckout::amounts($purchase),
            'prefill' => [
                'name' => $account->employerProfile?->company_name ?: $account->name,
                'email' => $account->contactEmail(),
                'contact' => $account->phone,
            ],
        ]);
    }

    public function callback(Request $request, RazorpayService $razorpay, AiCreditCheckout $checkout): RedirectResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $purchase = AiCreditPurchase::where('razorpay_order_id', $data['razorpay_order_id'])
            ->where('employer_id', $request->user()->employerAccount()->id)
            ->firstOrFail();

        if (! $razorpay->verifyPaymentSignature($data)) {
            return to_route('employer.ai-credits')->with('toast', ['type' => 'error', 'message' => __('Payment verification failed.')]);
        }

        $checkout->confirm($purchase, $data['razorpay_payment_id']);

        return to_route('employer.ai-credits')->with('toast', [
            'type' => 'success',
            'message' => __(':credits AI call credits added.', ['credits' => $purchase->totalCredits()]),
        ]);
    }

    /**
     * The ledger, newest first. `added` is everything that put credits in,
     * `spent` everything that took them out.
     *
     * @return HasMany<AiCreditEntry, User>
     */
    public static function ledgerQuery(User $account, string $filter): HasMany
    {
        return $account->aiCreditEntries()
            ->with('purchase.invoice')
            ->when($filter === 'added', fn (Builder $q) => $q->where('credits', '>', 0))
            ->when($filter === 'spent', fn (Builder $q) => $q->where('credits', '<', 0))
            ->latest('id');
    }

    /**
     * @return array{added: int, spent: int, calls: int, recharged_amount: float}
     */
    public static function totals(User $account): array
    {
        $entries = $account->aiCreditEntries();

        return [
            'added' => (int) (clone $entries)->where('credits', '>', 0)->sum('credits'),
            'spent' => (int) -(clone $entries)->where('credits', '<', 0)->sum('credits'),
            'calls' => (clone $entries)->where('type', AiCreditEntry::TYPE_CALL)->count(),
            'recharged_amount' => round((float) AiCreditPurchase::where('employer_id', $account->id)->where('status', AiCreditPurchase::STATUS_PAID)->sum('total_amount'), 2),
        ];
    }
}
