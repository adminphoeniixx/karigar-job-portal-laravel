<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Jobs\PlaceScreeningCall;
use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiMatcher;
use App\Services\Screening\CallPreferences;
use App\Services\Screening\CallScript;
use App\Services\Screening\ScreeningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The employer's own rules for AI calls (Employer → AI Call Settings): when
 * an applicant is rung, inside which hours and days, who qualifies, the daily
 * cap per job — and the job's third interview option, "the karigar chooses".
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();
    config([
        'scout.driver' => 'null',
        'services.ai.key' => null,
        'screening.from_number' => '+918000000000',
        'screening.webhook_secret' => 'test-secret',
        'screening.window.start' => '10:00',
        'screening.window.end' => '19:00',
    ]);
    Setting::set(ScreeningService::ENABLED_KEY, '1');

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    giveJobPlan($this->employer);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);
    giveCredits($this->employer);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011']);
    $this->worker->workerProfile()->create([
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'], 'city' => 'Chennai', 'available' => true,
        'experience_years' => 5, 'spoken_languages' => ['Hindi'],
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project', 'description' => 'Site work', 'category' => 'Plumbing',
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'], 'city' => 'Chennai', 'state' => 'Tamil Nadu',
        'vacancies' => 3, 'wage_min' => 800, 'wage_max' => 1200, 'contact_mode' => 'apply',
        'requires_worker_fee' => false, 'status' => JobStatus::Active,
    ]);

    $this->application = JobApplication::create([
        'job_listing_id' => $this->job->id, 'worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending,
    ]);
});

/** A Tuesday at the given IST time. */
function istAt(string $time, int $isoWeekday = 2): CarbonImmutable
{
    return CarbonImmutable::parse('2026-11-17 '.$time, 'Asia/Kolkata')->addDays($isoWeekday - 2);
}

function saveSettings(User $employer, array $settings): void
{
    CallPreferences::fromArray([...CallPreferences::defaults()->toArray(), ...$settings])->save($employer);
}

it('changes nothing until the employer touches the settings', function () {
    $this->travelTo(istAt('12:00', 7)); // a Sunday, inside the admin's hours

    expect(CallPreferences::for($this->employer)->toArray())->toBe(CallPreferences::defaults()->toArray())
        ->and(app(ScreeningService::class)->nextCallableTime(null, $this->employer)->equalTo(now()))->toBeTrue();
});

it('narrows the hours inside the admin window and never widens them', function () {
    saveSettings($this->employer, ['window_start' => '11:00', 'window_end' => '17:00']);
    $prefs = CallPreferences::for($this->employer);

    expect($prefs->nextCallableTime(istAt('10:30'))->format('H:i'))->toBe('11:00')
        ->and($prefs->nextCallableTime(istAt('12:00'))->format('H:i'))->toBe('12:00')
        ->and($prefs->nextCallableTime(istAt('18:00'))->format('D H:i'))->toBe('Wed 11:00');

    // 08:00 is outside what the admin allows: clipped to 10:00.
    saveSettings($this->employer, ['window_start' => '08:00', 'window_end' => '21:00']);
    expect(CallPreferences::for($this->employer)->window())->toBe(['10:00', '19:00']);
});

it('skips the days the employer switched off', function () {
    saveSettings($this->employer, ['days' => [1, 2, 3, 4, 5, 6]]);

    expect(CallPreferences::for($this->employer)->nextCallableTime(istAt('12:00', 7))->format('D H:i'))->toBe('Mon 10:00');
});

it('waits the employer\'s delay before ringing a shortlisted applicant', function () {
    $this->travelTo(istAt('12:00'));
    saveSettings($this->employer, ['delay_minutes' => 60]);

    $call = app(ScreeningService::class)->onShortlisted($this->application, $this->employer);

    expect($call->status)->toBe(ScreeningCallStatus::Scheduled)
        ->and($call->source)->toBe('shortlist')
        ->and($call->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('13:00');
});

it('books "next day" for the next morning the hours open', function () {
    $this->travelTo(istAt('15:00'));
    saveSettings($this->employer, ['delay_minutes' => CallPreferences::NEXT_DAY, 'window_start' => '11:00']);

    $call = app(ScreeningService::class)->onShortlisted($this->application, $this->employer);

    expect($call->scheduled_at->timezone('Asia/Kolkata')->format('D H:i'))->toBe('Wed 11:00');
});

it('rings as soon as someone applies when the employer chose that trigger', function () {
    Queue::fake();
    $this->travelTo(istAt('12:00'));

    // Default: on shortlist only — applying books nothing.
    app(ScreeningService::class)->onApplied($this->application);
    Queue::assertNotPushed(PlaceScreeningCall::class);
    expect(ScreeningCall::count())->toBe(0);

    saveSettings($this->employer, ['trigger' => 'apply']);
    app(ScreeningService::class)->onApplied($this->application);
    Queue::assertPushed(PlaceScreeningCall::class, fn ($job) => $job->applicationId === $this->application->id);

    // With a delay it is booked instead, under the apply source.
    saveSettings($this->employer, ['trigger' => 'apply', 'delay_minutes' => 30]);
    $call = app(ScreeningService::class)->onApplied($this->application);
    expect($call->source)->toBe('apply')
        ->and($call->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('12:30');
});

it('on "only strong AI matches" waits for the score before calling an applicant', function () {
    Queue::fake();
    $this->travelTo(istAt('12:00'));
    saveSettings($this->employer, ['trigger' => 'apply', 'who' => 'score', 'min_score' => 50]);

    app(ScreeningService::class)->onApplied($this->application);
    Queue::assertNotPushed(PlaceScreeningCall::class);

    // The heuristic scores this plumber well above 50 against a plumbing job.
    (new ScoreApplication($this->application->id))->handle(app(AiMatcher::class));
    Queue::assertPushed(PlaceScreeningCall::class);

    // Under the bar: no call.
    Queue::fake();
    saveSettings($this->employer, ['trigger' => 'apply', 'who' => 'score', 'min_score' => 80]);
    $this->application->forceFill(['ai_score' => 55])->save();
    app(ScreeningService::class)->onScored($this->application);
    Queue::assertNotPushed(PlaceScreeningCall::class);
});

it('on "only the ones I shortlist myself" the AI shortlist calls nobody but a manual shortlist still does', function () {
    Queue::fake();
    $this->travelTo(istAt('12:00'));
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    saveSettings($this->employer, ['who' => 'manual']);

    (new ScoreApplication($this->application->id))->handle(app(AiMatcher::class));

    expect($this->application->refresh()->shortlisted_at)->not->toBeNull();
    Queue::assertNotPushed(PlaceScreeningCall::class);

    $call = app(ScreeningService::class)->onShortlisted($this->application, $this->employer);
    expect($call)->not->toBeNull()->and($call->status)->not->toBe(ScreeningCallStatus::Scheduled);
});

it('caps automatic calls per job per day and books the overflow for the next day', function () {
    $this->travelTo(istAt('12:00'));
    saveSettings($this->employer, ['daily_limit' => 1, 'delay_minutes' => 30]);

    $second = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500012']);
    $second->workerProfile()->create(['skills' => ['Plumbing'], 'city' => 'Chennai', 'available' => true, 'spoken_languages' => ['Hindi']]);
    $other = JobApplication::create(['job_listing_id' => $this->job->id, 'worker_id' => $second->id, 'status' => ApplicationStatus::Pending]);

    $first = app(ScreeningService::class)->onShortlisted($this->application, $this->employer);
    $overflow = app(ScreeningService::class)->onShortlisted($other, $this->employer);

    expect($first->scheduled_at->timezone('Asia/Kolkata')->format('D H:i'))->toBe('Tue 12:30')
        ->and($overflow->scheduled_at->timezone('Asia/Kolkata')->format('D H:i'))->toBe('Wed 10:00');
});

it('serves and saves the settings over the API', function () {
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/ai-call-settings')
        ->assertOk()
        ->assertJsonPath('settings.trigger', 'shortlist')
        ->assertJsonPath('settings.days', [1, 2, 3, 4, 5, 6, 7])
        ->assertJsonPath('options.window.start', '10:00')
        ->assertJsonCount(5, 'options.delays');

    $this->actingAs($this->employer, 'sanctum')->putJson('/api/v1/employer/ai-call-settings', [
        'trigger' => 'apply', 'delay_minutes' => 60, 'window_start' => '11:00', 'window_end' => '17:00',
        'days' => [1, 2, 3, 4, 5], 'who' => 'score', 'min_score' => 70, 'daily_limit' => 5, 'reminder_call' => false,
    ])->assertOk()->assertJsonPath('settings.who', 'score')->assertJsonPath('settings.reminder_call', false);

    expect(CallPreferences::for($this->employer))->trigger->toBe('apply')->minScore->toBe(70)->dailyLimit->toBe(5);

    // Outside the admin's hours, or a window that closes before it opens: refused.
    $this->actingAs($this->employer, 'sanctum')->putJson('/api/v1/employer/ai-call-settings', [
        'trigger' => 'apply', 'delay_minutes' => 0, 'window_start' => '08:00', 'window_end' => '07:00',
        'days' => [1], 'who' => 'all', 'min_score' => 0, 'daily_limit' => 0, 'reminder_call' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['window_start', 'window_end']);
});

it('has a settings page on the web that saves', function () {
    $this->withoutVite()->actingAs($this->employer)->get('/employer/ai-call-settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('employer/AiCallSettings')->where('settings.who', 'all'));

    $this->actingAs($this->employer)->patch('/employer/ai-call-settings', [
        'trigger' => 'shortlist', 'delay_minutes' => 240, 'window_start' => null, 'window_end' => null,
        'days' => [1, 2, 3, 4, 5, 6], 'who' => 'all', 'min_score' => 60, 'daily_limit' => 0, 'reminder_call' => true,
    ])->assertRedirect();

    expect(CallPreferences::for($this->employer))->delayMinutes->toBe(240)->days->toBe([1, 2, 3, 4, 5, 6]);
});

it('lets a job offer the interview either way, and the AI call asks which the karigar prefers', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', [
        'title' => 'Kadhai karigar', 'description' => 'Hand embroidery on dupattas.', 'category' => 'Kadhai / Embroidery',
        'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'work_mode' => 'on_site', 'interview_mode' => 'both',
    ])->assertCreated()
        ->assertJsonPath('job.interview_mode', 'both')
        ->assertJsonPath('job.interview_mode_label', 'Interview in person or on phone / video');

    $this->job->update(['interview_mode' => 'both']);
    $script = CallScript::for($this->application->fresh()->load('job.employer.employerProfile', 'worker.workerProfile'));

    expect($script->instructions)
        ->toContain('interview आप Chennai में employer की जगह पर आकर दे सकते हैं, या phone या video call पर। आपको कैसे ठीक रहेगा?')
        ->toContain('Only if they choose to come in person:')
        ->toContain('Only if they choose phone or video: "उस call के लिए आपको कौन सा दिन और कितने बजे ठीक रहेगा?"')
        ->and(CallScript::extractionSchema()['proposed_mode'])->toContain('"site"');
});

it('asks a remote-interview karigar when the call would suit them', function () {
    $this->job->update(['interview_mode' => 'remote']);
    $script = CallScript::for($this->application->fresh()->load('job.employer.employerProfile', 'worker.workerProfile'));

    expect($script->instructions)
        ->toContain('interview phone या video call पर होगा, आपको कहीं आना नहीं पड़ेगा। उस call के लिए आपको कौन सा दिन और कितने बजे ठीक रहेगा?')
        ->not->toContain('interview के लिए वहाँ आ पाएँगे');
});
