<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('starts on the current location', function () {
    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/feed-location')
        ->assertOk()
        ->assertJsonPath('location.mode', 'current')
        ->assertJsonPath('location.label', null);
});

it('shows jobs around a picked place, even when the phone is elsewhere', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['latitude' => 25.3176, 'longitude' => 82.9739, 'label' => 'Varanasi, Uttar Pradesh'])
        ->assertOk()
        ->assertJsonPath('location.mode', 'chosen')
        ->assertJsonPath('location.label', 'Varanasi, Uttar Pradesh');

    // The phone says Jaipur; the picked place still wins.
    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs?lat=26.91&lng=75.79')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Varanasi weaver')
        ->assertJsonPath('feed.location', 'chosen')
        ->assertJsonPath('feed.location_label', 'Varanasi, Uttar Pradesh');

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/dashboard')
        ->assertJsonPath('feed_location.mode', 'chosen')
        ->assertJsonPath('latest_jobs.0.title', 'Varanasi weaver');
});

it('goes back to the current location', function () {
    $this->worker->workerProfile->update(['feed_location_label' => 'Varanasi', 'feed_latitude' => 25.31, 'feed_longitude' => 82.97]);

    $this->actingAs($this->worker, 'sanctum')->deleteJson('/api/v1/worker/feed-location')
        ->assertOk()
        ->assertJsonPath('location.mode', 'current');

    $this->actingAs($this->worker->fresh(), 'sanctum')->getJson('/api/v1/jobs?lat=26.91&lng=75.79')
        ->assertJsonPath('data.0.title', 'Jaipur weaver')
        ->assertJsonPath('feed.location', 'current');
});

it('names a dropped pin itself when no label is sent', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/reverse*' => Http::response([
        'lat' => '26.82', 'lon' => '75.80',
        'address' => ['suburb' => 'Sanganer', 'city' => 'Jaipur', 'state' => 'Rajasthan'],
    ])]);

    $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['latitude' => 26.82, 'longitude' => 75.80])
        ->assertOk()
        ->assertJsonPath('location.label', 'Sanganer, Jaipur, Rajasthan');
});

it('finds places for the search box', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/search*' => Http::response([
        ['lat' => '26.82', 'lon' => '75.80', 'address' => ['suburb' => 'Sanganer', 'city' => 'Jaipur', 'state' => 'Rajasthan']],
    ])]);

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=Sanganer')
        ->assertOk()
        ->assertJsonPath('places.0.label', 'Sanganer, Jaipur, Rajasthan')
        ->assertJsonPath('places.0.city', 'Jaipur')
        ->assertJsonPath('places.0.latitude', 26.82);
});

it('rejects a place with no coordinates', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->putJson('/api/v1/worker/feed-location', ['label' => 'Somewhere'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['latitude', 'longitude']);
});
