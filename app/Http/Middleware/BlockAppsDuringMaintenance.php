<?php

namespace App\Http\Middleware;

use App\Support\MobileApps;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While maintenance is switched on in Admin → Settings, the app API answers
 * 503 with code "maintenance", so an app that is already open shows its
 * maintenance screen instead of failing request by request. The launch checks,
 * the legal and help pages and the webhooks keep working.
 */
class BlockAppsDuringMaintenance
{
    private const OPEN = ['api.app.*', 'api.legal', 'api.legal.show', 'api.support', 'api.webhooks.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! MobileApps::underMaintenance() || $request->routeIs(...self::OPEN)) {
            return $next($request);
        }

        return response()->json([
            ...MobileApps::maintenance(),
            'code' => 'maintenance',
        ], 503);
    }
}
