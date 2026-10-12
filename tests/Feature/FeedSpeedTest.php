<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Support\CityCentres;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->worker->workerProfile()->create(['skills' => ['Weaving'], 'city' => 'Jaipur', 'available' => true]);

    $job = fn (string $title, float $lat, float $lng) => $this->employer->jobListings()->create([
        'title' => $title, 'description' => 'Work', 'category' => 'Weaving', 'skills' => ['Weaving'],
        'latitude' => $lat, 'longitude' => $lng, 'vacancies' => 1, 'contact_mode' => 'apply',
        'requires_worker_fee' => false, 'status' => JobStatus::Active,
    ]);

    $job('Jaipur weaver', 26.91, 75.79);
    $job('Varanasi weaver', 25.32, 82.97);
});

// ───────────────────────── One call per location change ─────────────────────────

it('sends the feed around a picked place back with the change', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['latitude' => 25.3176, 'longitude' => 82.9739, 'label' => 'Varanasi, Uttar Pradesh'])
        ->assertOk()
        ->assertJsonPath('location.mode', 'chosen')
        ->assertJsonPath('jobs.data.0.title', 'Varanasi weaver')
        ->assertJsonPath('jobs.feed.location', 'chosen')
        ->assertJsonPath('jobs.feed.location_label', 'Varanasi, Uttar Pradesh')
        ->assertJsonPath('jobs.meta.path', route('api.jobs'));
});

it('sends the feed around the phone back when going back to the current location', function () {
    $this->worker->workerProfile->update(['feed_location_label' => 'Varanasi', 'feed_latitude' => 25.31, 'feed_longitude' => 82.97]);

    $this->actingAs($this->worker, 'sanctum')
        ->deleteJson('/api/v1/worker/feed-location', ['lat' => 26.91, 'lng' => 75.79])
        ->assertOk()
        ->assertJsonPath('location.mode', 'current')
        ->assertJsonPath('jobs.data.0.title', 'Jaipur weaver')
        ->assertJsonPath('jobs.data.0.distance_km', 0)
        ->assertJsonPath('jobs.feed.location', 'current');
});

it('points the next page of a sent-back feed at the job list', function () {
    foreach (range(1, 15) as $i) {
        $this->employer->jobListings()->create([
            'title' => "Weaver {$i}", 'description' => 'Work', 'category' => 'Weaving', 'skills' => ['Weaving'],
            'latitude' => 26.9, 'longitude' => 75.8, 'vacancies' => 1, 'contact_mode' => 'apply',
            'requires_worker_fee' => false, 'status' => JobStatus::Active,
        ]);
    }

    $next = $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['latitude' => 26.91, 'longitude' => 75.79, 'label' => 'Jaipur, Rajasthan'])
        ->json('jobs.links.next');

    expect($next)->toStartWith(route('api.jobs'))->toContain('page=2');
});

it('keeps an unavailable karigar on the "switch it on" answer', function () {
    $this->worker->workerProfile->update(['available' => false]);

    $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['latitude' => 26.91, 'longitude' => 75.79, 'label' => 'Jaipur, Rajasthan'])
        ->assertOk()
        ->assertJsonPath('jobs.unavailable', true)
        ->assertJsonCount(0, 'jobs.data');
});

// ───────────────────────── Place search ─────────────────────────

it('answers a city name from the reference list without the map service', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake();

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=jaip')
        ->assertOk()
        ->assertJsonPath('places.0.label', 'Jaipur, Rajasthan')
        ->assertJsonPath('places.0.city', 'Jaipur')
        ->assertJsonPath('places.0.state', 'Rajasthan');

    Http::assertNothingSent();
});

it('narrows a city search by the state after a comma', function () {
    Http::fake();

    // Udaipur is a city in Rajasthan and in Tripura.
    $both = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=Udaipur')->json('places');
    $narrowed = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=Udaipur, Tri')->json('places');

    expect(collect($both)->pluck('state')->sort()->values()->all())->toBe(['Rajasthan', 'Tripura'])
        ->and(collect($narrowed)->pluck('label')->all())->toBe(['Udaipur, Tripura'])
        ->and(collect(CityCentres::search('udaipur tripura'))->pluck('label')->all())->toBe(['Udaipur, Tripura'])
        ->and(CityCentres::search('jaipur raj')[0]['label'] ?? null)->toBe('Jaipur, Rajasthan');
});

it('puts the exact city first, then cities with that word', function () {
    expect(collect(CityCentres::search('delhi'))->pluck('city')->take(2)->all())->toBe(['Delhi', 'New Delhi']);
});

it('sends Hindi text and localities to the map service', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/search*' => Http::response([])]);

    expect(CityCentres::search('जयपुर'))->toBe([])
        ->and(CityCentres::search('Vaishali Nagar'))->toBe([]);

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=Vaishali Nagar')->assertOk();
    Http::assertSentCount(1);
});

// ───────────────────────── Settings and tokens ─────────────────────────

it('caches a setting that has no row, and reads each setting once per request', function () {
    DB::enableQueryLog();

    expect(Setting::bool('some_unsaved_flag', true))->toBeTrue()
        ->and(Setting::bool('some_unsaved_flag', true))->toBeTrue();

    // The next request: memo gone, the cached "unset" answers it.
    app()->forgetScopedInstances();
    expect(Setting::bool('some_unsaved_flag', true))->toBeTrue();

    $settingQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'from "settings"'));
    expect($settingQueries)->toHaveCount(1);

    // Saving it is seen at once, in this request and the next.
    Setting::set('some_unsaved_flag', '0');
    expect(Setting::bool('some_unsaved_flag', true))->toBeFalse();
    app()->forgetScopedInstances();
    expect(Setting::bool('some_unsaved_flag', true))->toBeFalse();
});

it('writes a token\'s last use at most once a minute', function () {
    $token = $this->worker->createToken('phone');
    $plain = $token->plainTextToken;
    $stamp = fn () => $token->accessToken->fresh()->last_used_at;

    $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
    $first = $stamp();
    expect($first)->not->toBeNull();

    $this->travel(30)->seconds();
    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
    expect($stamp()->equalTo($first))->toBeTrue();

    $this->travel(31)->seconds();
    auth()->forgetGuards();
    $this->withToken($plain)->getJson('/api/v1/auth/me')->assertOk();
    expect($stamp()->greaterThan($first))->toBeTrue();
});
