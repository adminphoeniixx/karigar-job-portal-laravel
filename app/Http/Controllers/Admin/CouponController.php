<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DiscountType;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Coupons', [
            'coupons' => Coupon::withCount('redemptions')->latest()->get(),
            'plans' => Plan::orderBy('price')->get(['id', 'name', 'price']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        Coupon::create($data);

        return back()->with('toast', ['type' => 'success', 'message' => __('Coupon created.')]);
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $data = $this->validated($request, $coupon);
        $coupon->update($data);

        return back()->with('toast', ['type' => 'success', 'message' => __('Coupon updated.')]);
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->delete();

        return back()->with('toast', ['type' => 'success', 'message' => __('Coupon deleted.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'discount_type' => ['required', new Enum(DiscountType::class)],
            'discount_value' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'min_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'razorpay_offer_id' => ['nullable', 'string', 'max:255'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => ['integer', 'exists:plans,id'],
            'max_redemptions' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['required', 'integer', 'min:0', 'max:1000'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['required', 'boolean'],
        ]);

        // A percentage over 100 makes no sense.
        if ($data['discount_type'] === DiscountType::Percent->value && $data['discount_value'] > 100) {
            $data['discount_value'] = 100;
        }

        $data['code'] = strtoupper(trim($data['code']));
        $data['plan_ids'] = ! empty($data['plan_ids']) ? array_values($data['plan_ids']) : null;

        return $data;
    }
}
