<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use App\Services\Screening\CallScript;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A job says where the work is done (on-site, from home, or either) and how
 * the interview is held (in person, or on phone/video). The AI call asks the
 * karigar about whichever applies.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Jaipur Looms']);
    giveJobPlan($this->employer);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Sunita Devi']);
    $this->worker->workerProfile()->create(['skills' => ['Kadhai'], 'spoken_languages' => ['Hindi']]);
});

function modeJobPayload(array $overrides = []): array
{
    return [
        'title' => 'Kadhai karigar', 'description' => 'Hand embroidery on dupattas.', 'category' => 'Kadhai / Embroidery',
        'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
        ...$overrides,
    ];
}

function modeScript(JobListing $job, User $worker): string
{
    return CallScript::for(JobApplication::create([
        'job_listing_id' => $job->id,
        'worker_id' => $worker->id,
    ])->load('job.employer.employerProfile', 'worker.workerProfile'))->instructions;
}

it('saves where the work is done and how the interview is held', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', modeJobPayload([
        'work_mode' => 'both', 'interview_mode' => 'remote',
    ]))->assertCreated()
        ->assertJsonPath('job.work_mode', 'both')
        ->assertJsonPath('job.work_mode_label', 'On-site or from home')
        ->assertJsonPath('job.interview_mode', 'remote')
        ->assertJsonPath('job.interview_mode_label', 'Interview on phone / video');

    $job = JobListing::first();

    $this->actingAs($this->worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")
        ->assertJsonPath('data.work_mode', 'both')
        ->assertJsonPath('data.interview_mode', 'remote');
});

it('keeps older app builds working: on-site, and a remote job interviews remotely', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', modeJobPayload())
        ->assertCreated()
        ->assertJsonPath('job.work_mode', 'on_site')
        ->assertJsonPath('job.interview_mode', 'on_site');

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', modeJobPayload(['work_mode' => 'remote']))
        ->assertCreated()
        ->assertJsonPath('job.interview_mode', 'remote');
});

it('keeps the modes on an edit that leaves them out', function () {
    $id = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', modeJobPayload([
        'work_mode' => 'remote', 'interview_mode' => 'on_site',
    ]))->json('job.id');

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", modeJobPayload(['description' => 'Hand embroidery on bridal wear, now with zardozi too.']))
        ->assertOk();

    expect(JobListing::find($id))->work_mode->toBe('remote')->interview_mode->toBe('on_site');
});

it('refuses a mode it does not know', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', modeJobPayload(['work_mode' => 'hybrid', 'interview_mode' => 'video']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['work_mode', 'interview_mode']);
});

it('offers the modes on the job form options', function () {
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')
        ->assertOk()
        ->assertJsonPath('work_modes.1', ['value' => 'remote', 'label' => 'Work from home'])
        ->assertJsonPath('interview_modes.1.value', 'remote');
});

it('asks an on-site karigar about coming to the city and to the interview', function () {
    $job = $this->employer->jobListings()->create(modeJobPayload(['status' => JobStatus::Active]));

    $script = modeScript($job, $this->worker);

    expect($script)->toContain('यह काम Jaipur में है। वहाँ आकर काम करने में')
        ->toContain('interview के लिए भी वहीं आना होगा')
        ->not->toContain('घर से');
});

it('asks a work-from-home karigar about home, space and tools, never about coming over', function () {
    $job = $this->employer->jobListings()->create(modeJobPayload([
        'status' => JobStatus::Active, 'work_mode' => 'remote', 'interview_mode' => 'remote',
    ]));

    $script = modeScript($job, $this->worker);

    expect($script)->toContain('यह काम घर से करने का है। क्या आप घर से यह काम कर पाएँगे?')
        ->toContain('क्या आपके घर पर इस काम के लिए जगह और ज़रूरी सामान है?')
        ->toContain('interview phone या video call पर होगा')
        ->not->toContain('वहाँ आकर काम करने में')
        ->not->toContain('interview के लिए वहाँ आ पाएँगे');
});

it('lets the karigar choose on a job that can be either, and asks about home only if they pick it', function () {
    $job = $this->employer->jobListings()->create(modeJobPayload([
        'status' => JobStatus::Active, 'work_mode' => 'both', 'interview_mode' => 'on_site',
    ]));

    $script = modeScript($job, $this->worker);

    expect($script)->toContain('यह काम Jaipur में आकर भी हो सकता है, और घर से भी। आप कैसे करना चाहेंगे?')
        ->toContain('Only if they choose to work from home: "क्या आपके घर पर इस काम के लिए जगह और ज़रूरी सामान है?"')
        ->toContain('interview के लिए Jaipur में employer की जगह पर आना होगा');
});

it('starts the confirm-interview modal from how the job interviews', function () {
    $job = $this->employer->jobListings()->create(modeJobPayload(['status' => JobStatus::Active, 'interview_mode' => 'remote']));

    $this->withoutVite()->actingAs($this->employer)->get("/employer/jobs/{$job->id}/applicants")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('job.interview_mode', 'phone'));
});
