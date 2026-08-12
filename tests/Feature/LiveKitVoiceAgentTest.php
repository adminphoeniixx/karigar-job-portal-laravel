<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\User;
use App\Services\Screening\CallScript;
use App\Services\Screening\LiveKitVoiceAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

/**
 * The LiveKit connector, without LiveKit. Every test here asserts on the
 * request we would send, because the one thing that cannot be checked after
 * deployment is whether we asked the right thing in the first place.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('screening.livekit', [
        'url' => 'wss://karigar-test.livekit.cloud',
        'api_key' => 'APIkey123',
        'api_secret' => 'secret-value-for-signing',
        'sip_trunk_id' => 'ST_trunk123',
        'agent_name' => 'screening-agent',
        'timeout' => 20,
    ]);

    config()->set('screening.brand', 'Super Karigar');

    $employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011']);
    $worker->workerProfile()->create([
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'available' => true,
        'spoken_languages' => ['Hindi'],
    ]);

    $job = $employer->jobListings()->create([
        'title' => 'Plumber for apartment project',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing'],
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
        'job_listing_id' => $job->id,
        'worker_id' => $worker->id,
        'status' => ApplicationStatus::Pending,
    ]);

    $this->call = ScreeningCall::create([
        'job_application_id' => $this->application->id,
        'worker_id' => $worker->id,
        'provider' => 'livekit',
        'status' => ScreeningCallStatus::Queued,
        'language' => 'hi',
        'attempt' => 1,
    ]);
});

it('reports itself unconfigured until a trunk exists', function () {
    $agent = new LiveKitVoiceAgent;

    expect($agent->configured())->toBeTrue();

    // No trunk means nothing to dial through — ScreeningService must treat that
    // as "do not call", not as an error mid-dial.
    config()->set('screening.livekit.sip_trunk_id', null);

    expect((new LiveKitVoiceAgent)->configured())->toBeFalse();
});

it('dispatches the agent with the script and the number to dial', function () {
    Http::fake([
        '*/twirp/livekit.AgentDispatchService/CreateDispatch' => Http::response(['id' => 'AD_abc']),
    ]);

    $room = (new LiveKitVoiceAgent)->place($this->call, CallScript::for($this->application), '+919000000002');

    expect($room)->toStartWith('screening-'.$this->call->id.'-');

    $call = $this->call;

    Http::assertSent(function ($request) use ($room, $call) {
        $body = $request->data();
        $metadata = json_decode($body['metadata'], true, 512, JSON_THROW_ON_ERROR);

        return $body['room'] === $room
            && $body['agent_name'] === 'screening-agent'
            && $metadata['screening_call_id'] === $call->id
            && $metadata['dial']['sip_call_to'] === '+919000000002'
            && $metadata['dial']['sip_trunk_id'] === 'ST_trunk123'
            // Workers answer on noisy sites; this is what keeps the
            // transcription usable.
            && $metadata['dial']['krisp_enabled'] === true
            && filled($metadata['greeting'])
            && filled($metadata['instructions']);
    });
});

it('signs the dispatch with a short-lived token carrying sip admin', function () {
    Http::fake(['*' => Http::response(['id' => 'AD_abc'])]);

    (new LiveKitVoiceAgent)->place($this->call, CallScript::for($this->application), '+919000000002');

    Http::assertSent(function ($request) {
        $jwt = str_replace('Bearer ', '', $request->header('Authorization')[0]);
        [$header, $claims, $signature] = explode('.', $jwt);

        $decode = fn (string $part) => json_decode(
            base64_decode(strtr($part, '-_', '+/')), true, 512, JSON_THROW_ON_ERROR
        );

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$claims, 'secret-value-for-signing', true)
        ), '+/', '-_'), '=');

        $body = $decode($claims);

        return $decode($header)['alg'] === 'HS256'
            && hash_equals($expected, $signature)
            && $body['iss'] === 'APIkey123'
            // Without sip.admin LiveKit refuses the dial rather than placing it.
            && $body['video']['sip']['admin'] === true
            && $body['exp'] - $body['iat'] <= 60;
    });
});

it('talks to the https form of the websocket url', function () {
    Http::fake(['*' => Http::response(['id' => 'AD_abc'])]);

    (new LiveKitVoiceAgent)->place($this->call, CallScript::for($this->application), '+919000000002');

    Http::assertSent(fn ($request) => str_starts_with(
        $request->url(),
        'https://karigar-test.livekit.cloud/twirp/',
    ));
});

it('throws when LiveKit refuses the dispatch', function () {
    Http::fake(['*' => Http::response(['msg' => 'trunk not found'], 404)]);

    expect(fn () => (new LiveKitVoiceAgent)->place($this->call, CallScript::for($this->application), '+919000000002'))
        ->toThrow(RuntimeException::class, 'trunk not found');
});

it('rejects a webhook naming a room it never created', function () {
    $agent = new LiveKitVoiceAgent;

    expect($agent->verifyWebhook(['call_id' => 'screening-12-abcdef'], 'secret'))->toBeTrue()
        ->and($agent->verifyWebhook(['call_id' => 'stub-whatever'], 'secret'))->toBeFalse()
        ->and($agent->verifyWebhook([], 'secret'))->toBeFalse();
});

it('reads the proposed slot as IST, not UTC', function () {
    $result = (new LiveKitVoiceAgent)->parseWebhook([
        'call_id' => 'screening-1-abc',
        'status' => ScreeningCallStatus::Completed->value,
        'outcome' => 'interested',
        'proposed_interview_at' => '2026-08-20 11:00:00',
        'duration_seconds' => 96,
    ]);

    // Read as UTC this would book 16:30 IST — five and a half hours after the
    // worker said they would come.
    expect($result->proposedInterviewAt->timezone->getName())->toBe('Asia/Kolkata')
        ->and($result->proposedInterviewAt->format('H:i'))->toBe('11:00')
        ->and($result->durationSeconds)->toBe(96)
        ->and($result->status)->toBe(ScreeningCallStatus::Completed);
});
