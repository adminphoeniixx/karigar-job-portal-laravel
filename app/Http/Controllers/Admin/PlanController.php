<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\Billing\Gst;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Plans', [
            'plans' => Plan::orderBy('type', 'desc')->orderBy('price')->get(['id', 'name', 'slug', 'type', 'price', 'interval', 'features', 'is_active']),
            'gstPercent' => Gst::percent(),
        ]);
    }

    /**
     * Edit a plan's price and limits. The price is before GST. Razorpay plans
     * cannot be edited, so a new price does not touch Razorpay here: the next
     * checkout on this plan sees its Razorpay plan charging the old amount and
     * creates a new one (RazorpayService::ensurePlan). Subscriptions already
     * running stay on the one they started with.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
            'price' => ['sometimes', 'numeric', 'min:1', 'max:1000000'],
            'job_post_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'contact_unlock_limit' => ['required', 'integer', 'min:0', 'max:1000000'],
            'contact_database_limit' => ['required', 'integer', 'min:0', 'max:10000000'],
            'featured' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $features = $plan->features ?? [];
        $features['job_post_limit'] = $data['job_post_limit'];
        $features['contact_unlock_limit'] = $data['contact_unlock_limit'];
        $features['contact_database_limit'] = $data['contact_database_limit'];
        $features['featured'] = $data['featured'];

        $plan->update([
            'price' => isset($data['price']) ? round((float) $data['price'], 2) : $plan->price,
            'features' => $features,
            'is_active' => $data['is_active'],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Plan updated.')]);
    }
}
