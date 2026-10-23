<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Jobs\CheckJobSafety;
use App\Models\JobApplication;
use App\Models\JobInvite;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\JobHeldForReviewNotification;
use App\Notifications\JobModerationNotification;
use App\Notifications\NewJobNotification;
use App\Services\JobModerator;
use Carbon\CarbonImmutable;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

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
    giveCredits($this->employer);

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

it('seeds day-based plans: Single Job posts 1 job, 1 Month 3', function () {
    $this->seed(PlanSeeder::class);

    $single = Plan::where('slug', 'single-job')->first();

    expect($single->interval)->toBe(Plan::INTERVAL_DAYS)
        ->and($single->jobPostLimit())->toBe(1)
        ->and(Plan::where('slug', 'job-1-month')->first()->jobPostLimit())->toBe(3)
        ->and($single->interval_label)->toBe('30 days')
        ->and($single->featureList())->toContain('1 job post, live for 15 days');
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
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

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
    Notification::assertSentTo($admin, JobHeldForReviewNotification::class);
    Notification::assertNotSentTo($this->worker, NewJobNotification::class);
});

it('lets an ordinary job straight through', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Kids wear stitching tailor', 'description' => 'Stitch children\'s garments. Uniform provided free. Submit Aadhaar card at joining.',
        'category' => 'Weaving', 'vacancies' => 3, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])
        ->assertCreated()
        ->assertJsonPath('code', null)
        ->assertJsonPath('job.status', 'active')
        // On record that it passed, and how: no AI key in tests, so phrases.
        ->assertJsonPath('job.moderation.status', 'approved')
        ->assertJsonPath('job.moderation.method', 'phrases')
        ->assertJsonPath('job.moderation.by_admin', false);

    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'passed');
    Notification::assertSentTo($this->worker, NewJobNotification::class);
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
    // Never live before the approval: karigars hear about it now.
    Notification::assertSentTo($this->worker, NewJobNotification::class);
});

it('checks nothing while the admin has it switched off', function () {
    Setting::set(JobModerator::ENABLED_KEY, '0');

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Helper', 'description' => 'Pay registration fee first.',
        'category' => 'Weaving', 'vacancies' => 1, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])->assertCreated()->assertJsonPath('job.status', 'active')->assertJsonPath('job.moderation', null);
});

it('checks a new job on the queue: a draft nobody sees until it passes', function () {
    Queue::fake();

    $response = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Zari embroidery karigar', 'description' => 'Hand zari work on lehengas.',
        'category' => 'Weaving', 'skills' => ['Weaving'], 'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])
        ->assertCreated()
        ->assertJsonPath('code', 'job_checking')
        ->assertJsonPath('job.status', 'draft')
        ->assertJsonPath('job.moderation.status', 'checking');

    $job = JobListing::find($response->json('job.id'));
    Queue::assertPushedOn(CheckJobSafety::QUEUE, CheckJobSafety::class, fn ($check) => $check->jobListingId === $job->id);
    Notification::assertNotSentTo($this->worker, NewJobNotification::class);

    // Not editable, closable or repostable while the check decides.
    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$job->id}", [
        'title' => 'Zari karigar', 'description' => 'x', 'category' => 'Weaving', 'vacancies' => 2, 'status' => 'active',
    ])->assertStatus(409)->assertJsonPath('code', 'job_checking');
    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$job->id}/close")->assertStatus(409);

    (new CheckJobSafety($job->id))->handle(app(JobModerator::class));

    expect($job->fresh())
        ->status->toBe(JobStatus::Active)
        ->moderation_status->toBe('approved')
        ->moderation_method->toBe('phrases')
        ->published_at->not->toBeNull();
    Notification::assertSentTo($this->worker, NewJobNotification::class);
    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'passed');
});

it('takes an edited live job off the site while it is re-checked, without alerting karigars again', function () {
    Queue::fake();

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$this->job->id}", [
        'title' => 'Handloom weaver', 'description' => 'Weave Banarasi sarees on a handloom.',
        'category' => 'Weaving', 'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])->assertOk()->assertJsonPath('code', 'job_checking');

    expect($this->job->fresh())->status->toBe(JobStatus::Draft)->moderation_status->toBe('checking');

    (new CheckJobSafety($this->job->id))->handle(app(JobModerator::class));

    expect($this->job->fresh())->status->toBe(JobStatus::Active)->moderation_status->toBe('approved');
    Notification::assertNotSentTo($this->worker, NewJobNotification::class);
    Notification::assertSentTo($this->employer, JobModerationNotification::class, fn ($n) => $n->state === 'updated');
});

it('lets a wage change on a live job through without a check', function () {
    Queue::fake();

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$this->job->id}", [
        'title' => 'Handloom weaver', 'description' => 'Weave sarees on a handloom.', 'wage_min' => 15000,
        'category' => 'Weaving', 'skills' => ['Weaving'], 'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ])->assertOk()->assertJsonPath('code', null)->assertJsonPath('job.status', 'active');

    Queue::assertNotPushed(CheckJobSafety::class);
});

it('counts a job being checked against the plan and the free post', function () {
    Queue::fake();
    $owner = User::factory()->create(['role' => UserRole::Employer->value]);
    $owner->employerProfile()->create(['company_name' => 'Free Post Co', 'city' => 'Jaipur', 'state' => 'Rajasthan']);
    $post = fn () => $this->actingAs($owner, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Block printer', 'description' => 'Hand block printing on cotton.',
        'category' => 'Weaving', 'vacancies' => 1, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);

    $first = $post()->assertCreated()->assertJsonPath('code', 'job_checking');
    // The free post is on its way to being spent: no second one meanwhile.
    $post()->assertStatus(422)->assertJsonPath('code', 'subscription_required');
    expect($owner->employerProfile->fresh()->free_post_used_at)->toBeNull();

    (new CheckJobSafety($first->json('job.id')))->handle(app(JobModerator::class));

    expect($owner->employerProfile->fresh()->free_post_used_at)->not->toBeNull();
});

it('retries a model outage instead of passing the job on the phrase list', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake(['*' => Http::response('rate limited', 429)]);
    $moderator = app(JobModerator::class);
    $job = ['title' => 'Tailor', 'description' => 'Stitch kurtas.'];

    expect(fn () => $moderator->check($job, strict: true))->toThrow(RequestException::class)
        ->and($moderator->check($job))->toMatchArray(['flagged' => false, 'method' => 'phrases']);
});

it('hands a check that cannot run to an admin rather than leave it on checking', function () {
    $this->job->update(['status' => JobStatus::Draft, 'moderation_status' => 'checking']);

    (new CheckJobSafety($this->job->id))->failed(new RuntimeException('boom'));

    expect($this->job->fresh())->moderation_status->toBe('pending')->moderation_method->toBeNull();
});

it('queues a stuck check again', function () {
    Queue::fake();
    $this->job->update(['status' => JobStatus::Draft, 'moderation_status' => 'checking']);
    JobListing::whereKey($this->job->id)->update(['updated_at' => now()->subHour()]);

    $this->artisan('jobs:recheck-stuck')->assertSuccessful();

    Queue::assertPushed(CheckJobSafety::class, fn ($check) => $check->jobListingId === $this->job->id);
});

it('will not repost a job the safety check turned down', function () {
    $this->job->update(['status' => JobStatus::Draft, 'moderation_status' => 'rejected', 'moderation_note' => 'Scam']);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/jobs/{$this->job->id}/repost")->assertStatus(422);

    expect(JobListing::count())->toBe(1);
});
