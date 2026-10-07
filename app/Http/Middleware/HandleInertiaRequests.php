<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Support\Chat;
use App\Support\Verification;
use Illuminate\Http\Request;
use Inertia\Inertia;
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

        $this->forwardSessionToasts($request);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // The registered entity behind the brand — footers and the privacy
            // page print it, so every public page gets it without asking.
            'company' => config('company'),
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
            // Unread chat messages — drives the Messages badge in the sidebar.
            // Deferred to render time: share() runs before the controller, and
            // opening a thread marks it read.
            'chatUnread' => fn () => $user ? app(Chat::class)->unreadTotal($user) : 0,
            'categories' => Category::cachedActiveNames(),
            // Admin-controlled feature flags; the sidebar and dashboard drop
            // their KYC entries when verification is switched off.
            'features' => [
                'verification_enabled' => Verification::enabledFor($user),
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

    /**
     * Controllers flash toasts the Laravel way (`->with('toast', [...])`, and
     * a few `->with('success'|'error', '...')`), but Inertia only sends the
     * page what went through Inertia::flash(). Without this those messages,
     * "Invalid coupon code." among them, never reached the screen.
     */
    private function forwardSessionToasts(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();

        $toast = match (true) {
            is_array($session->get('toast')) => $session->get('toast'),
            is_string($session->get('error')) => ['type' => 'error', 'message' => $session->get('error')],
            is_string($session->get('success')) => ['type' => 'success', 'message' => $session->get('success')],
            default => null,
        };

        if ($toast !== null && ! isset(Inertia::getFlashed($request)['toast'])) {
            Inertia::flash('toast', $toast);
        }
    }
}
