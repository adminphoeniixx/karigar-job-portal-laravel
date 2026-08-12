<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy policy. Public, and reachable without an account — a visitor has
 * to be able to read what we collect *before* they hand any of it over.
 */
class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        // Matches the landing page: this is public-facing editorial, written
        // light, and carries no dark: variants of its own.
        View::share('appearance', 'light');

        return Inertia::render('Privacy', [
            // Rendered rather than hard-coded so the page never contradicts the
            // admin switch — with verification off there is no KYC to disclose.
            'kycEnabled' => \App\Models\Setting::bool('kyc_verification_enabled', true),
            'updatedAt' => '12 August 2026',
        ]);
    }
}
