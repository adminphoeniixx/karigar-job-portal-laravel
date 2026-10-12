<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Services\Calling\AgoraProvider;
use App\Services\Calling\CallProvider;
use App\Services\Calling\StubProvider;
use App\Services\Screening\LiveKitVoiceAgent;
use App\Services\Screening\StubVoiceAgent;
use App\Services\Screening\VoiceAgent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Voice-call transport, chosen by config/calling.php. Everything else
        // in the calling stack talks to the interface, never to Agora.
        $this->app->singleton(CallProvider::class, fn (): CallProvider => match (config('calling.provider')) {
            'stub' => new StubProvider,
            default => new AgoraProvider,
        });

        // Telephony + speech stack behind automated screening calls. Defaults
        // to the stub, so deploying without credentials dials nobody.
        $this->app->singleton(VoiceAgent::class, fn (): VoiceAgent => match (config('screening.provider')) {
            'livekit' => new LiveKitVoiceAgent,
            default => new StubVoiceAgent,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Writes last_used_at once a minute instead of on every API call.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
