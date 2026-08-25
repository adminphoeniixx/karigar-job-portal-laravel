<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\JobInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->worker = User::factory()->create([
        'role' => UserRole::Worker->value,
        'name' => 'Ramesh Kumar',
        'email' => 'ramesh@example.com',
    ]);

    $this->employer = User::factory()->create([
        'role' => UserRole::Employer->value,
        'name' => 'Sharma Builders',
    ]);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes',
        'status' => JobStatus::Active->value, 'vacancies' => 1,
        'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);

    $this->application = $this->job->applications()->create([
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
        'interview_at' => now()->addDays(2)->setTime(11, 0),
        'interview_mode' => 'site',
    ]);
});

function seedTemplate(string $key, string $subject, string $body, bool $active = true): void
{
    EmailTemplate::create([
        'key' => $key,
        'name' => $key,
        'subject' => $subject,
        'body_html' => $body,
        'is_active' => $active,
    ]);
}

it('emails the worker when an interview is scheduled, with the placeholders filled in', function () {
    Mail::fake();
    seedTemplate(
        'interview_scheduled',
        'Interview for {{ job_title }}',
        '<p>{{ worker_name }} — {{ employer_name }} — {{ interview_at }} — {{ interview_mode }}</p>',
    );

    $this->worker->notify(new InterviewScheduledNotification($this->application));

    Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail) {
        expect($mail->subjectLine)->toBe('Interview for Plumber needed')
            ->and($mail->bodyHtml)->toContain('Ramesh Kumar')
            ->and($mail->bodyHtml)->toContain('Sharma Builders')
            // The mode is shown as an instruction, not as the stored enum.
            ->and($mail->bodyHtml)->toContain('In person, at the worksite')
            ->and($mail->bodyHtml)->not->toContain('{{');

        return $mail->hasTo('ramesh@example.com');
    });
});

it('substitutes a sentence for an invite with no message, so the quote block is never empty', function () {
    Mail::fake();
    seedTemplate('job_invite', 'Invite', '<p>{{ note }}</p>');

    $this->worker->notify(new JobInviteNotification($this->job, null));

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => str_contains($mail->bodyHtml, 'did not leave a message'));
});

it('passes the employer note through when there is one', function () {
    Mail::fake();
    seedTemplate('job_invite', 'Invite', '<p>{{ note }}</p>');

    $this->worker->notify(new JobInviteNotification($this->job, 'Start Monday, 900 a day.'));

    Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => str_contains($mail->bodyHtml, 'Start Monday, 900 a day.'));
});

it('sends nothing when the template is switched off', function () {
    Mail::fake();
    seedTemplate('interview_scheduled', 'Interview', '<p>x</p>', active: false);

    $this->worker->notify(new InterviewScheduledNotification($this->application));

    Mail::assertNothingQueued();
});

it('skips the phone-OTP placeholder address rather than bouncing off it', function () {
    Mail::fake();
    seedTemplate('interview_scheduled', 'Interview', '<p>x</p>');

    // What a phone-only signup gets in place of a real inbox.
    $this->worker->update(['email' => '9876543210@phone.karigar']);

    $this->worker->fresh()->notify(new InterviewScheduledNotification($this->application));

    Mail::assertNothingQueued();
});

it('queues transactional mail instead of sending it inside the request', function () {
    Mail::fake();
    seedTemplate('interview_scheduled', 'Interview', '<p>x</p>');

    $this->worker->notify(new InterviewScheduledNotification($this->application));

    Mail::assertQueued(TemplatedMail::class);
    Mail::assertNotSent(TemplatedMail::class);
});
