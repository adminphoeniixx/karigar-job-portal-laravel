<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Services\Screening\CallPreferences;
use App\Services\Screening\ScreeningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET/PUT /employer/ai-call-settings: the account's rules for AI calls, and
 * the choices the screen offers (with labels, so the app shows the same
 * words as the web).
 */
class AiCallSettingsController extends Controller
{
    public function show(Request $request, ScreeningService $screening): JsonResponse
    {
        return $this->payload($request, $screening);
    }

    public function update(Request $request, ScreeningService $screening): JsonResponse
    {
        $data = $request->validate(CallPreferences::rules());

        CallPreferences::fromArray($data)->save($request->user());

        return $this->payload($request, $screening, __('AI call settings saved.'));
    }

    private function payload(Request $request, ScreeningService $screening, ?string $message = null): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $message,
            'settings' => CallPreferences::for($request->user())->toArray(),
            'options' => CallPreferences::options(),
            'calls_on' => $screening->autoEnabled() && $screening->agent()->configured(),
        ], fn ($v) => $v !== null));
    }
}
