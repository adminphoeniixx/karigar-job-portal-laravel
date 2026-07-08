<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Plans', [
            'plans' => Plan::orderBy('price')->get(['id', 'name', 'slug', 'price', 'interval', 'features', 'is_active']),
        ]);
    }

    /**
     * Edit a plan's app-enforced limits. Note: price/interval are set at Razorpay
     * plan creation and are not changed here — only the entitlement limits are.
     */
    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $request->validate([
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
            'features' => $features,
            'is_active' => $data['is_active'],
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => __('Plan updated.')]);
    }
}
