<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'teamRole' => $user?->isEmployer() ? $user->teamRole() : null,
            ],
            'notifications' => $user ? [
                'unread' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(8)->get()->map(fn ($n) => [
                    'id' => $n->id,
                    'data' => $n->data,
                    'read' => $n->read_at !== null,
                    'created_at' => $n->created_at?->diffForHumans(),
                ]),
            ] : null,
            'categories' => Cache::rememberForever('categories.active', fn () => Category::activeNames()),
            // Admin-controlled feature flags; the sidebar and dashboard drop
            // their KYC entries when verification is switched off.
            'features' => [
                'verification_enabled' => Setting::bool('kyc_verification_enabled', true),
            ],
            'locale' => app()->getLocale(),
            'supportedLocales' => [
                'en' => 'English',
                'hi' => 'हिन्दी',
                'hinglish' => 'Hinglish',
                'mr' => 'मराठी',
                'bn' => 'বাংলা',
                'ta' => 'தமிழ்',
                'te' => 'తెలుగు',
                'gu' => 'ગુજરાતી',
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
