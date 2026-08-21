<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\UserRole;
use App\Jobs\PlaceScreeningCall;
use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\ScreeningCallCompleted;
use App\Services\AiMatcher;
use App\Services\Screening\ScreeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    config([
        // No AI key => AiMatcher's deterministic heuristic, so scores are exact.
        'services.ai.key' => null,
        'screening.from_number' => '+918000000000',
        'screening.webhook_secret' => 'test-secret',
        // Mid-window, so the calling-hours guard never interferes.
        'screening.window.start' => '00:00',
        'screening.window.end' => '23:59',
    ]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011']);
    $this->worker->workerProfile()->create([
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'city' => 'Chennai',
        'available' => true,
        'experience_years' => 5,
        'spoken_languages' => ['Hindi', 'Tamil'],
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 3,
        'wage_min' => 800,
        'wage_max' => 1200,
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

it('calls a strong match once the admin turns screening calls on', function () {
    Queue::fake();
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScreeningService::ENABLED_KEY, '1');

    (new ScoreApplication($this->application->id))->handle(app(AiMatcher::class));

    Queue::assertPushed(PlaceScreeningCall::class, fn ($job) => $job->applicationId === $this->application->id);
});

it('shortlists without calling while screening calls are off', function () {
    Queue::fake();
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScreeningService::ENABLED_KEY, '0');

    (new ScoreApplication($this->application->id))->handle(app(AiMatcher::class));

    expect($this->application->refresh()->shortlisted_at)->not->toBeNull();
    Queue::assertNotPushed(PlaceScreeningCall::class);
});

it('records the call and the language it was held in', function () {
    runPlaceCall();

    $call = ScreeningCall::sole();

    expect($call->job_application_id)->toBe($this->application->id)
        ->and($call->status)->toBe(ScreeningCallStatus::Dialing)
        ->and($call->provider)->toBe('stub')
        ->and($call->attempt)->toBe(1)
        // The worker's profile lists Hindi first.
        ->and($call->language)->toBe('hi')
        ->and($call->provider_call_id)->toStartWith('stub-');
});

it('will not call without a caller-id number', function () {
    config(['screening.from_number' => null]);

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0);
});

it('will not call an applicant who already has an interview', function () {
    $this->application->update(['interview_at' => now()->addDay()]);

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0);
});

it('lets an employer queue a call from the applicant screen', function () {
    Queue::fake();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls")
        ->assertStatus(202);

    Queue::assertPushed(PlaceScreeningCall::class);
});

it('keeps other employers away from the applicant', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Rival Builders']);

    $this->actingAs($other, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls")
        ->assertForbidden();
});

it('refuses a webhook with a bad signature', function () {
    postWebhook(['call_id' => 'whatever'], 'wrong-secret')->assertStatus(401);
});

it('refuses every webhook when no secret is configured', function () {
    config(['screening.webhook_secret' => null]);

    postWebhook(['call_id' => 'whatever'])->assertStatus(503);
});

it('records an interested worker\'s slot without booking it', function () {
    $call = placeCall();
    $slot = now()->addDays(2)->setTime(11, 0);

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => $slot->toIso8601String(),
        'proposed_mode' => 'in_person',
        'summary' => 'Worker is available and can come to the site.',
        'transcript' => 'Agent: Namaste... Worker: Haan ji, main aa sakta hoon.',
        'duration_seconds' => 74,
    ])->assertOk()->assertJsonPath('handled', true);

    $call->refresh();

    expect($call->outcome)->toBe(ScreeningOutcome::Interested)
        ->and($call->proposed_interview_at->format('Y-m-d H:i'))->toBe($slot->format('Y-m-d H:i'))
        // The agent said "in_person"; the app only knows "site".
        ->and($call->proposed_mode)->toBe('site')
        ->and($call->duration_seconds)->toBe(74)
        ->and($call->awaitingConfirmation())->toBeTrue()
        // The agent proposes; it never books.
        ->and($this->application->refresh()->interview_at)->toBeNull();

    Notification::assertSentTo($this->employer, ScreeningCallCompleted::class);
    Notification::assertNotSentTo($this->worker, InterviewScheduledNotification::class);
});

it('books the interview when the employer confirms', function () {
    $call = placeCall();
    $slot = now()->addDays(2)->setTime(11, 0);

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => $slot->toIso8601String(),
        'proposed_mode' => 'in_person',
        'summary' => 'Available Thursday morning.',
    ])->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/screening-calls/{$call->id}/confirm")
        ->assertOk()
        ->assertJsonPath('call.employer_confirmed', true);

    $application = $this->application->refresh();

    expect($application->interview_at->format('Y-m-d H:i'))->toBe($slot->format('Y-m-d H:i'))
        ->and($application->interview_mode)->toBe('site')
        ->and($application->shortlisted_at)->not->toBeNull();

    Notification::assertSentTo($this->worker, InterviewScheduledNotification::class);
});

it('lets the employer move the slot while confirming', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => now()->addDays(2)->setTime(11, 0)->toIso8601String(),
    ])->assertOk();

    $moved = now()->addDays(3)->setTime(16, 30);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/screening-calls/{$call->id}/confirm", [
            'interview_at' => $moved->toIso8601String(),
            'mode' => 'phone',
        ])
        ->assertOk();

    $application = $this->application->refresh();

    expect($application->interview_at->format('Y-m-d H:i'))->toBe($moved->format('Y-m-d H:i'))
        ->and($application->interview_mode)->toBe('phone');
});

it('ignores a slot the agent mis-heard as being in the past', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => now()->subDay()->toIso8601String(),
    ])->assertOk();

    expect($call->refresh()->proposed_interview_at)->toBeNull()
        ->and($call->awaitingConfirmation())->toBeFalse();
});

it('tells the employer when the worker is not interested', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'not_interested',
        'summary' => 'Worker has already taken another job.',
    ])->assertOk();

    expect($call->refresh()->outcome)->toBe(ScreeningOutcome::NotInterested)
        ->and($call->proposed_interview_at)->toBeNull();

    Notification::assertSentTo($this->employer, ScreeningCallCompleted::class);
});

it('rejects the application when the worker says no on the call', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'not_interested',
    ])->assertOk();

    expect($this->application->refresh()->status)->toBe(ApplicationStatus::Rejected)
        ->and($this->application->status_changed_at)->not->toBeNull();
});

it('rejects the application when the worker already has work', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'already_placed',
    ])->assertOk();

    expect($this->application->refresh()->status)->toBe(ApplicationStatus::Rejected);
});

it('leaves the application alone when the answer was not a clear no', function (string $outcome) {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => $outcome,
    ])->assertOk();

    expect($this->application->refresh()->status)->toBe(ApplicationStatus::Pending);
})->with(['interested', 'callback_later', 'unclear']);

it('does not overrule a decision the employer already made', function () {
    $call = placeCall();
    $this->application->update(['status' => ApplicationStatus::Accepted, 'status_changed_at' => now()]);

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'not_interested',
    ])->assertOk();

    expect($this->application->refresh()->status)->toBe(ApplicationStatus::Accepted);
});

it('refuses to confirm a call with no slot on it', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'not_interested',
    ])->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/screening-calls/{$call->id}/confirm")
        ->assertStatus(422)
        ->assertJsonPath('code', 'no_proposed_slot');
});

it('tries again after a no-answer', function () {
    Queue::fake();
    $call = placeCall();

    postWebhook(['call_id' => $call->provider_call_id, 'status' => 'no_answer'])->assertOk();

    expect($call->refresh()->status)->toBe(ScreeningCallStatus::NoAnswer);

    Queue::assertPushed(PlaceScreeningCall::class, fn ($job) => $job->attempt === 2);
    Notification::assertNothingSentTo($this->employer);
});

it('gives up after the last attempt', function () {
    Queue::fake();
    config(['screening.max_attempts' => 2]);

    $call = placeCall();
    $call->update(['attempt' => 2]);

    postWebhook(['call_id' => $call->provider_call_id, 'status' => 'no_answer'])->assertOk();

    Queue::assertNotPushed(PlaceScreeningCall::class);
});

it('ignores a repeated callback for a finished call', function () {
    $call = placeCall();

    postWebhook(['call_id' => $call->provider_call_id, 'status' => 'completed', 'outcome' => 'interested'])->assertOk();
    postWebhook(['call_id' => $call->provider_call_id, 'status' => 'completed', 'outcome' => 'not_interested'])->assertOk();

    expect($call->refresh()->outcome)->toBe(ScreeningOutcome::Interested);
    Notification::assertSentTimes(ScreeningCallCompleted::class, 1);
});

it('answers a callback for a call it does not know about', function () {
    postWebhook(['call_id' => 'stub-nobody', 'status' => 'completed'])
        ->assertOk()
        ->assertJsonPath('handled', false);
});

it('never shows the employer the worker\'s number', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'transcript' => 'Worker gave their availability.',
    ])->assertOk();

    $body = $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/applicants/{$this->application->id}/screening-calls")
        ->assertOk()
        ->getContent();

    expect($body)->not->toContain('9876500011');
});

it('holds a call outside the permitted hours until the window opens', function () {
    Queue::fake();
    config(['screening.window.start' => '10:00', 'screening.window.end' => '19:00']);

    $this->travelTo(now()->setTimezone('Asia/Kolkata')->setTime(23, 30));

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0);
    Queue::assertPushed(PlaceScreeningCall::class, fn ($job) => $job->holds === 1);
});

it('stops re-queueing a held call instead of looping', function () {
    Queue::fake();
    config(['screening.window.start' => '10:00', 'screening.window.end' => '19:00']);

    $this->travelTo(now()->setTimezone('Asia/Kolkata')->setTime(23, 30));

    // A queue driver that ignores delays would re-enter this immediately; the
    // hold counter is what stops that becoming an infinite loop.
    (new PlaceScreeningCall($this->application->id, 1, 1))->handle(app(ScreeningService::class));

    expect(ScreeningCall::count())->toBe(0);
    Queue::assertNotPushed(PlaceScreeningCall::class);
});

/**
 * Place a call through the stub agent and return its row.
 *
 * Jobs are run by calling handle() rather than dispatch_sync() because
 * Queue::fake() suppresses dispatch_sync, and several of these tests need the
 * first call to really happen while capturing the retry it queues.
 */
function placeCall(): ScreeningCall
{
    runPlaceCall();

    return ScreeningCall::latest('id')->sole();
}

it('never calls a worker who opted out', function () {
    $this->worker->workerProfile->update(['screening_calls_opted_out' => true]);

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0)
        ->and(app(ScreeningService::class)->blocker($this->application))->toBe('worker_opted_out');
});

it('keeps the opt-out across a new application', function () {
    // The refusal lives on the profile, not the application, so applying to
    // something else must not quietly re-enable calls.
    $this->worker->workerProfile->update(['screening_calls_opted_out' => true]);

    $another = $this->employer->jobListings()->create([
        'title' => 'Second plumbing job',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 1,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);

    $this->application = JobApplication::create([
        'job_listing_id' => $another->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0);
});

it('lets a worker switch screening calls off from their profile', function () {
    $this->actingAs($this->worker)
        ->patch('/worker/profile', ['screening_calls_opted_out' => true])
        ->assertRedirect();

    expect($this->worker->workerProfile->refresh()->screening_calls_opted_out)->toBeTrue();
});

it('dials in E.164, whatever the worker typed', function () {
    expect(ScreeningService::toE164('9876500011'))->toBe('+919876500011')
        ->and(ScreeningService::toE164('098765 00011'))->toBe('+919876500011')
        ->and(ScreeningService::toE164('+91 98765-00011'))->toBe('+919876500011')
        ->and(ScreeningService::toE164('919876500011'))->toBe('+919876500011')
        ->and(ScreeningService::toE164('+12675092030'))->toBe('+12675092030')
        // Too short to be a phone number — refuse rather than dial something.
        ->and(ScreeningService::toE164('12345'))->toBeNull()
        ->and(ScreeningService::toE164(null))->toBeNull();
});

it('will not call a worker whose number makes no sense', function () {
    $this->worker->workerProfile->update(['phone' => '12345']);
    $this->worker->update(['phone' => '12345']);

    runPlaceCall();

    expect(ScreeningCall::count())->toBe(0);
});

// ── The web app (Inertia), which mirrors the mobile API ─────────────

it('shows the call and the proposed slot on the applicants page', function () {
    $call = placeCall();
    $slot = now()->addDays(2)->setTime(11, 0);

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => $slot->toIso8601String(),
        'proposed_mode' => 'in_person',
        'summary' => 'Available Thursday morning.',
    ])->assertOk();

    $this->actingAs($this->employer)
        ->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page
            ->where('applications.0.screening.call.outcome', 'interested')
            ->where('applications.0.screening.call.awaiting_confirmation', true)
            ->where('applications.0.screening.call.proposed_mode', 'site')
            // Already screened, so no second call is offered.
            ->where('applications.0.screening.can_call', false)
        );
});

it('lets an employer queue a call from the web applicants page', function () {
    Queue::fake();

    $this->actingAs($this->employer)
        ->post("/employer/applications/{$this->application->id}/screening-call")
        ->assertRedirect();

    Queue::assertPushed(PlaceScreeningCall::class);
});

it('books the interview when the employer confirms on the web', function () {
    $call = placeCall();
    $moved = now()->addDays(3)->setTime(16, 30);

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => now()->addDays(2)->setTime(11, 0)->toIso8601String(),
    ])->assertOk();

    $this->actingAs($this->employer)
        ->post("/employer/screening-calls/{$call->id}/confirm", [
            'interview_at' => $moved->toIso8601String(),
            'mode' => 'video',
        ])
        ->assertRedirect();

    $application = $this->application->refresh();

    expect($application->interview_at->format('Y-m-d H:i'))->toBe($moved->format('Y-m-d H:i'))
        ->and($application->interview_mode)->toBe('video')
        ->and($call->refresh()->employer_confirmed)->toBeTrue();

    Notification::assertSentTo($this->worker, InterviewScheduledNotification::class);
});

it('keeps another employer off the web confirm route', function () {
    $call = placeCall();

    postWebhook([
        'call_id' => $call->provider_call_id,
        'status' => 'completed',
        'outcome' => 'interested',
        'proposed_interview_at' => now()->addDays(2)->setTime(11, 0)->toIso8601String(),
    ])->assertOk();

    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Rival Builders']);

    $this->actingAs($other)
        ->post("/employer/screening-calls/{$call->id}/confirm")
        ->assertForbidden();

    expect($this->application->refresh()->interview_at)->toBeNull();
});

it('hides screening entirely when no caller id is configured', function () {
    config(['screening.from_number' => null]);

    $this->actingAs($this->employer)
        ->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertInertia(fn ($page) => $page->where('applications.0.screening', null));
});

function runPlaceCall(?int $attempt = null): void
{
    (new PlaceScreeningCall(test()->application->id, $attempt ?? 1))
        ->handle(app(ScreeningService::class));
}

/**
 * POST the provider's result callback.
 *
 * @param  array<string, mixed>  $payload
 */
function postWebhook(array $payload, string $secret = 'test-secret'): TestResponse
{
    return test()->postJson('/api/v1/webhooks/screening-call', $payload, [
        'X-Screening-Signature' => $secret,
    ]);
}
