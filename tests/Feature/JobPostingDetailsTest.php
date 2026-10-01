<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\JobDescriptionWriter;
use App\Support\ReferenceData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Jaipur Looms']);
    giveJobPlan($this->employer);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->worker->workerProfile()->create(['skills' => ['Weaving']]);
});

function detailsPayload(array $overrides = []): array
{
    return [
        'title' => 'Handloom weaver', 'description' => 'Weave sarees on a handloom.', 'category' => 'Weaving',
        'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
        ...$overrides,
    ];
}

// ───────────────────────── NEW FIELDS ─────────────────────────

it('takes an experience range, shift hours, any perks and who picks up the call', function () {
    $response = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', detailsPayload([
        'experience_min' => 2, 'experience_max' => 5,
        'shift' => 'day', 'shift_start' => '09:00', 'shift_end' => '18:00',
        'perks' => ['ESI', 'PF', ' Diwali bonus ', 'esi'],
        'contact_mode' => 'both', 'contact_phone' => '9876543210',
        'contact_name' => 'Ramesh Kumar', 'contact_designation' => 'Supervisor',
        'address' => 'Plot 12, Sanganer',
    ]))->assertCreated();

    $response->assertJsonPath('job.experience_label', '2–5 yrs')
        ->assertJsonPath('job.shift_hours_label', '9:00 AM – 6:00 PM')
        ->assertJsonPath('job.perks', ['ESI', 'PF', 'Diwali bonus'])
        ->assertJsonPath('job.contact_name', 'Ramesh Kumar')
        ->assertJsonPath('job.contact_designation', 'Supervisor')
        ->assertJsonPath('job.address', 'Plot 12, Sanganer');

    $job = JobListing::first();

    // The karigar sees them on the job page, the caller included.
    $this->actingAs($this->worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")
        ->assertJsonPath('data.experience_label', '2–5 yrs')
        ->assertJsonPath('data.shift_hours_label', '9:00 AM – 6:00 PM')
        ->assertJsonPath('data.contact_name', 'Ramesh Kumar')
        ->assertJsonPath('data.contact_designation', 'Supervisor');
});

it('keeps the caller private on an apply-only job', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', detailsPayload([
        'contact_mode' => 'apply', 'contact_name' => 'Ramesh Kumar',
    ]))->assertCreated();

    $data = $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/jobs/'.JobListing::first()->id)->json('data');

    expect($data)->not->toHaveKey('contact_name')
        ->and($data)->not->toHaveKey('contact_phone');
});

it('rejects a bad experience range or half a shift', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', detailsPayload(['experience_min' => 5, 'experience_max' => 2]))
        ->assertStatus(422)->assertJsonValidationErrors('experience_max');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', detailsPayload(['shift_start' => '09:00']))
        ->assertStatus(422)->assertJsonValidationErrors('shift_end');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', detailsPayload(['shift_start' => '9 AM', 'shift_end' => '18:00']))
        ->assertStatus(422)->assertJsonValidationErrors('shift_start');
});

it('labels the experience asked for', function (?int $min, ?int $max, ?string $label) {
    $job = new JobListing(['experience_min' => $min, 'experience_max' => $max]);

    expect($job->experienceLabel())->toBe($label);
})->with([
    [2, 5, '2–5 yrs'],
    [3, 3, '3 yrs'],
    [2, null, '2+ yrs'],
    [0, null, 'Freshers welcome'],
    [null, 4, 'Up to 4 yrs'],
    [null, null, null],
]);

// ───────────────────────── FORM OPTIONS ─────────────────────────

it('suggests the chosen category skills and the employer own perks', function () {
    Category::create(['name' => 'Weaving', 'slug' => 'weaving', 'is_active' => true, 'sort_order' => 1, 'skills' => ['Handloom weaving', 'Dyeing']]);
    cache()->forget(Category::SKILLS_CACHE_KEY);

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', detailsPayload(['perks' => ['Diwali bonus']]));

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')
        ->assertOk()
        ->assertJsonPath('category_skills.Weaving', ['Handloom weaving', 'Dyeing'])
        ->assertJsonFragment(['perks' => array_merge(ReferenceData::PERKS, ['Diwali bonus'])]);

    // Another employer does not get this one's perks.
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    expect($this->actingAs($other, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')->json('perks'))->not->toContain('Diwali bonus');

    $this->getJson('/api/v1/reference')->assertJsonPath('category_skills.Weaving', ['Handloom weaving', 'Dyeing']);
});

it('lets the admin edit a category skills', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $category = Category::create(['name' => 'Weaving', 'slug' => 'weaving', 'is_active' => true, 'sort_order' => 1, 'skills' => ['Dyeing']]);
    Category::cachedSkillsMap();

    $this->actingAs($admin)->patch("/admin/categories/{$category->id}", ['skills' => ['Warping', ' Dyeing ', 'Warping', '']])->assertRedirect();

    expect($category->fresh()->skills)->toBe(['Warping', 'Dyeing'])
        ->and(Category::cachedSkillsMap()['Weaving'])->toBe(['Warping', 'Dyeing']);
});

// ───────────────────────── AI IN HINDI ─────────────────────────

it('drafts a description in Hindi when asked', function () {
    config(['services.ai.key' => null, 'services.ai.api_key' => null]);
    $writer = app(JobDescriptionWriter::class);

    $hindi = $writer->suggest('Handloom weaver', 'Weaving', ['Dyeing'], 'Jaipur', 'Rajasthan', 'hi');
    $english = $writer->suggest('Handloom weaver', 'Weaving', ['Dyeing'], 'Jaipur', 'Rajasthan');

    expect($hindi[0])->toMatch('/\p{Devanagari}/u')->toContain('Handloom weaver')
        ->and($english[0])->not->toMatch('/\p{Devanagari}/u');

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/jobs/suggest-description?title=Handloom+weaver&language=hi')
        ->assertOk()
        ->assertJsonPath('suggestions.0', fn ($s) => (bool) preg_match('/\p{Devanagari}/u', $s));

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/jobs/suggest-description?title=Handloom+weaver&language=fr')
        ->assertStatus(422);
});

// ───────────────────────── MAP PIN FROM THE ADDRESS ─────────────────────────

it('places the map pin from the address when the app sends none', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake(['nominatim.openstreetmap.org/*' => Http::response([['lat' => '26.8', 'lon' => '75.8']])]);

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', detailsPayload(['address' => 'Plot 12, Sanganer']))->assertCreated();

    $job = JobListing::first();
    expect((float) $job->latitude)->toBe(26.8)->and((float) $job->longitude)->toBe(75.8);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'Plot 12, Sanganer, Jaipur, Rajasthan, India'));
});

it('keeps the pin the client sent', function () {
    config(['services.geocoder.enabled' => true]);
    Http::fake();

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', detailsPayload([
        'address' => 'Plot 12, Sanganer', 'latitude' => 26.91, 'longitude' => 75.79,
    ]))->assertCreated();

    Http::assertNothingSent();
    expect((float) JobListing::first()->latitude)->toBe(26.91);
});

// ───────────────────────── REPOST ─────────────────────────

it('reposts a closed job as a new live copy', function () {
    $job = $this->employer->jobListings()->create(detailsPayload([
        'status' => JobStatus::Closed->value, 'published_at' => now()->subMonth(),
        'experience_min' => 2, 'perks' => ['ESI'], 'contact_mode' => 'call', 'contact_phone' => '9876543210', 'contact_name' => 'Ramesh',
    ]));

    $response = $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$job->id}/repost")->assertCreated();

    $copy = JobListing::find($response->json('job.id'));

    expect($copy->id)->not->toBe($job->id)
        ->and($copy->status)->toBe(JobStatus::Active)
        ->and($copy->published_at->isToday())->toBeTrue()
        ->and($copy->reposted_from_id)->toBe($job->id)
        ->and($copy->perks)->toBe(['ESI'])
        ->and($copy->contact_name)->toBe('Ramesh')
        ->and($job->fresh()->status)->toBe(JobStatus::Closed);
});

it('reposts an expired job with as long a run as it had', function () {
    $job = $this->employer->jobListings()->create(detailsPayload(['published_at' => now()->subDays(40)]));
    $job->forceFill(['expires_at' => now()->subDays(10)])->saveQuietly(); // ran 30 days

    $this->actingAs($this->employer)->post("/employer/jobs/{$job->id}/repost")
        ->assertRedirect('/employer/jobs')
        ->assertSessionHas('toast.type', 'success');

    $copy = JobListing::where('reposted_from_id', $job->id)->first();
    expect((int) round(now()->diffInDays($copy->expires_at)))->toBe(30);
});

it('refuses to repost a live job or a draft', function () {
    $live = $this->employer->jobListings()->create(detailsPayload());
    $draft = $this->employer->jobListings()->create(detailsPayload(['status' => JobStatus::Draft->value]));

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$live->id}/repost")
        ->assertStatus(422)->assertJsonPath('code', 'cannot_repost');
    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$draft->id}/repost")
        ->assertStatus(422);

    expect(JobListing::count())->toBe(2);
});

it('counts a repost against the plan job posts', function () {
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $employer->employerProfile()->create(['company_name' => 'Small Looms']);
    $plan = Plan::create(['name' => 'One', 'slug' => 'one', 'type' => 'job', 'price' => 99, 'currency' => 'INR', 'interval' => 'monthly', 'features' => ['job_post_limit' => 1], 'is_active' => true]);
    Subscription::create(['employer_id' => $employer->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

    $old = $employer->jobListings()->create(detailsPayload(['status' => JobStatus::Closed->value, 'published_at' => now()->subMonths(2)]));
    $employer->jobListings()->create(detailsPayload()); // uses this cycle's one post

    $this->actingAs($employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$old->id}/repost")
        ->assertStatus(422)->assertJsonPath('code', 'cannot_repost');
});
