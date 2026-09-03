<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\HelpCentre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Help & Support" for the apps: the common questions, and the ways to reach a
 * person. Public, so someone who cannot sign in can still find help.
 */
class SupportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'audience' => ['nullable', 'string', 'in:'.implode(',', HelpCentre::AUDIENCES)],
        ]);

        return response()->json([
            'channels' => HelpCentre::channels(),
            'faqs' => HelpCentre::faqs($filters['audience'] ?? null),
        ]);
    }
}
