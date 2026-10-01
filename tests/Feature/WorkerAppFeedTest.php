<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\KycStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\WageConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'name' => 'Jaipur Looms']);
    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->worker->workerProfile()->create(['skills' => ['Weaving'], 'city' => 'Jaipur', 'state' => 'Rajasthan']);
});

function feedJob(User $employer, string $title, array $attributes = []): JobListing
{
    return $employer->jobListings()->create([
        'title' => $title, 'description' => 'Craft work', 'category' => 'Weaving',
        'status' => JobStatus::Active->value, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        ...$attributes,
    ]);
}

function feedTitles($response): array
{
    return collect($response->json('data'))->pluck('title')->all();
}

// ───────────────────────── CATEGORY ─────────────────────────

it('shows a karigar only the jobs in their own categories', function () {
    feedJob($this->employer, 'Loom weaver');
    feedJob($this->employer, 'Weaving helper at a pottery', ['category' => 'Pottery / Handmade Pots', 'skills' => ['Weaving']]);
    feedJob($this->employer, 'Potter', ['category' => 'Pottery / Handmade Pots']);

    $response = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')->assertOk();

    expect(feedTitles($response))->toEqualCanonicalizing(['Loom weaver', 'Weaving helper at a pottery'])
        ->and($response->json('feed.type'))->toBe('for_you')
        ->and($response->json('feed.categories'))->toBe(['Weaving']);
});

it('matches categories whatever their case', function () {
    $this->worker->workerProfile->update(['skills' => ['weaving']]);
    feedJob($this->employer, 'Loom weaver');

    expect(feedTitles($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')))->toBe(['Loom weaver']);
});

it('shows every job to a karigar with no skills, or when asked for all', function () {
    feedJob($this->employer, 'Loom weaver');
    feedJob($this->employer, 'Potter', ['category' => 'Pottery / Handmade Pots']);

    expect(feedTitles($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs?all=1')))->toHaveCount(2);

    $this->worker->workerProfile->update(['skills' => []]);

    expect(feedTitles($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')))->toHaveCount(2);
});

// ───────────────────────── LOCATION ─────────────────────────

it('puts the nearest jobs first from the phone position', function () {
    // Karigar in Jaipur; jobs in Jaipur, Ajmer (~130 km), Delhi (~240 km), and one with no pin.
    feedJob($this->employer, 'Delhi', ['latitude' => 28.6139, 'longitude' => 77.2090]);
    feedJob($this->employer, 'No pin');
    feedJob($this->employer, 'Jaipur', ['latitude' => 26.9124, 'longitude' => 75.7873]);
    feedJob($this->employer, 'Ajmer', ['latitude' => 26.4499, 'longitude' => 74.6399]);

    $response = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs?lat=26.92&lng=75.79');
    $distances = collect($response->json('data'))->pluck('distance_km', 'title');

    expect(feedTitles($response))->toBe(['Jaipur', 'Ajmer', 'Delhi', 'No pin'])
        ->and($distances['Jaipur'])->toBeLessThan(2)
        ->and($distances['Ajmer'])->toBeGreaterThan(110)->toBeLessThan(150)
        ->and($distances['Delhi'])->toBeGreaterThan(210)->toBeLessThan(270)
        ->and($distances['No pin'])->toBeNull()
        ->and($response->json('feed.location'))->toBe('current');
});

it('keeps to a radius when one is given', function () {
    feedJob($this->employer, 'Jaipur', ['latitude' => 26.9124, 'longitude' => 75.7873]);
    feedJob($this->employer, 'Delhi', ['latitude' => 28.6139, 'longitude' => 77.2090]);

    expect(feedTitles($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs?lat=26.92&lng=75.79&radius=50')))->toBe(['Jaipur']);
});

it('falls back to the saved location, then to the city', function () {
    feedJob($this->employer, 'Delhi', ['latitude' => 28.6139, 'longitude' => 77.2090, 'city' => 'Delhi', 'state' => 'Delhi']);
    feedJob($this->employer, 'Jaipur', ['latitude' => 26.9124, 'longitude' => 75.7873]);

    // No position sent, none saved: same city first.
    $byCity = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs');
    expect(feedTitles($byCity))->toBe(['Jaipur', 'Delhi'])
        ->and($byCity->json('feed.location'))->toBe('city');

    // Saved on the profile (Delhi): nearest to that.
    $this->worker->workerProfile->update(['latitude' => 28.61, 'longitude' => 77.21]);
    $saved = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs');
    expect(feedTitles($saved))->toBe(['Delhi', 'Jaipur'])
        ->and($saved->json('feed.location'))->toBe('profile');
});

it('leaves paused jobs out of the feed', function () {
    feedJob($this->employer, 'Loom weaver');
    $plan = Plan::create(['name' => 'Basic', 'slug' => 'basic', 'type' => 'job', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly', 'features' => [], 'is_active' => true]);
    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active->value,
        'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay(),
    ]);

    expect(feedTitles($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')))->toBe([]);
});

it('uses the full search when the karigar searches', function () {
    feedJob($this->employer, 'Loom weaver');

    // Search goes to Typesense (the null driver here finds nothing) and carries no feed block.
    $response = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs?q=loom')->assertOk();

    expect($response->json('feed'))->toBeNull();
});

it('opens the home screen with the top of the feed', function () {
    feedJob($this->employer, 'Loom weaver', ['latitude' => 26.9124, 'longitude' => 75.7873]);
    feedJob($this->employer, 'Potter', ['category' => 'Pottery / Handmade Pots']);

    $response = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/dashboard?lat=26.92&lng=75.79')->assertOk();

    expect(collect($response->json('latest_jobs'))->pluck('title')->all())->toBe(['Loom weaver'])
        ->and($response->json('latest_jobs.0.distance_km'))->toBeLessThan(2);
});

// ───────────────────────── VERIFIED EMPLOYER ─────────────────────────

it('marks a verified employer on job cards and the job page', function () {
    $job = feedJob($this->employer, 'Loom weaver');

    expect($this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')->json('data.0.employer.verified'))->toBeFalse();

    $this->employer->kyc()->create(['pan_number' => 'ABCDE1234F', 'status' => KycStatus::Verified]);

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')->assertJsonPath('data.0.employer.verified', true);
    $this->actingAs($this->worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")->assertJsonPath('data.employer.verified', true);

    // Verification switched off by the admin: no badge anywhere.
    Setting::set('kyc_verification_enabled', '0');
    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs')->assertJsonPath('data.0.employer.verified', false);
});

// ───────────────────────── SIGN-UP CATEGORIES ─────────────────────────

it('offers every active craft category as sign-up skills', function () {
    Category::create(['name' => 'Weaving', 'slug' => 'weaving', 'is_active' => true, 'sort_order' => 1]);
    Category::create(['name' => 'Bunai / Knitting', 'slug' => 'bunai-knitting', 'is_active' => true, 'sort_order' => 0]);
    Category::create(['name' => 'Plumbing', 'slug' => 'plumbing', 'is_active' => false, 'sort_order' => 999]);
    cache()->forget('categories.active');

    $this->getJson('/api/v1/reference')
        ->assertOk()
        ->assertJsonPath('skills', ['Bunai / Knitting', 'Weaving'])
        ->assertJsonPath('job_categories', ['Bunai / Knitting', 'Weaving'])
        ->assertJsonPath('wage_types', ['monthly']);
});

// ───────────────────────── MONTHLY WAGES ─────────────────────────

it('stores a daily or hourly wage from an older app as monthly', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->patchJson('/api/v1/worker/profile', ['expected_wage' => 800, 'wage_type' => 'daily'])
        ->assertOk();

    expect((float) $this->worker->workerProfile->fresh()->expected_wage)->toBe(20800.0)
        ->and($this->worker->workerProfile->fresh()->wage_type)->toBe('monthly');

    $this->actingAs($this->worker, 'sanctum')->patchJson('/api/v1/worker/profile', ['expected_wage' => 100, 'wage_type' => 'hourly']);
    expect((float) $this->worker->workerProfile->fresh()->expected_wage)->toBe(20800.0);

    $this->actingAs($this->worker, 'sanctum')->patchJson('/api/v1/worker/profile', ['expected_wage' => 18000]);
    expect((float) $this->worker->workerProfile->fresh()->expected_wage)->toBe(18000.0)
        ->and($this->worker->workerProfile->fresh()->wage_type)->toBe('monthly');
});

it('turns stored daily and hourly wages into monthly ones, once', function () {
    $daily = feedJob($this->employer, 'Daily job', ['wage_min' => 800, 'wage_max' => 1000, 'wage_type' => 'daily']);
    $hourly = feedJob($this->employer, 'Hourly job', ['wage_min' => 100, 'wage_type' => 'hourly']);
    $monthly = feedJob($this->employer, 'Monthly job', ['wage_min' => 18000, 'wage_type' => 'monthly']);
    $application = $daily->applications()->create([
        'worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending->value, 'expected_wage' => 900,
    ]);
    $this->worker->workerProfile->update(['expected_wage' => 700, 'wage_type' => 'daily']);

    WageConversion::run();
    WageConversion::run(); // a second run changes nothing

    expect((float) $daily->fresh()->wage_min)->toBe(20800.0)
        ->and((float) $daily->fresh()->wage_max)->toBe(26000.0)
        ->and($daily->fresh()->wage_type)->toBe('monthly')
        ->and((float) $hourly->fresh()->wage_min)->toBe(20800.0)
        ->and((float) $monthly->fresh()->wage_min)->toBe(18000.0)
        ->and((float) $application->fresh()->expected_wage)->toBe(23400.0)
        ->and((float) $this->worker->workerProfile->fresh()->expected_wage)->toBe(18200.0)
        ->and($daily->fresh()->wageLabel())->toBe('₹20,800 – ₹26,000 / monthly');
});
