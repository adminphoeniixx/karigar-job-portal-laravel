<?php

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Geocoder;
use App\Support\LocateByCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The collection engine searches the database, so the list has rows.
    config(['scout.driver' => 'collection', 'services.geocoder.enabled' => true]);

    Http::fake([
        '*' => fn ($request) => Http::response(match ($request->data()['city'] ?? null) {
            'Delhi' => [['lat' => '28.6139', 'lon' => '77.2090']],
            'Jaipur' => [['lat' => '26.9124', 'lon' => '75.7873']],
            default => [],
        }),
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Jaipur Looms', 'city' => 'Jaipur', 'state' => 'Rajasthan']);

    $plan = Plan::create([
        'name' => 'Db', 'slug' => 'db', 'type' => Plan::TYPE_DATABASE, 'price' => 299, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 5, 'contact_database_limit' => 1000], 'is_active' => true,
    ]);
    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->karigar = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Nitin']);
    $this->profile = $this->karigar->workerProfile()->create(['city' => 'Delhi', 'state' => 'Delhi', 'skills' => ['Carpentry'], 'available' => true]);
});

it('places karigars and employers without a map pin at their city centre', function () {
    $counts = LocateByCity::run(app(Geocoder::class));

    expect($counts['workers'])->toBe(1)
        ->and($counts['employers'])->toBe(1)
        ->and((float) $this->profile->fresh()->latitude)->toBe(28.6139)
        ->and((float) $this->employer->employerProfile->fresh()->longitude)->toBe(75.7873);
});

it('measures distance from the employer when the app sends no point', function () {
    LocateByCity::run(app(Geocoder::class));

    // A search word, since the collection engine reads `*` literally.
    $row = $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/workers?q=Delhi')
        ->assertOk()
        ->json('workers.data.0');

    // Jaipur to Delhi, straight line.
    expect($row['name'])->toBe('Nitin')
        ->and($row['distance_km'])->toBeGreaterThan(230)->toBeLessThan(250);
});

it('falls back to the cached city centre for a karigar without a pin', function () {
    // Only the cache knows Delhi; the karigar row itself has no coordinates.
    app(Geocoder::class)->cityCentre('Delhi', 'Delhi');
    $this->employer->employerProfile->update(['latitude' => 26.9124, 'longitude' => 75.7873]);

    $row = $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/workers?q=Delhi')
        ->json('workers.data.0');

    expect($this->profile->fresh()->latitude)->toBeNull()
        ->and($row['distance_km'])->toBeGreaterThan(230);
});
