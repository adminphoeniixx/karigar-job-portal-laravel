<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AiCreditEntry;
use App\Models\AiCreditPack;
use App\Models\AiCreditPurchase;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiCredits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → AI Credits: the rate and limits, the packs on sale, every
 * employer's ledger, and adding or taking credits by hand.
 */
class AiCreditController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q'));
        $type = in_array($request->query('type'), AiCreditEntry::TYPES, true) ? $request->query('type') : null;

        $ledger = AiCreditEntry::query()
            ->with('employer:id,name,phone,email', 'employer.employerProfile:id,user_id,company_name', 'purchase.invoice', 'creator:id,name')
            ->when($type !== null, fn (Builder $b) => $b->where('type', $type))
            ->when($q !== '', fn (Builder $b) => $b->whereHas('employer', fn (Builder $u) => $this->matchEmployer($u, $q)))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AiCreditEntry $entry) => [
                ...$entry->toRow(),
                'employer' => [
                    'id' => $entry->employer?->id,
                    'name' => $entry->employer?->employerProfile?->company_name ?: $entry->employer?->name,
                    'phone' => $entry->employer?->phone,
                ],
                'by' => $entry->creator?->name,
            ]);

        return Inertia::render('admin/AiCredits', [
            'settings' => [
                'enabled' => AiCredits::enabled(),
                'per_minute' => AiCredits::perMinute(),
                'min_balance' => AiCredits::minBalance(),
                'low_alert' => AiCredits::lowAlert(),
                'welcome' => AiCredits::welcome(),
                'max_call_minutes' => AiCredits::maxCallMinutes(),
            ],
            'packs' => AiCreditPack::orderBy('sort_order')->orderBy('price')->get()->map(fn (AiCreditPack $pack) => [
                ...$pack->toCard(),
                'is_active' => $pack->is_active,
                'sort_order' => $pack->sort_order,
                'sold' => AiCreditPurchase::where('ai_credit_pack_id', $pack->id)->where('status', AiCreditPurchase::STATUS_PAID)->count(),
            ]),
            'stats' => [
                'revenue' => round((float) AiCreditPurchase::where('status', AiCreditPurchase::STATUS_PAID)->sum('total_amount'), 2),
                'recharges' => AiCreditPurchase::where('status', AiCreditPurchase::STATUS_PAID)->count(),
                'credits_spent' => (int) -AiCreditEntry::where('type', AiCreditEntry::TYPE_CALL)->sum('credits'),
                'outstanding' => (int) User::where('role', UserRole::Employer->value)->sum('ai_credits'),
            ],
            'ledger' => $ledger,
            'filters' => (object) array_filter(['q' => $q, 'type' => $type]),
            'types' => collect(AiCreditEntry::TYPES)->map(fn (string $t) => ['value' => $t, 'label' => AiCreditEntry::typeLabel($t)])->values(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'per_minute' => ['required', 'integer', 'min:1', 'max:1000'],
            'min_balance' => ['required', 'integer', 'min:0', 'max:100000'],
            'low_alert' => ['required', 'integer', 'min:0', 'max:100000'],
            'welcome' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        Setting::set(AiCredits::ENABLED_KEY, $data['enabled'] ? '1' : '0');
        Setting::set(AiCredits::PER_MINUTE_KEY, (string) $data['per_minute']);
        Setting::set(AiCredits::MIN_BALANCE_KEY, (string) $data['min_balance']);
        Setting::set(AiCredits::LOW_ALERT_KEY, (string) $data['low_alert']);
        Setting::set(AiCredits::WELCOME_KEY, (string) $data['welcome']);

        return back()->with('toast', ['type' => 'success', 'message' => __('AI credit settings saved.')]);
    }

    public function storePack(Request $request): RedirectResponse
    {
        AiCreditPack::create($this->validatePack($request));

        return back()->with('toast', ['type' => 'success', 'message' => __('Pack added.')]);
    }

    public function updatePack(Request $request, AiCreditPack $pack): RedirectResponse
    {
        $pack->update($this->validatePack($request));

        return back()->with('toast', ['type' => 'success', 'message' => __('Pack updated.')]);
    }

    /**
     * A pack someone bought is hidden rather than deleted, so its sales keep
     * their pack in the reports.
     */
    public function destroyPack(AiCreditPack $pack): RedirectResponse
    {
        if (AiCreditPurchase::where('ai_credit_pack_id', $pack->id)->exists()) {
            $pack->update(['is_active' => false]);

            return back()->with('toast', ['type' => 'success', 'message' => __('Pack hidden: it has sales, so it is kept for the records.')]);
        }

        $pack->delete();

        return back()->with('toast', ['type' => 'success', 'message' => __('Pack deleted.')]);
    }

    /**
     * Add (positive) or take (negative) credits on an employer account by
     * hand, with the reason the employer will see in their ledger.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employer' => ['required', 'string', 'max:150'],
            'credits' => ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        $employer = User::where('role', UserRole::Employer->value)
            ->where(fn (Builder $u) => $this->matchEmployer($u, $data['employer'], exact: true))
            ->first();

        if ($employer === null) {
            return back()->withErrors(['employer' => __('No employer with that phone, email or ID.')]);
        }

        $account = $employer->employerAccount();

        AiCredits::for($account)->record(
            $data['credits'] > 0 ? AiCreditEntry::TYPE_ADMIN_CREDIT : AiCreditEntry::TYPE_ADMIN_DEBIT,
            (int) $data['credits'],
            trim($data['reason']),
            by: $request->user(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => __(':name now has :balance credits.', [
                'name' => $account->employerProfile?->company_name ?: $account->name,
                'balance' => AiCredits::for($account)->balance(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePack(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'price' => ['required', 'numeric', 'min:1', 'max:1000000'],
            'credits' => ['required', 'integer', 'min:1', 'max:1000000'],
            'bonus_credits' => ['required', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
            'is_popular' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [...$data, 'price' => round((float) $data['price'], 2), 'sort_order' => $data['sort_order'] ?? 0];
    }

    /**
     * An employer by phone, email, account id or (loosely) name.
     *
     * @param  Builder<User>  $query
     */
    private function matchEmployer(Builder $query, string $q, bool $exact = false): void
    {
        $digits = preg_replace('/\D/', '', $q);

        $query->where(function (Builder $w) use ($q, $digits, $exact) {
            if ($digits !== '' && strlen($digits) >= 6) {
                $w->orWhere('phone', 'like', '%'.substr($digits, -10));
            }

            if (ctype_digit($q) && strlen($q) < 6) {
                $w->orWhere('id', (int) $q);
            }

            $w->orWhereRaw('LOWER(email) = ?', [mb_strtolower($q)]);

            if (! $exact) {
                $w->orWhereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])
                    ->orWhereHas('employerProfile', fn (Builder $p) => $p->whereRaw('LOWER(company_name) LIKE ?', ['%'.mb_strtolower($q).'%']));
            }
        });
    }
}
