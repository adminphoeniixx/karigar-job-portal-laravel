<?php

namespace App\Http\Middleware;

use App\Support\MobileApps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While an app is in maintenance (Admin → Settings → Mobile apps), its API
 * calls answer 503 with code "maintenance", so an app that is already open
 * shows its maintenance screen instead of failing request by request. The
 * other app keeps working. The launch checks, the legal and help pages and the
 * webhooks stay open.
 *
 * The calling app comes from MobileApps::appFor(). A guest call that names no
 * app is refused only while both apps are down.
 */
class BlockAppsDuringMaintenance
{
    private const OPEN = ['api.app.*', 'api.legal', 'api.legal.show', 'api.support', 'api.webhooks.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        $app = MobileApps::appFor($request) ?? $this->appIfAllDown();

        if ($app === null || ! MobileApps::underMaintenance($app)) {
            return $next($request);
        }

        return response()->json([
            ...MobileApps::maintenance($app),
            'code' => 'maintenance',
        ], 503);
    }

    private function appIfAllDown(): ?string
    {
        foreach (MobileApps::APPS as $app) {
            if (! MobileApps::underMaintenance($app)) {
                return null;
            }
        }

        return MobileApps::APPS[0];
    }
}
