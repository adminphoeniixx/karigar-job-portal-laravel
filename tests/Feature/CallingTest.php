<?php

use App\Enums\ApplicationStatus;
use App\Enums\CallStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\ExpireCallSession;
use App\Models\CallSession;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\Calling\CallProvider;
use App\Services\Calling\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011']);
    $this->worker->workerProfile()->create(['skills' => ['Plumbing'], 'city' => 'Chennai', 'available' => true]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber for apartment project',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 3,
        'experience_min' => 1,
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

it('lets an employer ring an applicant and returns join credentials', function () {
    $response = $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', [
            'worker_id' => $this->worker->id,
            'job_application_id' => $this->application->id,
        ])
        ->assertCreated()
        ->assertJsonPath('call.status', 'ringing')
        ->assertJsonPath('call.direction', 'outgoing')
        ->assertJsonPath('call.counterpart.id', $this->worker->id)
        ->assertJsonPath('credentials.provider', 'stub')
        ->assertJsonPath('credentials.uid', $this->employer->id);

    $call = CallSession::sole();

    expect($call->caller_id)->toBe($this->employer->id)
        ->and($call->callee_id)->toBe($this->worker->id)
        ->and($call->job_application_id)->toBe($this->application->id)
        ->and($call->channel)->toBe($response->json('credentials.channel'));
});

it('never puts a phone number in the call payload', function () {
    $body = $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => $this->worker->id])
        ->assertCreated()
        ->getContent();

    expect($body)->not->toContain('9876500011');
});

it('refuses a call to someone with no application between you', function () {
    $stranger = User::factory()->create(['role' => UserRole::Worker->value]);
    $stranger->workerProfile()->create(['skills' => ['Painting']]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => $stranger->id])
        ->assertForbidden()
        ->assertJsonPath('code', 'not_allowed');

    expect(CallSession::count())->toBe(0);
});

it('lets a worker ring the employer they applied to', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/calls', ['employer_id' => $this->employer->id])
        ->assertCreated()
        ->assertJsonPath('call.direction', 'outgoing')
        ->assertJsonPath('call.counterpart.id', $this->employer->id);
});

it('requires the counterpart id for the caller\'s role', function () {
    // An employer passing employer_id names nobody callable.
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['employer_id' => $this->employer->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'worker_id is required.');
});

it('hands the callee their own credentials when they answer', function () {
    $call = dialWorker();

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/answer")
        ->assertOk()
        ->assertJsonPath('call.status', 'answered')
        ->assertJsonPath('call.direction', 'incoming')
        ->assertJsonPath('credentials.uid', $this->worker->id)
        ->assertJsonPath('credentials.channel', $call->channel);

    expect($call->refresh()->answered_at)->not->toBeNull();
});

it('does not let the caller answer their own call', function () {
    $call = dialWorker();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/answer")
        ->assertForbidden();

    expect($call->refresh()->status)->toBe(CallStatus::Ringing);
});

it('records a decline', function () {
    $call = dialWorker();

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/reject")
        ->assertOk()
        ->assertJsonPath('call.status', 'rejected');

    expect($call->refresh()->ended_reason)->toBe('declined')
        ->and($call->ended_at)->not->toBeNull();
});

it('logs a cancelled ring as a missed call', function () {
    $call = dialWorker();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/end")
        ->assertOk()
        ->assertJsonPath('call.status', 'missed');

    expect($call->refresh()->ended_reason)->toBe('cancelled')
        ->and($call->duration_seconds)->toBeNull();
});

it('measures how long an answered call ran', function () {
    $call = dialWorker();

    $this->actingAs($this->worker, 'sanctum')->postJson("/api/v1/calls/{$call->id}/answer")->assertOk();

    $this->travel(90)->seconds();

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/end")
        ->assertOk()
        ->assertJsonPath('call.status', 'ended')
        ->assertJsonPath('call.duration_seconds', 90);

    expect($call->refresh()->ended_reason)->toBe('hangup');
});

it('keeps outsiders out of a call they are not in', function () {
    $call = dialWorker();
    $outsider = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/calls/{$call->id}/end")->assertForbidden();
    $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/calls/{$call->id}/refresh")->assertForbidden();
});

it('refuses a second call while one is still live', function () {
    dialWorker();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => $this->worker->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'busy');
});

it('caps how often the same person can be dialled in a day', function () {
    config(['calling.limits.per_pair_daily' => 1]);

    $call = dialWorker();
    $this->actingAs($this->worker, 'sanctum')->postJson("/api/v1/calls/{$call->id}/reject")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => $this->worker->id])
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');
});

it('re-issues credentials mid-call but not once it is over', function () {
    $call = dialWorker();
    $this->actingAs($this->worker, 'sanctum')->postJson("/api/v1/calls/{$call->id}/answer")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/refresh")
        ->assertOk()
        ->assertJsonPath('credentials.channel', $call->channel);

    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/calls/{$call->id}/end")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/calls/{$call->id}/refresh")
        ->assertStatus(422)
        ->assertJsonPath('code', 'call_not_open');
});

it('marks a call missed once the ring timeout passes', function () {
    $call = dialWorker();

    $this->travel((int) config('calling.ring_timeout') + 5)->seconds();

    (new ExpireCallSession($call->id))->handle(app(CallService::class));

    expect($call->refresh()->status)->toBe(CallStatus::Missed)
        ->and($call->ended_reason)->toBe('timeout');
});

it('leaves a call alone when the expiry job fires early', function () {
    $call = dialWorker();

    // A `sync` queue ignores the delay, so the job can land immediately.
    (new ExpireCallSession($call->id))->handle(app(CallService::class));

    expect($call->refresh()->status)->toBe(CallStatus::Ringing);
});

it('shows both sides the same call in their history', function () {
    $call = dialWorker();
    $this->actingAs($this->worker, 'sanctum')->postJson("/api/v1/calls/{$call->id}/reject")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/calls')
        ->assertOk()
        ->assertJsonPath('data.0.direction', 'outgoing')
        ->assertJsonPath('data.0.counterpart.id', $this->worker->id);

    $this->actingAs($this->worker, 'sanctum')
        ->getJson('/api/v1/calls')
        ->assertOk()
        ->assertJsonPath('data.0.direction', 'incoming')
        ->assertJsonPath('data.0.counterpart.id', $this->employer->id);
});

it('stops calling entirely when no provider is configured', function () {
    config(['calling.provider' => 'agora', 'calling.agora.app_id' => null, 'calling.agora.app_certificate' => null]);
    app()->forgetInstance(CallProvider::class);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => $this->worker->id])
        ->assertStatus(503)
        ->assertJsonPath('code', 'calling_unavailable');
});

/**
 * Employer rings the worker and we get the resulting session back.
 */
function dialWorker(): CallSession
{
    test()->actingAs(test()->employer, 'sanctum')
        ->postJson('/api/v1/calls', ['worker_id' => test()->worker->id])
        ->assertCreated();

    return CallSession::latest('id')->sole();
}
