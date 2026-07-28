<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the KYC/verification routes while the feature is switched off in
 * admin settings (Settings > Verification). Returns a 404 rather than a 403 so
 * a disabled feature looks like it simply does not exist — the apps hide their
 * KYC screens off the same flag, exposed as `verification_enabled`.
 */
class EnsureKycEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Setting::bool('kyc_verification_enabled', true), 404);

        return $next($request);
    }
}
