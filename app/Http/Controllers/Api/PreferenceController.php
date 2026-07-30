<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * App-level toggles from the Settings screen (theme, alert opt-outs). Stored as
 * a JSON blob on the user so the app keeps them across devices.
 */
class PreferenceController extends Controller
{
    /** Defaults applied when the user has never saved a preference. */
    private const DEFAULTS = [
        'theme' => 'system',              // system | light | dark
        'applicant_alerts' => true,       // employer: new-application pushes
        'job_alerts' => true,             // worker: matching-job pushes
        'message_alerts' => true,
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => array_merge(self::DEFAULTS, $request->user()->preferences ?? []),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['nullable', 'string', 'in:system,light,dark'],
            'applicant_alerts' => ['nullable', 'boolean'],
            'job_alerts' => ['nullable', 'boolean'],
            'message_alerts' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $preferences = array_merge(self::DEFAULTS, $user->preferences ?? [], $data);

        $user->update(['preferences' => $preferences]);

        return response()->json([
            'message' => __('Preferences saved.'),
            'preferences' => $preferences,
        ]);
    }
}
