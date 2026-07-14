<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Persist the worker's preferred app language on their account.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(SetLocale::SUPPORTED)],
        ]);

        $request->user()->update(['locale' => $data['locale']]);

        return response()->json([
            'locale' => $data['locale'],
            'supported' => SetLocale::SUPPORTED,
        ]);
    }
}
