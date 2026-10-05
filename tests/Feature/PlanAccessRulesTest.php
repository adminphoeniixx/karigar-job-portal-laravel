<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use App\Services\ApplicantAccess;
use App\Services\CreditWallet;
use App\Services\JobPostingGate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Jaipur Handlooms']);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Handloom weaver', 'description' => 'Craft work', 'category' => 'Weaving',
        'status' => JobStatus::Active->value, 'vacancies' => 5, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);

    $this->jobPlan = Plan::create([
        'name' => 'Standard', 'slug' => 'standard', 'type' => Plan::TYPE_JOB, 'price' => 999, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 2, 'contact_database_limit' => 1000], 'is_active' => true,
    ]);

    $this->databasePlan = Plan::create([
        'name' => 'Database Basic', 'slug' => 'database-basic', 'type' => Plan::TYPE_DATABASE, 'price' => 299, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 1, 'contact_database_limit' => 2000], 'is_active' => true,
    ]);
});

function accessSubscribe(User $employer, Plan $plan, bool $active = true): Subscription
{
    return Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value,
        'starts_at' => $active ? now() : now()->subMonths(2),
        'ends_at' => $active ? now()->addMonth() : now()->subMonth(),
    ]);
}

/**
 * @return list<JobApplication>
 */
function accessApplicants(JobListing $job, int $count): array
{
    return User::factory()->count($count)->create(['role' => UserRole::Worker->value])
        ->map(fn (User $worker) => $job->applications()->create(['worker_id' => $worker->id, 'status' => ApplicationStatus::Pending->value]))
        ->all();
}

function accessKarigar(string $phone): User
{
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $worker->workerProfile()->create(['phone' => $phone]);

    return $worker;
}

// ───────────────────────── APPLICANT BATCHES ─────────────────────────

it('shows the first 20 applicants, then 15 more once all 20 are decided', function () {
    accessSubscribe($this->employer, $this->jobPlan);
    $apps = accessApplicants($this->job, 40);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 20)
            ->where('access.reason', 'batch')
            ->where('access.total', 40)
            ->where('access.undecided', 20)
            ->where('access.next_batch', 15));

    // Deciding 19 of them is not enough.
    foreach (array_slice($apps, 0, 19) as $i => $app) {
        $app->update($i % 2 ? ['status' => ApplicationStatus::Rejected] : ['shortlisted_at' => now()]);
    }
    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 20)->where('access.undecided', 1));

    $apps[19]->update(['status' => ApplicationStatus::Rejected]);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 35)->where('access.hidden', 5));
});

it('never takes a batch back when a decision is undone', function () {
    accessSubscribe($this->employer, $this->jobPlan);
    $apps = accessApplicants($this->job, 30);
    foreach (array_slice($apps, 0, 20) as $app) {
        $app->update(['shortlisted_at' => now()]);
    }

    expect(ApplicantAccess::for($this->employer)->released($this->job))->toBe(30);

    $apps[0]->update(['shortlisted_at' => null]);

    expect(ApplicantAccess::for($this->employer)->released($this->job->fresh()))->toBe(30);
});

it('takes its batch sizes from the admin settings', function () {
    Setting::set(ApplicantAccess::FIRST_BATCH_KEY, '5');
    Setting::set(ApplicantAccess::NEXT_BATCH_KEY, '3');
    accessSubscribe($this->employer, $this->jobPlan);
    $apps = accessApplicants($this->job, 10);

    expect(ApplicantAccess::for($this->employer)->released($this->job))->toBe(5);

    foreach (array_slice($apps, 0, 5) as $app) {
        $app->update(['status' => ApplicationStatus::Rejected]);
    }

    expect(ApplicantAccess::for($this->employer)->released($this->job->fresh()))->toBe(8);
});

it('refuses an applicant outside the released batch, in the app too', function () {
    accessSubscribe($this->employer, $this->jobPlan);
    $apps = accessApplicants($this->job, 25);

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/applicants/{$apps[5]->id}")->assertOk();
    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/applicants/{$apps[22]->id}")->assertForbidden();
    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/applicants/{$apps[22]->id}/unlock")->assertForbidden();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/jobs/{$this->job->id}/applicants")
        ->assertOk()
        ->assertJsonPath('counts.all', 20)
        ->assertJsonPath('access.hidden', 5)
        ->assertJsonPath('access.reason', 'batch');
});

it('keeps hidden applicants off the app home screen', function () {
    $apps = accessApplicants($this->job, 25);

    // No plan: none of them.
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/dashboard')
        ->assertOk()
        ->assertJsonCount(0, 'recent_applicants');

    accessSubscribe($this->employer, $this->jobPlan);

    $ids = collect($this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/dashboard')->json('recent_applicants'))->pluck('id');

    expect($ids)->toHaveCount(5)
        ->and($ids->every(fn ($id) => $id <= $apps[19]->id))->toBeTrue();
});

// ───────────────────────── WITHOUT A JOB PLAN ─────────────────────────

it('shows only a count of applicants to an employer who never had a plan', function () {
    $apps = accessApplicants($this->job, 6);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 0)
            ->where('access.reason', 'no_plan')
            ->where('access.hidden', 6));

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/applicants/{$apps[0]->id}")->assertForbidden();
});

it('keeps only shortlisted and hired applicants once the job plan ends', function () {
    accessSubscribe($this->employer, $this->jobPlan, active: false);
    $apps = accessApplicants($this->job, 5);
    $apps[0]->update(['shortlisted_at' => now()]);
    $apps[1]->update(['status' => ApplicationStatus::Accepted]);
    $apps[2]->update(['status' => ApplicationStatus::Rejected]);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 2)
            ->where('access.reason', 'plan_expired')
            ->where('access.hidden', 3));

    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/applicants/{$apps[0]->id}")->assertOk();
    $this->actingAs($this->employer, 'sanctum')->getJson("/api/v1/employer/applicants/{$apps[2]->id}")->assertForbidden();

    // Renewing brings them all back.
    accessSubscribe($this->employer, $this->jobPlan);
    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->has('applications', 5)->where('access.reason', null));
});

it('no longer unlocks for free without a plan', function () {
    $apps = accessApplicants($this->job, 1);
    $apps[0]->update(['shortlisted_at' => now()]); // kept, so visible

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$apps[0]->id}/unlock")
        ->assertStatus(422)
        ->assertJsonPath('code', 'out_of_credits');

    CreditWallet::for($this->employer)->add(1);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/applicants/{$apps[0]->id}/unlock")->assertOk();
    expect(WorkerContactUnlock::first()->pool)->toBe(WorkerContactUnlock::POOL_CREDIT);
});

// ───────────────────────── PAUSED JOBS ─────────────────────────

it('pauses the jobs of an employer whose job plan ran out, until it renews', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    // Never subscribed: the free first post keeps hiring.
    expect($this->job->isOpenForApplications())->toBeTrue();

    accessSubscribe($this->employer, $this->jobPlan, active: false);
    $job = $this->job->fresh();

    expect($job->isOpenForApplications())->toBeFalse()
        ->and($job->shouldBeSearchable())->toBeFalse()
        ->and(JobListing::hiring()->whereKey($job->id)->exists())->toBeFalse();

    $this->actingAs($worker, 'sanctum')
        ->postJson("/api/v1/jobs/{$job->id}/apply")
        ->assertStatus(422)
        ->assertJsonPath('code', 'job_not_hiring');
    $this->actingAs($worker)->post("/jobs/{$job->id}/apply")->assertSessionHas('toast.type', 'error');
    expect($job->applications()->count())->toBe(0);

    accessSubscribe($this->employer, $this->jobPlan);

    expect($job->fresh()->isOpenForApplications())->toBeTrue()
        ->and(JobListing::hiring()->whereKey($job->id)->exists())->toBeTrue();
});

it('keeps paused jobs off the karigar dashboards and tells the employer', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    accessSubscribe($this->employer, $this->jobPlan, active: false);

    $ids = collect($this->actingAs($worker, 'sanctum')->getJson('/api/v1/worker/dashboard')->json('latest_jobs'))->pluck('id');
    expect($ids)->not->toContain($this->job->id);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs')->assertJsonPath('hiring_paused', true);
    $this->actingAs($this->employer)->get('/employer/jobs')->assertInertia(fn ($page) => $page->where('hiringPaused', true));

    accessSubscribe($this->employer, $this->jobPlan);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs')->assertJsonPath('hiring_paused', false);
});

it('hides a paused job everywhere except from workers who already applied', function () {
    $stranger = User::factory()->create(['role' => UserRole::Worker->value]);
    $applicant = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->job->applications()->create(['worker_id' => $applicant->id, 'status' => ApplicationStatus::Pending->value]);
    $stranger->savedJobs()->create(['job_listing_id' => $this->job->id]);

    accessSubscribe($this->employer, $this->jobPlan, active: false);
    $id = $this->job->id;

    $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/jobs/{$id}")->assertNotFound();
    $this->actingAs($stranger)->get("/worker/jobs/{$id}")->assertNotFound();
    $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/worker/saved')->assertJsonCount(0, 'data');
    $this->actingAs($stranger)->get('/worker/saved')->assertInertia(fn ($page) => $page->where('saved.data', []));
    auth()->guard('web')->logout();
    $this->get("/jobs/{$id}")->assertNotFound();

    $this->actingAs($applicant, 'sanctum')->getJson("/api/v1/jobs/{$id}")
        ->assertOk()
        ->assertJsonPath('meta.is_open', false)
        ->assertJsonPath('meta.can_apply', false);
    $this->actingAs($this->employer)->get("/jobs/{$id}")->assertOk();

    accessSubscribe($this->employer, $this->jobPlan);

    $this->actingAs($stranger, 'sanctum')->getJson("/api/v1/jobs/{$id}")->assertOk()->assertJsonPath('meta.is_open', true);
    $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/worker/saved')->assertJsonCount(1, 'data');
});

it('does not pause jobs for a database plan running out', function () {
    accessSubscribe($this->employer, $this->databasePlan, active: false);

    expect($this->job->fresh()->isOpenForApplications())->toBeTrue();
});

it('syncs paused jobs with search from the scheduled command', function () {
    accessSubscribe($this->employer, $this->jobPlan, active: false);

    $this->artisan('jobs:sync-hiring')->assertSuccessful();
});

// ───────────────────────── DATABASE PLANS ─────────────────────────

it('opens the Worker Database with a database plan alone, and posts no jobs', function () {
    Setting::set('first_post_free_enabled', '0');
    accessSubscribe($this->employer, $this->databasePlan);
    $karigar = accessKarigar('9000000301');

    expect($this->employer->contactDatabaseQuota())->toBe(2000)
        ->and(JobPostingGate::evaluate($this->employer)['allowed'])->toBeFalse();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/workers/{$karigar->workerProfile->id}/unlock")
        ->assertOk()
        ->assertJsonPath('worker.phone', '9000000301')
        ->assertJsonPath('credits.database_plan.used', 1);

    expect(WorkerContactUnlock::first()->pool)->toBe(WorkerContactUnlock::POOL_DATABASE);
});

it('adds a database plan to a job plan, each paying for its own list first', function () {
    accessSubscribe($this->employer, $this->jobPlan);
    accessSubscribe($this->employer, $this->databasePlan);

    expect($this->employer->contactDatabaseQuota())->toBe(3000);

    // Two database karigars: the database plan pays for one, the job plan for the next.
    $first = accessKarigar('9000000302');
    $second = accessKarigar('9000000303');
    $this->actingAs($this->employer)->post("/employer/workers/{$first->workerProfile->id}/unlock");
    $this->actingAs($this->employer)->post("/employer/workers/{$second->workerProfile->id}/unlock");

    // An applicant goes on the job plan.
    $apps = accessApplicants($this->job, 1);
    $this->actingAs($this->employer)->post("/employer/applications/{$apps[0]->id}/unlock");

    expect(WorkerContactUnlock::where('worker_id', $first->id)->value('pool'))->toBe('database')
        ->and(WorkerContactUnlock::where('worker_id', $second->id)->value('pool'))->toBe('job')
        ->and(WorkerContactUnlock::where('worker_id', $apps[0]->worker_id)->value('pool'))->toBe('job')
        ->and(CreditWallet::for($this->employer)->planRemaining())->toBe(0);
});

it('hides database contacts when the database access ends, keeping shortlisted karigars', function () {
    $subscription = accessSubscribe($this->employer, $this->databasePlan);
    $plain = accessKarigar('9000000304');
    $kept = accessKarigar('9000000305');
    foreach ([$plain, $kept] as $karigar) {
        WorkerContactUnlock::create(['employer_id' => $this->employer->id, 'worker_id' => $karigar->id, 'source' => 'directory', 'pool' => 'database']);
    }
    $this->job->applications()->create(['worker_id' => $kept->id, 'status' => ApplicationStatus::Pending->value, 'shortlisted_at' => now()]);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/contacts/database')->assertJsonPath('contacts.total', 2);

    $subscription->update(['ends_at' => now()->subDay()]);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/contacts/database')
        ->assertJsonPath('contacts.total', 1)
        ->assertJsonPath('contacts.data.0.phone', '9000000305')
        ->assertJsonPath('usage.database_hidden', 1)
        ->assertJsonPath('usage.has_database_access', false);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/workers/{$plain->workerProfile->id}")
        ->assertJsonPath('worker.phone', null)
        ->assertJsonPath('worker.can_unlock', false);
});

it('gives no database access from the admin bonus alone', function () {
    $this->employer->employerProfile->update(['contact_quota_bonus' => 500]);

    expect($this->employer->fresh()->contactDatabaseQuota())->toBe(0);

    accessSubscribe($this->employer, $this->jobPlan);

    expect($this->employer->fresh()->contactDatabaseQuota())->toBe(1500);
});

// ───────────────────────── BILLING SCREENS ─────────────────────────

it('lists both kinds of plan and both current subscriptions in the app', function () {
    accessSubscribe($this->employer, $this->jobPlan);
    accessSubscribe($this->employer, $this->databasePlan);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/plans')
        ->assertOk()
        ->assertJsonPath('current.plan', 'Standard')
        ->assertJsonPath('current_database.plan', 'Database Basic')
        ->assertJsonPath('plans.0.type', 'database')
        ->assertJsonPath('plans.0.is_current', true)
        ->assertJsonPath('job_plan_lapsed', false);
});

it('saves the batch sizes from the admin settings page', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->patch('/admin/settings', [
        'first_post_free_enabled' => true, 'kyc_verification_enabled' => true,
        'ai_auto_shortlist_enabled' => false, 'ai_auto_shortlist_threshold' => 70,
        'ai_auto_reject_enabled' => false, 'ai_auto_reject_below' => 20,
        'ai_screening_call_enabled' => false,
        'applicant_first_batch' => 12, 'applicant_next_batch' => 6,
    ])->assertRedirect();

    expect(ApplicantAccess::firstBatch())->toBe(12)
        ->and(ApplicantAccess::nextBatch())->toBe(6);
});
