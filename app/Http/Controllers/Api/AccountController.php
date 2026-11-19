<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountDeletionRefused;
use App\Http\Controllers\Controller;
use App\Services\AccountDeletion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    /**
     * What deleting the account would take with it, for the app's confirm
     * sheet: running plans and their AutoPay, AI credits, jobs, team,
     * applications, and whether this login may be deleted at all.
     */
    public function deletion(Request $request, AccountDeletion $deletion): JsonResponse
    {
        return response()->json($deletion->summary($request->user()));
    }

    /**
     * Permanently delete the signed-in account, karigar or employer.
     *
     * App users sign in with OTP only (no password), so deletion is guarded by
     * an explicit confirmation flag rather than a password re-entry.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        $user = $request->user();

        if (($reason = $deletion->blocker($user)) !== null) {
            return response()->json(['message' => AccountDeletion::blockerMessage($reason), 'code' => $reason], 403);
        }

        try {
            $deletion->delete($user);
        } catch (AccountDeletionRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->reason], 409);
        }

        return response()->json(['message' => __('Your account has been deleted.')]);
    }
}
