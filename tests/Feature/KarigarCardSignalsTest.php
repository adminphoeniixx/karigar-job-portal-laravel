<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use App\Models\WorkerRejection;
use App\Services\WorkerRejections;
use App\Support\KarigarLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'collection']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    // The employer sits in Jaipur's Malviya Nagar.
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'latitude' => 26.8505, 'longitude' => 75.8100]);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh']);
    // Sanganer, about 7 km south: the karigar's own pin.
    $this->profile = $this->worker->workerProfile()->create([
        'phone' => '9876543210', 'city' => 'Jaipur', 'state' => 'Rajasthan', 'area' => 'Sanganer',
        'latitude' => 26.8231, 'longitude' => 75.7900, 'location_source' => 'gps', 'skills' => ['Weaving'], 'available' => true,
    ]);

    $plan = Plan::create([
        'name' => 'DB', 'slug' => 'db', 'type' => Plan::TYPE_DATABASE, 'price' => 299, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['contact_unlock_limit' => 10, 'contact_database_limit' => 1000],
        'is_active' => true,
    ]);
    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
});

// ───────────────────────── Reject without unlocking ─────────────────────────

it('rejects a karigar for free, without showing the number', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/workers/{$this->profile->id}/reject")
        ->assertOk()
        ->assertJsonPath('worker.rejected', true)
        ->assertJsonPath('rejected_count', 1);

    expect(WorkerContactUnlock::count())->toBe(0);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/workers/{$this->profile->id}")
        ->assertJsonPath('worker.rejected', true)
        ->assertJsonPath('worker.phone', null)
        ->assertJsonPath('worker.can_unlock', true);
});

it('takes a rejected karigar back', function () {
    WorkerRejection::create(['employer_id' => $this->employer->id, 'worker_id' => $this->worker->id]);

    $this->actingAs($this->employer, 'sanctum')
        ->deleteJson("/api/v1/employer/workers/{$this->profile->id}/reject")
        ->assertOk()
        ->assertJsonPath('worker.rejected', false)
        ->assertJsonPath('rejected_count', 0);
});

it('keeps rejected karigars out of the search, or shows only them', function () {
    $rejections = WorkerRejections::for($this->employer);
    expect($rejections->searchFilter(false))->toBeNull();

    $rejections->reject($this->worker, $this->employer);
    $other = User::factory()->create(['role' => UserRole::Worker->value]);
    $rejections->reject($other, $this->employer);

    expect($rejections->searchFilter(false))->toBe("user_id:!=[{$this->worker->id},{$other->id}]")
        ->and($rejections->searchFilter(true))->toBe("user_id:=[{$this->worker->id},{$other->id}]");
});

it('shows an empty Rejected tab without searching', function () {
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/workers?rejected=1')
        ->assertOk()
        ->assertJsonCount(0, 'workers.data')
        ->assertJsonPath('rejected_count', 0);
});

it('rejects for the whole account when a team member does it', function () {
    $member = User::factory()->create(['role' => UserRole::Employer->value]);
    $member->teamMembership()->create(['employer_id' => $this->employer->id, 'role' => 'recruiter']);

    $this->actingAs($member, 'sanctum')->postJson("/api/v1/employer/workers/{$this->profile->id}/reject")->assertOk();

    expect(WorkerRejection::first())->employer_id->toBe($this->employer->id)->rejected_by->toBe($member->id);
});

it('leaves rejected karigars out of a job\'s matches', function () {
    $job = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'category' => 'Weaving', 'skills' => ['Weaving'],
        'status' => JobStatus::Active->value, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'latitude' => 26.85, 'longitude' => 75.81,
    ]);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/jobs/{$job->id}/matches")
        ->assertJsonPath('workers.0.user_id', $this->worker->id)
        ->assertJsonPath('workers.0.location_label', 'Sanganer, Jaipur');

    WorkerRejections::for($this->employer)->reject($this->worker, $this->employer);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/jobs/{$job->id}/matches")
        ->assertJsonCount(0, 'workers');
});

// ───────────────────────── Distance, area, unlocked by ─────────────────────────

it('shows how far the karigar is, their area, and how many other HRs unlocked them', function () {
    foreach (range(1, 3) as $i) {
        $hr = User::factory()->create(['role' => UserRole::Employer->value]);
        WorkerContactUnlock::create(['employer_id' => $hr->id, 'worker_id' => $this->worker->id, 'unlocked_by' => $hr->id, 'source' => 'directory', 'pool' => 'database']);
    }
    // This employer's own unlock is not counted.
    WorkerContactUnlock::create(['employer_id' => $this->employer->id, 'worker_id' => $this->worker->id, 'unlocked_by' => $this->employer->id, 'source' => 'directory', 'pool' => 'database']);

    // The test search engine reads `*` literally, so search by name.
    $card = $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/workers?q=Ramesh')
        ->assertOk()
        ->json('workers.data.0');

    expect($card)
        ->distance_label->toBe('3.6 km')
        ->distance_approx->toBeFalse()
        ->area->toBe('Sanganer')
        ->location_label->toBe('Sanganer, Jaipur')
        ->unlocked_by_count->toBe(3)
        ->rejected->toBeFalse();
});

it('calls a city-centre distance approximate', function () {
    $this->profile->update(['area' => null, 'latitude' => 26.9155, 'longitude' => 75.8190, 'location_source' => 'city']);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/workers/{$this->profile->id}")
        ->assertJsonPath('worker.distance_label', '~7 km')
        ->assertJsonPath('worker.distance_approx', true)
        ->assertJsonPath('worker.location_label', 'Jaipur');
});

it('formats distances the way cards show them', function (float $km, bool $exact, ?string $label) {
    expect(KarigarLocation::format($km, $exact))->toBe($label);
})->with([
    [1.5, true, '1.5 km'],
    [3.0, true, '3 km'],
    [0.8, true, '800 m'],
    [12.4, true, '12 km'],
    [4.4, false, '~4 km'],
    [0.6, false, null],
]);

it('measures an applicant from the job\'s site', function () {
    $job = $this->employer->jobListings()->create([
        'title' => 'Weaver', 'description' => 'Handloom', 'category' => 'Weaving',
        'status' => JobStatus::Active->value, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        // Right in Sanganer.
        'latitude' => 26.8231, 'longitude' => 75.7900,
    ]);
    giveJobPlan($this->employer);
    $job->applications()->create(['worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending]);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/jobs/{$job->id}/applicants")
        ->assertOk()
        ->assertJsonPath('data.0.worker.distance_label', '100 m')
        ->assertJsonPath('data.0.worker.location_label', 'Sanganer, Jaipur')
        ->assertJsonPath('data.0.worker.unlocked_by_count', 0);
});

// ───────────────────────── The karigar's area ─────────────────────────

it('names the area from the phone\'s position', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/reverse*' => Http::response([
        'lat' => '26.82', 'lon' => '75.79',
        'address' => ['suburb' => 'Sanganer', 'city' => 'Jaipur', 'state' => 'Rajasthan'],
    ])]);
    $karigar = User::factory()->create(['role' => UserRole::Worker->value]);
    $karigar->workerProfile()->create(['city' => 'Jaipur', 'state' => 'Rajasthan']);

    $this->actingAs($karigar, 'sanctum')
        ->patchJson('/api/v1/worker/profile', ['latitude' => 26.82, 'longitude' => 75.79])
        ->assertOk()
        ->assertJsonPath('data.area', 'Sanganer')
        ->assertJsonPath('data.location_source', 'gps');
});

it('puts the pin on an area the karigar types', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/search*' => Http::response([['lat' => '26.9095', 'lon' => '75.7391']])]);
    $karigar = User::factory()->create(['role' => UserRole::Worker->value]);
    $karigar->workerProfile()->create(['city' => 'Jaipur', 'state' => 'Rajasthan', 'latitude' => 26.9155, 'longitude' => 75.8190, 'location_source' => 'city']);

    $this->actingAs($karigar, 'sanctum')
        ->patchJson('/api/v1/worker/profile', ['area' => 'Vaishali Nagar'])
        ->assertOk()
        ->assertJsonPath('data.area', 'Vaishali Nagar')
        ->assertJsonPath('data.latitude', 26.9095)
        ->assertJsonPath('data.location_source', 'area');
});

it('moves the pin to the new city\'s centre and drops the old area', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->patchJson('/api/v1/worker/profile', ['city' => 'Udaipur', 'state' => 'Rajasthan'])
        ->assertOk()
        ->assertJsonPath('data.area', null)
        ->assertJsonPath('data.location_source', 'city')
        ->assertJsonPath('data.latitude', 24.57872);
});

it('leaves the area alone when the same pin comes back from the website form', function () {
    Http::fake();

    $this->actingAs($this->worker)
        ->patch('/worker/profile', [
            'city' => 'Jaipur', 'state' => 'Rajasthan',
            // The form sends the pin it loaded, written differently.
            'latitude' => '26.8231', 'longitude' => '75.79',
        ])
        ->assertRedirect();

    expect($this->profile->fresh())->area->toBe('Sanganer')->location_source->toBe('gps');
    Http::assertNothingSent();
});

it('treats a pin the website centred on a new city as approximate', function () {
    Http::fake();

    $this->actingAs($this->worker)
        ->patch('/worker/profile', [
            'city' => 'Ajmer', 'state' => 'Rajasthan',
            'latitude' => '26.4691', 'longitude' => '74.639', 'location_source' => 'city',
        ])
        ->assertRedirect();

    expect($this->profile->fresh())->area->toBeNull()->location_source->toBe('city');
    Http::assertNothingSent();
});

it('gives the area of a place picked from the search', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['*/search*' => Http::response([
        ['lat' => '26.82', 'lon' => '75.80', 'address' => ['suburb' => 'Sanganer', 'city' => 'Jaipur', 'state' => 'Rajasthan']],
    ])]);

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/places?q=Sanganer')
        ->assertJsonPath('places.0.area', 'Sanganer')
        ->assertJsonPath('places.0.city', 'Jaipur');
});
