<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\Gst;
use App\Support\PricingPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The plan catalogue, shaped entirely from here: name, tagline, price and
 * struck-out MRP, how long a plan runs, how long its jobs stay live, and what
 * it grants. The public /pricing page, /subscription and the apps all read
 * these rows as they are.
 */
class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Plans', [
            'plans' => Plan::orderBy('type', 'desc')->orderBy('is_active', 'desc')->orderBy('price')->withCount('subscriptions')->get([
                'id', 'name', 'tagline', 'slug', 'type', 'price', 'mrp', 'interval', 'duration_days', 'job_live_days', 'features', 'is_active',
            ])->each(fn (Plan $plan) => $plan->setAttribute('auto_feature_list', $plan->limitFeatureList())),
            'gstPercent' => Gst::percent(),
            'pageText' => PricingPage::content(),
            'pageDefaults' => PricingPage::defaults(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, creating: true);

        $plan = new Plan([
            'type' => $data['type'],
            'slug' => $this->uniqueSlug($data['name']),
            'currency' => 'INR',
            'interval' => Plan::INTERVAL_DAYS,
        ]);

        $this->fill($plan, $data)->save();

        return back()->with('toast', ['type' => 'success', 'message' => __('Plan added.')]);
    }

    /**
     * Edit a plan. The price is before GST. Razorpay plans cannot be edited,
     * so a new price or length does not touch Razorpay here: the next checkout
     * on this plan makes a new Razorpay plan (RazorpayService::ensurePlan).
     * Subscriptions already running stay on the one they started with.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validated($request, creating: false);

        // A Razorpay plan bills every so many days; a new length needs a new one.
        if (isset($data['duration_days']) && $plan->interval === Plan::INTERVAL_DAYS && (int) $data['duration_days'] !== $plan->durationDays()) {
            $plan->razorpay_plan_id = null;
            $plan->razorpay_amount = null;
        }

        $this->fill($plan, $data)->save();

        return back()->with('toast', ['type' => 'success', 'message' => __('Plan updated.')]);
    }

    /**
     * Delete a plan nobody has bought. One with subscriptions, even ended
     * ones, is kept for their invoices and order history; hide it instead.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->exists()) {
            return back()->with('toast', ['type' => 'error', 'message' => __('This plan has been bought before, so it stays for its invoices. Untick Active to hide it.')]);
        }

        $plan->delete();

        return back()->with('toast', ['type' => 'success', 'message' => __('Plan deleted.')]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => [$creating ? 'required' : 'prohibited', 'in:'.Plan::TYPE_JOB.','.Plan::TYPE_DATABASE],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'tagline' => ['nullable', 'string', 'max:120'],
            'price' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:1', 'max:1000000'],
            // The struck-out price; blank or not above the price shows none.
            'mrp' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            // Razorpay bills a daily plan no more often than every 7 days.
            'duration_days' => [$creating ? 'required' : 'sometimes', 'integer', 'min:7', 'max:3650'],
            'job_live_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'job_post_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'contact_unlock_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'contact_database_limit' => ['required', 'integer', 'min:0', 'max:10000000'],
            // AI call credits added to the wallet with each payment.
            'ai_call_credits' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'featured' => ['required', 'boolean'],
            'badge' => ['nullable', 'string', 'max:30'],
            // Blank keeps the lines built from the limits above.
            'highlights' => ['nullable', 'array', 'max:12'],
            'highlights.*' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Plan $plan, array $data): Plan
    {
        $features = $plan->features ?? [];
        // A database plan posts no jobs.
        $features['job_post_limit'] = $plan->type === Plan::TYPE_DATABASE ? 0 : $data['job_post_limit'];
        $features['contact_unlock_limit'] = $data['contact_unlock_limit'];
        $features['contact_database_limit'] = $data['contact_database_limit'];
        $features['ai_call_credits'] = (int) ($data['ai_call_credits'] ?? 0);
        $features['featured'] = $data['featured'];
        $features['badge'] = trim((string) ($data['badge'] ?? '')) ?: null;
        $features['highlights'] = array_values(array_filter(array_map('trim', $data['highlights'] ?? []), 'strlen'));

        $plan->fill([
            'features' => $features,
            'is_active' => $data['is_active'],
            ...array_intersect_key($data, array_flip(['name', 'tagline', 'mrp'])),
        ]);

        if (isset($data['price'])) {
            $plan->price = round((float) $data['price'], 2);
        }

        // Monthly and yearly plans from before keep their calendar period.
        if (isset($data['duration_days']) && $plan->interval !== Plan::INTERVAL_MONTHLY && $plan->interval !== Plan::INTERVAL_YEARLY) {
            $plan->interval = Plan::INTERVAL_DAYS;
            $plan->duration_days = (int) $data['duration_days'];
        }

        if ($plan->type === Plan::TYPE_JOB && array_key_exists('job_live_days', $data)) {
            $plan->job_live_days = $data['job_live_days'];
        }

        return $plan;
    }

    /**
     * The words on the public /pricing page around the plans: masthead,
     * section headings, FAQ, closing block. A field left blank falls back
     * to the built-in text.
     */
    public function updatePage(Request $request): RedirectResponse
    {
        $text = collect(PricingPage::defaults())->except('faqs')
            ->map(fn ($default, $key) => ['nullable', 'string', 'max:'.(in_array($key, PricingPage::LONG, true) ? 600 : 160)])
            ->all();

        $data = $request->validate([
            ...$text,
            'faqs' => ['present', 'array', 'max:20'],
            'faqs.*.q' => ['required', 'string', 'max:200'],
            'faqs.*.a' => ['required', 'string', 'max:1500'],
        ]);

        PricingPage::save($data);

        return back()->with('toast', ['type' => 'success', 'message' => __('Pricing page updated.')]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plan';
        $slug = $base;

        for ($i = 2; Plan::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
