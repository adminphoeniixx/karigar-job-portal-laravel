<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    /**
     * Register (or refresh) the current device's FCM token. Called by the app
     * after login and whenever FCM rotates the token.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['nullable', 'string', 'in:android,ios,web'],
        ]);

        // A token is globally unique to one device, so claim it for this user.
        DeviceToken::updateOrCreate(
            ['token' => $data['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $data['platform'] ?? null,
                'last_used_at' => now(),
            ],
        );

        return response()->json(['registered' => true]);
    }

    /**
     * Remove the device's token, e.g. on logout so it stops receiving pushes.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        $request->user()->deviceTokens()->where('token', $data['token'])->delete();

        return response()->json(['removed' => true]);
    }
}
