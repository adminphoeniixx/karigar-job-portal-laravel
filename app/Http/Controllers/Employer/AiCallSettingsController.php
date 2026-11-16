<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Services\Screening\CallPreferences;
use App\Services\Screening\ScreeningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The employer's own rules for AI calls: when applicants are rung, in which
 * hours and on which days, who qualifies, the daily cap per job, and the
 * reminder call on interview day. Account-wide; team members share them.
 */
class AiCallSettingsController extends Controller
{
    public function edit(Request $request, ScreeningService $screening): Response
    {
        return Inertia::render('employer/AiCallSettings', [
            'settings' => CallPreferences::for($request->user())->toArray(),
            'options' => CallPreferences::options(),
            'callsOn' => $screening->autoEnabled() && $screening->agent()->configured(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(CallPreferences::rules());

        CallPreferences::fromArray($data)->save($request->user());

        return back()->with('toast', ['type' => 'success', 'message' => __('AI call settings saved.')]);
    }
}
