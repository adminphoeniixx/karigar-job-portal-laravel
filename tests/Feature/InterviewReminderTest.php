<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Models\AiCreditEntry;
use App\Models\InterviewReminderCall;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\InterviewReminderCallCompleted;
use App\Notifications\InterviewReminderNotification;
use App\Services\AiCredits;
use App\Services\Screening\CallPreferences;
use App\Services\Screening\InterviewReminders;
use App\Services\Screening\ScreeningService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * The follow-up on interview day: a reminder to both sides in the morning,
 * and the short AI call that asks the karigar whether they are coming.
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
    Setting::set(AiCredits::ENABLED_KEY, '1');

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    giveJobPlan($this->employer);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);
    giveCredits($this->employer, 100);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011', 'name' => 'Ramesh Kumar']);
    $this->worker->workerProfile()->create(['skills' => ['Plumbing'], 'city' => 'Chennai', 'available' => true, 'spoken_languages' => ['Hindi']]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project', 'description' => 'Site work', 'category' => 'Plumbing',
        'skills' => ['Plumbing'], 'city' => 'Chennai', 'state' => 'Tamil Nadu', 'address' => '12, Anna Nagar 2nd Street',
        'contact_name' => 'Suresh', 'vacancies' => 3, 'wage_min' => 800, 'wage_max' => 1200,
        'contact_mode' => 'apply', 'requires_worker_fee' => false, 'status' => JobStatus::Active,
    ]);

    // 08:00 IST on interview day; the interview is at 16:00.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00', 'Asia/Kolkata'));
    $this->application = JobApplication::create([
        'job_listing_id' => $this->job->id, 'worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending,
        'shortlisted_at' => now(), 'interview_at' => CarbonImmutable::parse('2026-11-17 16:00', 'Asia/Kolkata'), 'interview_mode' => 'site',
    ]);
});

it('reminds both sides once, from 07:30 on the day of the interview', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 07:00', 'Asia/Kolkata'));
    $this->artisan('interviews:remind')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00', 'Asia/Kolkata'));
    $this->artisan('interviews:remind')->assertSuccessful();

    Notification::assertSentTo($this->worker, InterviewReminderNotification::class, fn ($n) => $n->audience === 'worker'
        && str_contains($n->toArray($this->worker)['message'], '4:00 PM at 12, Anna Nagar 2nd Street, Chennai'));
    Notification::assertSentTo($this->employer, InterviewReminderNotification::class, fn ($n) => $n->audience === 'employer');
    expect($this->application->refresh()->interview_reminded_at)->not->toBeNull();

    // A second run on the same morning sends nothing more.
    Notification::fake();
    $this->artisan('interviews:remind');
    Notification::assertNothingSent();
});

it('books and dials the AI reminder call when the employer allows it and there is time', function () {
    $this->artisan('interviews:remind')->assertSuccessful();

    // 08:00 is before the hours open: booked for 10:00, well before 16:00.
    $call = InterviewReminderCall::sole();
    expect($call->status)->toBe(ScreeningCallStatus::Scheduled)
        ->and($call->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('10:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:01', 'Asia/Kolkata'));
    $this->artisan('interviews:remind');

    expect($call->refresh()->status)->toBe(ScreeningCallStatus::Dialing)
        ->and($call->provider_call_id)->not->toBeNull()
        ->and($call->language)->toBe('hi');
});

it('makes no reminder call when the employer switched it off, or the interview is too close', function () {
    CallPreferences::fromArray([...CallPreferences::defaults()->toArray(), 'reminder_call' => false])->save($this->employer);
    $this->artisan('interviews:remind');
    expect(InterviewReminderCall::count())->toBe(0);
    Notification::assertSentTo($this->worker, InterviewReminderNotification::class);

    // Switched back on, but booked at 15:30 for a 16:00 interview: no call.
    CallPreferences::defaults()->save($this->employer);
    $this->travelTo(CarbonImmutable::parse('2026-11-17 15:30', 'Asia/Kolkata'));
    $late = JobApplication::create([
        'job_listing_id' => $this->job->id, 'worker_id' => $this->worker->id, 'status' => ApplicationStatus::Pending,
        'interview_at' => CarbonImmutable::parse('2026-11-17 16:00', 'Asia/Kolkata'), 'interview_mode' => 'site',
    ]);
    $this->application->update(['interview_reminded_at' => now()]);

    $this->artisan('interviews:remind');

    expect($late->refresh()->interview_reminded_at)->not->toBeNull()
        ->and(InterviewReminderCall::count())->toBe(0);
});

it('records what the karigar said, charges the minutes and tells the employer', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:01', 'Asia/Kolkata'));
    $this->artisan('interviews:remind');
    $call = InterviewReminderCall::sole();
    expect($call->status)->toBe(ScreeningCallStatus::Dialing);

    $this->postJson('/api/v1/webhooks/screening-call', [
        'call_id' => $call->provider_call_id, 'status' => 'completed', 'outcome' => 'reschedule',
        'proposed_interview_at' => '2026-11-18 11:00:00', 'summary' => 'Ramesh cannot come today; asked for tomorrow 11 AM.',
        'transcript' => "assistant: नमस्ते।\nuser: कल आ सकता हूँ।", 'duration_seconds' => 50,
    ], ['X-Screening-Signature' => 'test-secret'])->assertOk()->assertJson(['handled' => true]);

    $call->refresh();
    expect($call->status)->toBe(ScreeningCallStatus::Completed)
        ->and($call->outcome)->toBe('reschedule')
        ->and($call->proposed_interview_at->format('Y-m-d H:i'))->toBe('2026-11-18 11:00')
        ->and($call->credits_charged)->toBe(1)
        ->and(AiCreditEntry::where('employer_id', $this->employer->id)->where('type', AiCreditEntry::TYPE_CALL)->count())->toBe(1)
        ->and(AiCredits::for($this->employer)->balance())->toBe(99);

    Notification::assertSentTo($this->employer, InterviewReminderCallCompleted::class, fn ($n) => str_contains($n->toArray($this->employer)['message'], 'Wed 18 Nov, 11:00 AM'));

    // The applicants page shows it under the booked interview.
    $this->withoutVite()->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('applications.0.interview.reminder.outcome', 'reschedule')
            ->where('applications.0.interview.reminder.proposed_interview_label', '18 Nov 2026, 11:00 AM'));
});

it('tries once more after a missed reminder call while there is time, and the retry is the last', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:01', 'Asia/Kolkata'));
    $this->artisan('interviews:remind');
    $call = InterviewReminderCall::sole();

    app(InterviewReminders::class)->apply($call, app(ScreeningService::class)->agent()->parseWebhook([
        'call_id' => $call->provider_call_id, 'status' => 'no_answer',
    ]), ['status' => 'no_answer']);

    $retry = InterviewReminderCall::where('attempt', 2)->sole();
    expect($retry->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('10:31');
    Notification::assertNotSentTo($this->employer, InterviewReminderCallCompleted::class);

    app(InterviewReminders::class)->apply($retry, app(ScreeningService::class)->agent()->parseWebhook([
        'call_id' => 'x', 'status' => 'no_answer',
    ]), ['status' => 'no_answer']);

    expect(InterviewReminderCall::count())->toBe(2);
});

it('reminds again when the interview is moved to another time', function () {
    $this->artisan('interviews:remind');
    expect($this->application->refresh()->interview_reminded_at)->not->toBeNull();

    $this->application->update(['interview_at' => CarbonImmutable::parse('2026-11-17 17:30', 'Asia/Kolkata')]);

    expect($this->application->refresh()->interview_reminded_at)->toBeNull();

    // The booked reminder call is for the old time: cancelled when its turn comes.
    $old = InterviewReminderCall::sole();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:01', 'Asia/Kolkata'));
    $this->artisan('interviews:remind');

    expect($old->refresh()->status)->toBe(ScreeningCallStatus::Cancelled)
        ->and($old->failure_reason)->toBe('interview_moved')
        ->and(InterviewReminderCall::where('interview_at', $this->application->interview_at)->count())->toBe(1);
});

it('leaves a karigar who opted out of automated calls alone, but still reminds them', function () {
    $this->worker->workerProfile->update(['screening_calls_opted_out' => true]);

    $this->artisan('interviews:remind');

    Notification::assertSentTo($this->worker, InterviewReminderNotification::class);
    expect(InterviewReminderCall::count())->toBe(0);
});

it('tells the employer when the karigar could not be reached at all', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-17 14:50', 'Asia/Kolkata'));
    $this->artisan('interviews:remind');
    $call = InterviewReminderCall::sole();
    expect($call->status)->toBe(ScreeningCallStatus::Dialing);

    // 14:50 + 30 min retry lands inside the 45-minute lead, so there is no
    // second attempt — the employer hears it straight away.
    app(InterviewReminders::class)->apply($call, app(ScreeningService::class)->agent()->parseWebhook([
        'call_id' => $call->provider_call_id, 'status' => 'no_answer',
    ]), ['status' => 'no_answer']);

    expect(InterviewReminderCall::count())->toBe(1);
    Notification::assertSentTo($this->employer, InterviewReminderCallCompleted::class, fn ($n) => str_contains($n->toArray($this->employer)['message'], 'could not reach Ramesh Kumar'));
});
