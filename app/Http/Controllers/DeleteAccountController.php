<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How to delete your Super Karigar account. Public and unauthenticated for the
 * same reason as the privacy policy — app stores require a deletion route that
 * someone can reach without signing in, and a person who has lost access to
 * their number needs to read it precisely when they cannot log in.
 */
class DeleteAccountController extends Controller
{
    public function __invoke(): Response
    {
        // Public-facing editorial, written light, like the landing page and the
        // privacy policy — it carries no dark: variants of its own.
        View::share('appearance', 'light');

        return Inertia::render('DeleteAccount', [
            // With verification switched off there are no identity documents to
            // promise the deletion of.
            'kycEnabled' => Setting::bool('kyc_verification_enabled', true),
            'updatedAt' => '12 August 2026',
        ]);
    }
}
