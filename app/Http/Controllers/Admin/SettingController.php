<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreApplication;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'first_post_free_enabled' => Setting::bool('first_post_free_enabled', true),
                'kyc_verification_enabled' => Setting::bool('kyc_verification_enabled', true),
                'ai_auto_shortlist_enabled' => Setting::bool(ScoreApplication::ENABLED_KEY, false),
                'ai_auto_shortlist_threshold' => Setting::int(
                    ScoreApplication::THRESHOLD_KEY,
                    ScoreApplication::DEFAULT_THRESHOLD,
                ),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_post_free_enabled' => ['required', 'boolean'],
            'kyc_verification_enabled' => ['required', 'boolean'],
            'ai_auto_shortlist_enabled' => ['required', 'boolean'],
            // Below 40 the model calls a candidate a "weak" match, so allow the
            // admin to be lenient but not to shortlist literally everyone.
            'ai_auto_shortlist_threshold' => ['required', 'integer', 'min:40', 'max:100'],
        ]);

        Setting::set('first_post_free_enabled', $data['first_post_free_enabled'] ? '1' : '0');
        Setting::set('kyc_verification_enabled', $data['kyc_verification_enabled'] ? '1' : '0');
        Setting::set(ScoreApplication::ENABLED_KEY, $data['ai_auto_shortlist_enabled'] ? '1' : '0');
        Setting::set(ScoreApplication::THRESHOLD_KEY, (string) $data['ai_auto_shortlist_threshold']);

        return back()->with('toast', ['type' => 'success', 'message' => __('Settings updated.')]);
    }
}
