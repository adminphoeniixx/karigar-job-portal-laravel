<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * Permanently delete the authenticated user's account.
     *
     * Workers sign in with OTP only (no password), so deletion is guarded by an
     * explicit confirmation flag rather than a password re-entry. All of the
     * user's API tokens are revoked before the record is removed.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        $user = $request->user();
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => __('Your account has been deleted.')]);
    }
}
