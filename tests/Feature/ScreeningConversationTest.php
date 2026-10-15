<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\ScreeningCallStatus;
use App\Enums\ScreeningOutcome;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Om Electricals', 'city' => 'Kanpur', 'state' => 'Uttar Pradesh']);
    giveJobPlan($this->employer);

    $job = $this->employer->jobListings()->create([
        'title' => 'Electrician Helper', 'description' => 'Wiring work.', 'category' => 'Electrician',
        'skills' => ['wiring'], 'status' => JobStatus::Active, 'vacancies' => 2, 'city' => 'Kanpur', 'state' => 'Uttar Pradesh',
        'published_at' => now(),
    ]);

    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => 'Atul']);
    $worker->workerProfile()->create(['skills' => ['Electrician'], 'city' => 'Kanpur', 'available' => true]);

    $this->application = JobApplication::create([
        'job_listing_id' => $job->id, 'worker_id' => $worker->id, 'status' => ApplicationStatus::Pending,
        'ai_score' => 82, 'ai_recommendation' => 'strong_match', 'ai_summary' => 'Good fit.', 'ai_scored_at' => now(),
    ]);

    $this->call = ScreeningCall::create([
        'job_application_id' => $this->application->id, 'worker_id' => $worker->id, 'provider' => 'livekit',
        'status' => ScreeningCallStatus::Completed, 'language' => 'hi', 'attempt' => 1,
        'outcome' => ScreeningOutcome::Interested, 'summary' => 'Interested, can start tomorrow.',
        'transcript' => "assistant: नमस्ते, क्या आप अभी भी interested हैं?\nuser: हम्म।\nuser: हाँ जी।\nassistant: कितनी salary चाहिए?\nuser: पच्चीस हज़ार।",
    ]);
});

it('splits the transcript into turns and joins a karigar who paused mid-answer', function () {
    expect($this->call->conversation())->toBe([
        ['speaker' => 'agent', 'text' => 'नमस्ते, क्या आप अभी भी interested हैं?'],
        ['speaker' => 'karigar', 'text' => 'हम्म। हाँ जी।'],
        ['speaker' => 'agent', 'text' => 'कितनी salary चाहिए?'],
        ['speaker' => 'karigar', 'text' => 'पच्चीस हज़ार।'],
    ])->and($this->call->hasConversation())->toBeTrue()
        ->and((new ScreeningCall)->conversation())->toBe([]);
});

it('gives the employer the conversation on the web', function () {
    $this->actingAs($this->employer)
        ->getJson("/employer/screening-calls/{$this->call->id}/conversation")
        ->assertOk()
        ->assertJsonPath('worker_name', 'Atul')
        ->assertJsonPath('summary', 'Interested, can start tomorrow.')
        ->assertJsonCount(4, 'conversation')
        ->assertJsonPath('conversation.1.speaker', 'karigar');
});

it('keeps the conversation from another employer', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Someone Else']);

    $this->actingAs($other)
        ->getJson("/employer/screening-calls/{$this->call->id}/conversation")
        ->assertForbidden();

    $this->actingAs($other, 'sanctum')
        ->getJson("/api/v1/employer/screening-calls/{$this->call->id}")
        ->assertForbidden();
});

it('returns one call with its conversation to the employer app', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/screening-calls/{$this->call->id}")
        ->assertOk()
        ->assertJsonPath('call.has_conversation', true)
        ->assertJsonPath('call.conversation.0.speaker', 'agent')
        ->assertJsonCount(4, 'call.conversation');
});

it('puts the latest call and a readable AI label on the applicant for the app', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/applicants/{$this->application->id}")
        ->assertOk()
        ->assertJsonPath('data.ai.recommendation', 'strong_match')
        ->assertJsonPath('data.ai.recommendation_label', 'Strong match')
        ->assertJsonPath('data.screening_call.id', $this->call->id)
        ->assertJsonPath('data.screening_call.outcome', 'interested')
        ->assertJsonPath('data.screening_call.summary', 'Interested, can start tomorrow.')
        ->assertJsonPath('data.screening_call.has_conversation', true);
});
