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
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\Filesystem;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNClient;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion;

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

        // The 'private' disk (resumes, KYC documents) on BunnyCDN storage.
        Storage::extend('bunnycdn', function ($app, array $config) {
            $adapter = new BunnyCDNAdapter(
                new BunnyCDNClient((string) $config['storage_zone'], (string) $config['api_key'], (string) ($config['region'] ?? '') ?: BunnyCDNRegion::DEFAULT),
                root: (string) ($config['root'] ?? ''),
            );

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });

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
