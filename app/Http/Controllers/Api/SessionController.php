<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Login & security → device sessions": the API tokens issued to this user's
 * phones, and the ability to sign a device out remotely.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $current = $request->user()->currentAccessToken();

        return response()->json([
            'sessions' => $request->user()->tokens()
                ->latest('last_used_at')
                ->get()
                ->map(fn (PersonalAccessToken $token) => [
                    'id' => $token->id,
                    'device' => $token->name,
                    'current' => $current !== null && $token->id === $current->id,
                    'last_used_ago' => $token->last_used_at?->diffForHumans(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * Revoke one device's token. Revoking the current one logs this app out.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $token)->delete();

        abort_if($deleted === 0, 404);

        return response()->json(['message' => __('Device signed out.')]);
    }
}
