<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\ShortlistedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes',
        'status' => JobStatus::Active->value, 'vacancies' => 1,
        'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);

    $this->application = $this->job->applications()->create([
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);

    EmailTemplate::create([
        'key' => 'application_shortlisted',
        'name' => 'Shortlisted',
        'subject' => 'Shortlisted for {{ job_title }}',
        'body_html' => '<p>Hi {{ worker_name }}</p>',
        'is_active' => true,
    ]);
});

it('lets an employer shortlist an applicant, notifying and emailing the worker', function () {
    Mail::fake();
    Notification::fake();

    $this->actingAs($this->employer)
        ->post("/employer/applications/{$this->application->id}/shortlist")
        ->assertRedirect();

    expect($this->application->fresh()->shortlisted_at)->not->toBeNull();

    Notification::assertSentTo($this->worker, ShortlistedNotification::class);
    Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo($this->worker->email));
});

it('toggles the shortlist off without emailing again', function () {
    $this->application->update(['shortlisted_at' => now()]);

    Mail::fake();
    Notification::fake();

    $this->actingAs($this->employer)
        ->post("/employer/applications/{$this->application->id}/shortlist")
        ->assertRedirect();

    expect($this->application->fresh()->shortlisted_at)->toBeNull();
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

it('blocks other employers from shortlisting', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($other)
        ->post("/employer/applications/{$this->application->id}/shortlist")
        ->assertForbidden();
});

it('shows shortlisted applicants on the shortlist tab', function () {
    $this->application->update(['shortlisted_at' => now()]);

    $this->actingAs($this->employer)
        ->get('/employer/shortlisted')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('employer/Shortlisted')
            ->has('applications', 1)
            ->where('applications.0.worker.name', $this->worker->name)
            ->where('applications.0.job.title', 'Plumber needed'));
});
