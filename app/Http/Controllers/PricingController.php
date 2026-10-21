<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\AiCredits;
use App\Services\Billing\Gst;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public price list for employers: job plans, database plans and AI call
 * credit packs. Anyone can read it; "Buy" signs an employer in (or up) with
 * the mobile OTP and drops them on the checkout for that exact plan.
 */
class PricingController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Public-facing editorial, written light like the landing page.
        View::share('appearance', 'light');

        $user = $request->user();

        return Inertia::render('Pricing', [
            'plans' => Plan::where('is_active', true)->orderBy('price')->get()
                ->map(fn (Plan $plan) => [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'type' => $plan->type,
                    'price' => (float) $plan->price,
                    'price_with_gst' => Gst::gross((float) $plan->price),
                    'interval_label' => $plan->interval_label,
                    'featured' => $plan->isRecommended(),
                    'feature_list' => $plan->featureList(),
                ]),
            'packs' => AiCredits::enabled() ? AiCredits::packs() : [],
            'creditRules' => AiCredits::enabled() ? AiCredits::rules() : [],
            'gstPercent' => Gst::percent(),
            // Who is looking decides where "Buy" goes: guests sign in first,
            // employers go straight to checkout, karigars are told it is for employers.
            'viewer' => match (true) {
                $user === null => 'guest',
                $user->isEmployer() => 'employer',
                default => 'other',
            },
            // Team members buy through the account owner, the same rule checkout enforces.
            'canBuyCredits' => $user === null || $user->id === $user->employerAccount()->id,
        ]);
    }
}
