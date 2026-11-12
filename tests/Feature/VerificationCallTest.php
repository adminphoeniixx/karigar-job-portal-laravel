<?php

use App\Enums\KycStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerificationCall;
use App\Services\Verification\VerificationCalls;
use App\Services\Verification\VerificationCallScript;
use App\Support\WorkerVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    config([
        'screening.webhook_secret' => 'test-secret',
        // Mid-window, so the calling-hours guard never interferes.
        'screening.window.start' => '00:00',
        'screening.window.end' => '23:59',
    ]);

    Setting::set('kyc_verification_enabled', '1');
    Setting::set('worker_verification_enabled', '1');

    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
});

function unverifiedKarigar(array $attributes = []): User
{
    $user = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011', ...$attributes]);
    $user->workerProfile()->create(['city' => 'Jaipur', 'available' => true, 'spoken_languages' => ['Hindi']]);

    return $user->fresh();
}

function unverifiedHirer(?string $businessType = null): User
{
    $user = User::factory()->create(['role' => UserRole::Employer->value, 'phone' => '98765'.fake()->unique()->numerify('#####')]);
    $user->employerProfile()->create(['company_name' => 'Jaipur Handlooms', 'city' => 'Jaipur', 'business_type' => $businessType]);

    return $user->fresh();
}

function switchVerificationCallsOn(string $since): void
{
    Setting::set(VerificationCalls::ENABLED_KEY, '1');
    Setting::set(VerificationCalls::SINCE_KEY, now()->parse($since)->toIso8601String());
}

it('explains DigiLocker step by step to a karigar who has not started KYC', function () {
    $script = VerificationCallScript::for(unverifiedKarigar(['name' => 'Ramesh Kumar']), VerificationCall::STATE_NOT_STARTED);

    expect($script->greeting)->toContain('Ramesh Kumar')->toContain('रिकॉर्ड')
        ->and($script->instructions)
        ->toContain('Verify with DigiLocker')
        ->toContain('OTP')
        ->toContain('Allow')
        ->toContain('उसी समय verify हो जाएगा')
        // Never asks for a number or an OTP on the phone.
        ->toContain('NEVER ask for')
        ->toContain('हम कभी भी phone पर OTP या Aadhaar number नहीं माँगते')
        ->not->toContain('PAN card');
});

it('tells a karigar they must verify to apply only while that is the rule', function () {
    Setting::set(WorkerVerification::KEY, '1');
    expect(VerificationCallScript::for(unverifiedKarigar(), 'not_started')->instructions)->toContain('jobs पर apply कर पाएँगे');

    Setting::set(WorkerVerification::KEY, '0');
    expect(VerificationCallScript::for(unverifiedKarigar(['phone' => '9876500099']), 'not_started')->instructions)
        ->toContain('verified का निशान')
        ->not->toContain('jobs पर apply कर पाएँगे');
});

it('gives an employer the documents of their business type, or asks for it', function () {
    $individual = VerificationCallScript::for(unverifiedHirer('individual'), 'not_started')->instructions;
    expect($individual)->toContain('Individual (hiring for myself)')->toContain('Sole proprietorship:');

    $unknown = VerificationCallScript::for(unverifiedHirer(), 'not_started')->instructions;
    expect($unknown)->toContain('आप अपने लिए hiring कर रहे हैं, या आपकी कोई दुकान, firm या company है?')
        ->toContain('GST certificate');
});

it('tells a rejected account why, before the steps', function () {
    $worker = unverifiedKarigar();
    $worker->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Rejected, 'remarks' => 'Aadhaar photo is blurry']);

    $script = VerificationCallScript::for($worker->fresh(), VerificationCall::STATE_REJECTED);

    expect($script->instructions)->toContain('Aadhaar photo is blurry')->toContain('approve नहीं हो पाई');
});

it('only explains to accounts with KYC still to do', function () {
    $fresh = unverifiedKarigar();
    $pending = unverifiedKarigar(['phone' => '9876500033']);
    $pending->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Pending]);
    $verified = unverifiedKarigar(['phone' => '9876500044']);
    $verified->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Verified]);

    $owner = unverifiedHirer();
    $staff = User::factory()->create(['role' => UserRole::Employer->value, 'phone' => '9876500055']);
    $owner->teamMembers()->create(['user_id' => $staff->id, 'role' => 'manager']);

    expect(VerificationCalls::stateFor($fresh))->toBe('not_started')
        ->and(VerificationCalls::stateFor($pending->fresh()))->toBeNull()
        ->and(VerificationCalls::stateFor($verified->fresh()))->toBeNull()
        ->and(VerificationCalls::stateFor($owner))->toBe('not_started')
        ->and(VerificationCalls::stateFor($staff->fresh()))->toBeNull()
        ->and(VerificationCalls::stateFor($this->admin))->toBeNull();

    Setting::set('worker_verification_enabled', '0');
    expect(VerificationCalls::stateFor($fresh->fresh()))->toBeNull();
});

it('lets the admin ring an account, and records the call', function () {
    $worker = unverifiedKarigar();

    $this->actingAs($this->admin)
        ->post("/admin/verification-calls/{$worker->id}")
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    $call = VerificationCall::sole();

    expect($call->user_id)->toBe($worker->id)
        ->and($call->status)->toBe(ScreeningCallStatus::Dialing)
        ->and($call->source)->toBe('admin')
        ->and($call->requested_by)->toBe($this->admin->id)
        ->and($call->kyc_state)->toBe('not_started')
        ->and($call->provider_call_id)->toStartWith('stub-');

    // A second press while it rings does not add another.
    $this->actingAs($this->admin)
        ->post("/admin/verification-calls/{$worker->id}")
        ->assertSessionHas('toast.type', 'error');

    expect(VerificationCall::count())->toBe(1);
});

it('books the call for the morning outside calling hours', function () {
    config(['screening.window.start' => '10:00', 'screening.window.end' => '19:00']);
    $this->travelTo(now('Asia/Kolkata')->setTime(22, 0));

    $call = app(VerificationCalls::class)->call(unverifiedKarigar(), 'admin', $this->admin);

    expect($call->status)->toBe(ScreeningCallStatus::Scheduled)
        ->and($call->scheduled_at->timezone('Asia/Kolkata')->format('H:i'))->toBe('10:00')
        ->and($call->scheduled_at->isFuture())->toBeTrue();
});

it('records the result from the shared webhook and tries a missed call again', function () {
    $call = app(VerificationCalls::class)->call(unverifiedKarigar(), 'admin', $this->admin);

    $this->postJson('/api/v1/webhooks/screening-call', [
        'call_id' => $call->provider_call_id,
        'status' => 'no_answer',
    ], ['X-Screening-Signature' => 'test-secret'])->assertOk()->assertJsonPath('handled', true);

    expect($call->refresh()->status)->toBe(ScreeningCallStatus::NoAnswer);

    $retry = VerificationCall::whereKeyNot($call->id)->sole();
    expect($retry->status)->toBe(ScreeningCallStatus::Scheduled)
        ->and($retry->attempt)->toBe(2)
        ->and($retry->source)->toBe('retry');

    $this->travel(2)->hours();
    $placed = app(VerificationCalls::class)->place($retry);

    $this->postJson('/api/v1/webhooks/screening-call', [
        'call_id' => $placed->provider_call_id,
        'status' => 'completed',
        'outcome' => 'will_verify',
        'summary' => 'Ramesh answered and will verify with DigiLocker tonight.',
        'transcript' => "assistant: नमस्ते\nuser: हाँ",
        'duration_seconds' => 140,
    ], ['X-Screening-Signature' => 'test-secret'])->assertOk();

    expect($placed->refresh()->outcome)->toBe('will_verify')
        ->and($placed->summary)->toContain('DigiLocker')
        ->and($placed->duration_seconds)->toBe(140)
        ->and(VerificationCall::count())->toBe(2);
});

it('cancels a booked call when the account verified meanwhile', function () {
    config(['screening.window.start' => '10:00', 'screening.window.end' => '19:00']);
    $this->travelTo(now('Asia/Kolkata')->setTime(22, 0));
    $worker = unverifiedKarigar();
    $call = app(VerificationCalls::class)->call($worker, 'admin', $this->admin);

    $worker->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Verified]);

    expect(app(VerificationCalls::class)->place($call)->status)->toBe(ScreeningCallStatus::Cancelled)
        ->and($call->failure_reason)->toBe('nothing_to_explain');
});

it('rings automatically only accounts made after the switch, a day after sign-up', function () {
    switchVerificationCallsOn('-3 days');

    $before = unverifiedKarigar(['created_at' => now()->subDays(5)]);
    $new = unverifiedKarigar(['phone' => '9876500033', 'created_at' => now()->subDays(2)]);
    $tooSoon = unverifiedKarigar(['phone' => '9876500044', 'created_at' => now()->subHours(3)]);
    $sent = unverifiedKarigar(['phone' => '9876500055', 'created_at' => now()->subDays(2)]);
    $sent->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Pending]);

    expect(app(VerificationCalls::class)->run())->toBe(['booked' => 1, 'placed' => 0])
        ->and(VerificationCall::sole()->user_id)->toBe($new->id)
        ->and(VerificationCall::sole()->source)->toBe('auto');

    // Once is enough: the next sweep leaves them alone.
    app(VerificationCalls::class)->run();
    expect(VerificationCall::count())->toBe(1);
});

it('rings a rejected account a day after the rejection, once', function () {
    switchVerificationCallsOn('-10 days');
    $worker = unverifiedKarigar(['created_at' => now()->subDays(5)]);
    $worker->kyc()->create([
        'aadhaar_number' => '123412341234',
        'status' => KycStatus::Rejected,
        'remarks' => 'Blurry photo',
        'reviewed_at' => now()->subHours(30),
    ]);
    // It was rung before the rejection, about not having started.
    VerificationCall::create([
        'user_id' => $worker->id, 'kyc_state' => 'not_started', 'provider' => 'stub',
        'status' => ScreeningCallStatus::Completed,
    ])->forceFill(['created_at' => now()->subDays(4)])->save();

    expect(app(VerificationCalls::class)->run()['booked'])->toBe(1);
    expect(VerificationCall::latest('id')->first()->kyc_state)->toBe('rejected');

    expect(app(VerificationCalls::class)->run()['booked'])->toBe(0);
});

it('rings nobody automatically while the switch is off', function () {
    unverifiedKarigar(['created_at' => now()->subDays(2)]);

    expect(app(VerificationCalls::class)->run()['booked'])->toBe(0);
});

it('starts the clock when the admin switches automatic calls on', function () {
    $this->actingAs($this->admin)->patch('/admin/settings', [
        'first_post_free_enabled' => true,
        'kyc_verification_enabled' => true,
        'ai_auto_shortlist_enabled' => false,
        'ai_auto_shortlist_threshold' => 75,
        'ai_auto_reject_enabled' => false,
        'ai_auto_reject_below' => 20,
        'ai_screening_call_enabled' => false,
        'ai_verification_call_enabled' => true,
    ])->assertSessionHasNoErrors();

    expect(VerificationCalls::autoEnabled())->toBeTrue()
        ->and(VerificationCalls::since()?->diffInMinutes(now()))->toBeLessThan(1);
});

it('shows the admin who needs verification and the calls made', function () {
    $this->withoutVite();
    $worker = unverifiedKarigar(['name' => 'Ramesh Kumar']);
    $verified = unverifiedKarigar(['phone' => '9876500033', 'name' => 'Already Done']);
    $verified->kyc()->create(['aadhaar_number' => '123412341234', 'status' => KycStatus::Verified]);
    app(VerificationCalls::class)->call($worker, 'admin', $this->admin);

    $this->actingAs($this->admin)->get('/admin/verification-calls')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/VerificationCalls')
            ->has('accounts.data', 1)
            ->where('accounts.data.0.name', 'Ramesh Kumar')
            ->where('accounts.data.0.last_call.status', 'dialing'));

    $this->actingAs($this->admin)->get('/admin/verification-calls?tab=calls')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('calls.data', 1)->where('calls.data.0.user.name', 'Ramesh Kumar'));
});
