<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasActiveSubscription()) {
            return redirect()->route('subscription.pricing')->with('toast', [
                'type' => 'error',
                'message' => __('An active subscription is required to post jobs.'),
            ]);
        }

        return $next($request);
    }
}
