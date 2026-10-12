<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobInvite;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\JobModerationNotification;
use App\Services\JobModerator;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config([
        'scout.driver' => 'null',
        'screening.from_number' => '+918000000000',
        'screening.window.start' => '10:00',
        'screening.window.end' => '19:00',
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Interiors', 'city' => 'Jaipur', 'state' => 'Rajasthan']);
    giveJobPlan($this->employer);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Handloom weaver', 'description' => 'Weave sarees on a handloom.', 'category' => 'Weaving',
        'skills' => ['Weaving'], 'status' => JobStatus::Active, 'vacancies' => 2, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'published_at' => now(),
    ]);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Ramesh', 'phone' => '9876500011']);
    $this->worker->workerProfile()->create(['skills' => ['Weaving'], 'city' => 'Jaipur', 'state' => 'Rajasthan', 'available' => true, 'experience_years' => 6]);

    $this->application = JobApplication::create([
        'job_listing_id' => $this->job->id, 'worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending,
    ]);
});

// ───────────────────────── 30-day plans ─────────────────────────

it('seeds 30-day plans: Basic posts 1 job, Standard 3', function () {
    $this->seed(PlanSeeder::class);

    $basic = Plan::where('slug', 'basic')->first();

    expect($basic->interval)->toBe(Plan::INTERVAL_30_DAYS)
        ->and($basic->jobPostLimit())->toBe(1)
        ->and(Plan::where('slug', 'standard')->first()->jobPostLimit())->toBe(3)
        ->and($basic->interval_label)->toBe('30 days')
        ->and($basic->featureList())->toContain('1 job post every 30 days');
});

it('runs a 30-day plan for exactly 30 days', function () {
    $plan = Plan::create([
        'name' => 'Basic30', 'slug' => 'basic-30', 'type' => Plan::TYPE_JOB, 'price' => 499, 'currency' => 'INR',
        'interval' => Plan::INTERVAL_30_DAYS, 'features' => ['job_post_limit' => 1], 'is_active' => true,
    ]);
    $subscription = Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Created->value,
    ]);

    $subscription->activateWithInvoice();

    expect((int) round($subscription->starts_at->diffInDays($subscription->ends_at)))->toBe(30)
        ->and($subscription->currentCycleStart()->equalTo($subscription->ends_at->subDays(30)))->toBeTrue();
});

// ───────────────────────── Booking AI calls ─────────────────────────

it('books an AI call for a time the employer picks, in Indian time', function () {
    $tomorrow = CarbonImmutable::now('Asia/Kolkata')->addDay()->setTime(11, 30)->format('Y-m-d\TH:i');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls", ['at' => $tomorrow])
        ->assertCreated()
        ->assertJsonPath('call.status', 'scheduled')
        ->assertJsonPath('call.can_cancel', true);

    $call = ScreeningCall::sole();
    expect($call->scheduled_at->timezone('Asia/Kolkata')->format('Y-m-d\TH:i'))->toBe($tomorrow)
        ->and($call->source)->toBe('manual');
});

it('moves a time outside calling hours to the next opening', function () {
    $late = CarbonImmutable::now('Asia/Kolkata')->addDay()->setTime(22, 0)->format('Y-m-d\TH:i');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls", ['at' => $late])
        ->assertCreated();

    expect(ScreeningCall::sole()->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('10:00');
});

it('cancels a booked call, and lists AI calls for the employer', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls", [
            'at' => CarbonImmutable::now('Asia/Kolkata')->addDay()->setTime(12, 0)->format('Y-m-d\TH:i'),
        ]);
    $call = ScreeningCall::sole();

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-interviews')
        ->assertOk()
        ->assertJsonPath('counts.upcoming', 1)
        ->assertJsonPath('interviews.data.0.candidate.name', 'Ramesh')
        ->assertJsonPath('interviews.data.0.job.title', 'Handloom weaver')
        ->assertJsonPath('interviews.data.0.status', 'scheduled');

    $this->actingAs($this->employer, 'sanctum')->deleteJson("/api/v1/employer/screening-calls/{$call->id}")
        ->assertOk()
        ->assertJsonPath('call.status', 'cancelled');

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-interviews?filter=done')
        ->assertJsonPath('counts.upcoming', 0)
        ->assertJsonPath('interviews.data.0.status', 'cancelled');
});

it('keeps other employers\' AI calls out of the list', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls", [
            'at' => CarbonImmutable::now('Asia/Kolkata')->addDay()->setTime(12, 0)->format('Y-m-d\TH:i'),
        ]);

    $this->actingAs($other, 'sanctum')->getJson('/api/v1/employer/ai-interviews?filter=all')
        ->assertJsonPath('counts.all', 0);
});

// ───────────────────────── AI shortlist from the database ─────────────────────────

it('invites the best database karigars, and books a call when one applies', function () {
    $this->travelTo(CarbonImmutable::now('Asia/Kolkata')->setTime(12, 0));
    $pick = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Suresh', 'phone' => '9876500022']);
    $pick->workerProfile()->create(['skills' => ['Weaving'], 'city' => 'Jaipur', 'state' => 'Rajasthan', 'available' => true, 'experience_years' => 8]);
    $offTrade = User::factory()->create(['role' => UserRole::Worker->value]);
    $offTrade->workerProfile()->create(['skills' => ['Pottery'], 'city' => 'Jaipur', 'available' => true]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/ai-shortlist", ['count' => 5, 'auto_call' => true])
        ->assertCreated()
        ->assertJsonPath('picked.0.name', 'Suresh')
        ->assertJsonCount(1, 'picked');

    // Already applied (Ramesh) and off-trade karigars are not invited.
    expect(JobInvite::where('source', 'ai')->pluck('worker_id')->all())->toBe([$pick->id]);

    $this->actingAs($pick, 'sanctum')->postJson("/api/v1/jobs/{$this->job->id}/apply")->assertCreated();

    $application = JobApplication::where('worker_id', $pick->id)->sole();
    expect($application->shortlisted_at)->not->toBeNull()
        ->and($application->screeningCalls()->sole())
        ->status->toBe(ScreeningCallStatus::Scheduled)
        ->source->toBe('invite');
});

// ───────────────────────── Job safety check ─────────────────────────

it('holds a job that asks karigars for money, spending nothing', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Packing helper', 'description' => 'Pay registration fee of Rs 500 first, then join.',
        'category' => 'Weaving', 'vacancies' => 3, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])
        ->assertCreated()
        ->assertJsonPath('code', 'job_under_review')
        ->assertJsonPath('job.status', 'draft')
        ->assertJsonPath('job.moderation.status', 'pending');

    $held = JobListing::latest('id')->first();
    expect($held->moderation_reasons)->toContain('Asks karigars to pay money (registration/security fee)')
        ->and($held->published_at)->toBeNull();
    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'held');
});

it('lets an ordinary job straight through', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Kids wear stitching tailor', 'description' => 'Stitch children\'s garments. Uniform provided free. Submit Aadhaar card at joining.',
        'category' => 'Weaving', 'vacancies' => 3, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])
        ->assertCreated()
        ->assertJsonPath('code', null)
        ->assertJsonPath('job.status', 'active');
});

it('pulls a live job back when its words are changed to something harmful', function () {
    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$this->job->id}", [
        'title' => 'Handloom weaver', 'description' => 'Earn Rs 5000 per day from home, just pay a security deposit.',
        'category' => 'Weaving', 'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])->assertOk()->assertJsonPath('code', 'job_under_review');

    expect($this->job->fresh())->status->toBe(JobStatus::Draft)->moderation_status->toBe('pending');
});

it('puts a held job live when an admin approves it, or tells the employer why not', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->job->update(['status' => JobStatus::Draft, 'published_at' => null, 'moderation_status' => 'pending', 'moderation_reasons' => ['x']]);
    $second = $this->employer->jobListings()->create([
        'title' => 'Spa girls needed', 'description' => '…', 'status' => JobStatus::Draft, 'vacancies' => 1,
        'moderation_status' => 'pending', 'moderation_reasons' => ['Adult work'],
    ]);

    $this->actingAs($admin)->post("/admin/jobs/{$this->job->id}/approve")->assertRedirect();
    expect($this->job->fresh())->status->toBe(JobStatus::Active)->moderation_status->toBe('approved')
        ->and($this->job->fresh()->published_at)->not->toBeNull();

    $this->actingAs($admin)->post("/admin/jobs/{$second->id}/reject", ['note' => 'Adult work is not allowed.'])->assertRedirect();
    expect($second->fresh())->status->toBe(JobStatus::Draft)->moderation_status->toBe('rejected')->moderation_note->toBe('Adult work is not allowed.');

    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'approved');
    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'rejected');
});

it('checks nothing while the admin has it switched off', function () {
    Setting::set(JobModerator::ENABLED_KEY, '0');

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Helper', 'description' => 'Pay registration fee first.',
        'category' => 'Weaving', 'vacancies' => 1, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])->assertCreated()->assertJsonPath('job.status', 'active');
});
