<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MobileApps;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two checks both apps make on launch, before sign-in: is an update
 * available or required, and is the service under maintenance. Public, and
 * still answered during maintenance.
 */
class AppStatusController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app' => ['required', 'string', 'in:'.implode(',', MobileApps::APPS)],
            'platform' => ['required', 'string', 'in:'.implode(',', MobileApps::PLATFORMS)],
            // The installed version, e.g. 1.4.2. A build suffix ("+37") is
            // ignored; an unencoded "+" arrives as a space, so that is too.
            'version' => ['nullable', 'string', 'max:32', 'regex:/^v?\d+(\.\d+){0,3}([+\- ][0-9A-Za-z.]+)?$/'],
        ]);

        return response()->json(MobileApps::update($data['app'], $data['platform'], $data['version'] ?? null));
    }

    public function maintenance(): JsonResponse
    {
        return response()->json(MobileApps::maintenance());
    }
}
