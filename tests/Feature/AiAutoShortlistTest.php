<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ShortlistedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    // No key => AiMatcher's deterministic skill-overlap heuristic, so these
    // tests assert exact scores without calling the model over the network.
    config(['services.ai.key' => null]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);

    // A perfect skill + city match, so the no-key heuristic scores it 90:
    // 3/3 skills * 70 + 20 same-city + min(10, 5*2) = 100, capped.
    $this->worker->workerProfile()->create([
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'city' => 'Chennai',
        'available' => true,
        'experience_years' => 5,
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 3,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);

    $this->application = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);
});

it('scores an applicant but does not shortlist while auto-shortlist is off', function () {
    Setting::set(ScoreApplication::ENABLED_KEY, '0');

    dispatch_sync(new ScoreApplication($this->application->id));
    $application = $this->application->fresh();

    expect($application->ai_score)->toBe(100)
        ->and($application->ai_recommendation)->toBe('strong_match')
        ->and($application->ai_scored_at)->not->toBeNull()
        ->and($application->shortlisted_at)->toBeNull();

    Notification::assertNothingSent();
});

it('is off by default, before any admin has touched the setting', function () {
    dispatch_sync(new ScoreApplication($this->application->id));

    expect($this->application->fresh()->shortlisted_at)->toBeNull();
});

it('shortlists and notifies the worker once the admin switches it on', function () {
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScoreApplication::THRESHOLD_KEY, '80');

    dispatch_sync(new ScoreApplication($this->application->id));

    expect($this->application->fresh()->shortlisted_at)->not->toBeNull();
    Notification::assertSentTo($this->worker, ShortlistedNotification::class);
});

it('leaves an applicant scoring below the threshold alone', function () {
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScoreApplication::THRESHOLD_KEY, '100');

    // Move the worker out of the job's city so the 20-point same-city bonus is
    // lost: 3/3 skills * 70 + 0 + min(10, 5*2) = 80.
    $this->worker->workerProfile->update(['city' => 'Madurai']);

    dispatch_sync(new ScoreApplication($this->application->id));
    $application = $this->application->fresh();

    expect($application->ai_score)->toBe(80)
        ->and($application->shortlisted_at)->toBeNull();

    Notification::assertNothingSent();
});

it('never double-shortlists an already shortlisted applicant', function () {
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScoreApplication::THRESHOLD_KEY, '80');

    $shortlistedAt = now()->subDay();
    $this->application->update(['shortlisted_at' => $shortlistedAt]);

    dispatch_sync(new ScoreApplication($this->application->id));

    expect($this->application->fresh()->shortlisted_at->toDateTimeString())
        ->toBe($shortlistedAt->toDateTimeString());

    Notification::assertNothingSent();
});

it('exposes both settings to the admin settings screen', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.ai_auto_shortlist_enabled', false)
            ->where('settings.ai_auto_shortlist_threshold', ScoreApplication::DEFAULT_THRESHOLD));
});

it('lets an admin switch auto-shortlist on and set the threshold', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->patch('/admin/settings', [
        'first_post_free_enabled' => true,
        'kyc_verification_enabled' => true,
        'ai_auto_shortlist_enabled' => true,
        'ai_auto_shortlist_threshold' => 65,
        'ai_auto_reject_enabled' => false,
        'ai_auto_reject_below' => 30,
    ])->assertRedirect();

    expect(Setting::bool(ScoreApplication::ENABLED_KEY))->toBeTrue()
        ->and(Setting::int(ScoreApplication::THRESHOLD_KEY))->toBe(65);
});

it('rejects a threshold outside the allowed range', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->patch('/admin/settings', [
        'first_post_free_enabled' => true,
        'kyc_verification_enabled' => true,
        'ai_auto_shortlist_enabled' => true,
        'ai_auto_shortlist_threshold' => 10,
    ])->assertSessionHasErrors('ai_auto_shortlist_threshold');
});

it('keeps a non-admin away from the settings screen', function () {
    $this->actingAs($this->employer)->get('/admin/settings')->assertForbidden();
});
