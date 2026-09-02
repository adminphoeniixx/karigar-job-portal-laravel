<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\JobApplication;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\InterviewScheduledNotification;
use App\Notifications\JobInviteNotification;
use App\Notifications\NewMessageNotification;
use App\Services\ResumeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->worker->workerProfile()->create([
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'available' => true,
        'experience_years' => 5,
    ]);

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
});

function applyToJob(): JobApplication
{
    return JobApplication::create([
        'job_listing_id' => test()->job->id,
        'worker_id' => test()->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);
}

it('accepts the post-job fields the app sends', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', [
            'title' => 'Electrician needed',
            'description' => 'Wiring for a new floor',
            'vacancies' => 2,
            'experience_min' => 3,
            'shift' => 'flexible',
            'contact_mode' => 'apply',
            'requires_worker_fee' => false,
            'status' => 'active',
        ])
        ->assertCreated()
        ->assertJsonPath('job.experience_min', 3)
        ->assertJsonPath('job.shift', 'flexible');
});

it('saves the registration wizard fields on the business profile', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', [
            'hiring_as' => 'business',
            'industry' => 'Construction & Real Estate',
            'company_size' => '11–50',
            'hiring_categories' => ['Plumbing', 'Electrical'],
        ])
        ->assertOk()
        ->assertJsonPath('data.hiring_as', 'business')
        ->assertJsonPath('data.company_size', '11–50')
        ->assertJsonPath('data.hiring_categories.1', 'Electrical');
});

it('schedules an interview and moves the applicant into the interview stage', function () {
    Notification::fake();
    $application = applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$application->id}/interview", [
            'interview_at' => now()->addDay()->toIso8601String(),
            'mode' => 'site',
            'note' => 'Report to gate 2',
        ])
        ->assertOk()
        ->assertJsonPath('applicant.stage', 'interview');

    Notification::assertSentTo($this->worker, InterviewScheduledNotification::class);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/jobs/{$this->job->id}/applicants?stage=interview")
        ->assertOk()
        ->assertJsonPath('counts.interview', 1)
        ->assertJsonPath('counts.pending', 0);
});

it('cancels an interview back to the shortlisted stage', function () {
    Notification::fake();
    $application = applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/applicants/{$application->id}/interview", [
            'interview_at' => now()->addDay()->toIso8601String(),
            'mode' => 'phone',
        ])->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->deleteJson("/api/v1/employer/applicants/{$application->id}/interview")
        ->assertOk()
        ->assertJsonPath('applicant.stage', 'shortlisted');
});

it('stores the hire sheet offer when accepting an applicant', function () {
    Notification::fake();
    $application = applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->patchJson("/api/v1/employer/applicants/{$application->id}/status", [
            'status' => 'accepted',
            'offered_wage' => 900,
            'start_date' => '2026-08-05',
            'message' => 'Report by 9 AM',
        ])
        ->assertOk()
        ->assertJsonPath('applicant.stage', 'hired')
        ->assertJsonPath('applicant.offer.start_date', '2026-08-05')
        ->assertJsonPath('applicant.offer.message', 'Report by 9 AM');

    expect((float) $application->fresh()->offered_wage)->toBe(900.0);
});

it('suggests matched workers and invites them once', function () {
    Notification::fake();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/jobs/{$this->job->id}/matches")
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('workers.0.user_id', $this->worker->id)
        ->assertJsonPath('workers.0.invited', false);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/invite", ['worker_id' => $this->worker->id])
        ->assertCreated();

    Notification::assertSentTo($this->worker, JobInviteNotification::class);

    // Second invite is a no-op, not a duplicate.
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/invite", ['worker_id' => $this->worker->id])
        ->assertOk();

    expect($this->job->invites()->count())->toBe(1);
});

it('drops applicants who already applied out of the matches list', function () {
    applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/jobs/{$this->job->id}/matches")
        ->assertOk()
        ->assertJsonPath('total', 0);
});

it('refuses to boost a job without credits and boosts once topped up', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/boost", ['tier' => 'standard'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'out_of_credits');

    $this->employer->employerProfile->update(['credit_balance' => 5]);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson("/api/v1/employer/jobs/{$this->job->id}/boost", ['tier' => 'turbo'])
        ->assertOk()
        ->assertJsonPath('job.boost.active', true)
        ->assertJsonPath('job.boost.tier', 'turbo')
        ->assertJsonPath('credits.purchased', 2);

    expect($this->job->fresh()->isBoosted())->toBeTrue();
});

it('reports the credit balance on the dashboard and plans screen', function () {
    $this->employer->employerProfile->update(['credit_balance' => 12]);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/dashboard')
        ->assertOk()
        ->assertJsonPath('credits.balance', 12);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/plans')
        ->assertOk()
        ->assertJsonPath('credits.purchased', 12)
        ->assertJsonStructure(['plans', 'credit_packs', 'boost_tiers', 'payment' => ['configured', 'gst_percent']]);
});

it('counts a job view for the funnel', function () {
    $this->actingAs($this->worker, 'sanctum')
        ->getJson("/api/v1/jobs/{$this->job->id}")
        ->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/jobs/{$this->job->id}")
        ->assertOk()
        ->assertJsonPath('data.stats.views', 1);
});

it('lets an employer chat with an applicant and the worker reply', function () {
    Notification::fake();
    applyToJob();

    $created = $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/conversations', [
            'worker_id' => $this->worker->id,
            'job_id' => $this->job->id,
            'body' => 'Can you start Monday?',
        ])
        ->assertCreated()
        ->json('conversation.id');

    Notification::assertSentTo($this->worker, NewMessageNotification::class);

    $this->actingAs($this->worker, 'sanctum')
        ->getJson('/api/v1/conversations')
        ->assertOk()
        ->assertJsonPath('unread_total', 1)
        ->assertJsonPath('data.0.unread', 1);

    $this->actingAs($this->worker, 'sanctum')
        ->postJson("/api/v1/conversations/{$created}/messages", ['body' => 'Yes, I can.'])
        ->assertCreated();

    // Reading the thread clears the employer's unread count.
    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/conversations/{$created}")
        ->assertOk()
        ->assertJsonCount(2, 'messages');

    expect(Conversation::find($created)->unreadCountFor($this->employer))->toBe(0);
});

it('rejects a chat request that names the wrong side', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/conversations', ['employer_id' => $this->employer->id])
        ->assertStatus(422);
});

it('blocks chatting with a worker who never applied', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/conversations', ['worker_id' => $this->worker->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'chat_not_allowed');
});

it('keeps other people out of a thread', function () {
    applyToJob();
    $outsider = User::factory()->create(['role' => UserRole::Employer->value]);

    $conversation = Conversation::create([
        'employer_id' => $this->employer->id,
        'worker_id' => $this->worker->id,
    ]);

    $this->actingAs($outsider, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}")
        ->assertForbidden();
});

it('saves settings toggles and lists signed-in devices', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/preferences', ['theme' => 'dark', 'applicant_alerts' => false])
        ->assertOk()
        ->assertJsonPath('preferences.theme', 'dark')
        ->assertJsonPath('preferences.applicant_alerts', false);

    $this->employer->createToken('iPhone 15');

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/auth/sessions')
        ->assertOk()
        ->assertJsonPath('sessions.0.device', 'iPhone 15');
});

it('exposes the new reference lists to the app', function () {
    $this->getJson('/api/v1/reference')
        ->assertOk()
        ->assertJsonPath('hiring_as.0', 'business')
        ->assertJsonPath('interview_modes.0', 'site')
        ->assertJsonFragment(['flexible']);
});

it('drafts a job description for the post-job screen', function () {
    // With no AI key the writer falls back to a template — still a draft the
    // app can drop into the textarea.
    config(['services.ai.key' => null]);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/jobs/suggest-description?'.http_build_query([
            'title' => 'Plumber for apartment project',
            'category' => 'Plumbing',
            'city' => 'Chennai',
        ]))
        ->assertOk()
        ->assertJsonCount(1, 'suggestions');
});

it('does not mistake the suggest-description path for a job id', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/jobs/suggest-description?title=ab')
        ->assertStatus(422);
});

it('streams an applicant resume to the employer holding the application', function () {
    Storage::fake(ResumeStore::DISK);

    // resume_* are guarded on the model — ResumeStore force-fills them.
    $this->worker->workerProfile->forceFill([
        'resume_path' => 'resumes/sample.pdf',
        'resume_name' => 'suresh-plumber.pdf',
        'resume_uploaded_at' => now(),
    ])->save();
    Storage::disk(ResumeStore::DISK)->put('resumes/sample.pdf', "%PDF-1.4\n%%EOF\n");

    $application = applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->get("/api/v1/employer/applicants/{$application->id}/resume")
        ->assertOk()
        ->assertDownload('suresh-plumber.pdf');

    // The payload points at this token-auth route, not the web session one.
    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/applicants/{$application->id}")
        ->assertOk()
        ->assertJsonPath('data.resume.download_url', route('api.employer.applicants.resume', $application->id));
});

it('keeps another employer away from an applicant resume', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $application = applyToJob();

    $this->actingAs($other, 'sanctum')
        ->get("/api/v1/employer/applicants/{$application->id}/resume")
        ->assertForbidden();
});

it('404s the resume download when the worker has not uploaded one', function () {
    $application = applyToJob();

    $this->actingAs($this->employer, 'sanctum')
        ->get("/api/v1/employer/applicants/{$application->id}/resume")
        ->assertNotFound();
});

it('lists everyone shortlisted across all of the employer jobs', function () {
    $application = applyToJob();

    $secondJob = $this->employer->jobListings()->create([
        'title' => 'Mason for boundary wall',
        'description' => 'Site work',
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 1,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);
    $secondApplication = JobApplication::create([
        'job_listing_id' => $secondJob->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
        'shortlisted_at' => now(),
    ]);

    // Only the shortlisted one shows up, and it carries its job.
    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/shortlisted')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $secondApplication->id)
        ->assertJsonPath('data.0.job.title', 'Mason for boundary wall');

    $application->update(['shortlisted_at' => now()->addMinute()]);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/shortlisted')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $application->id);
});

it('returns an invoice as data the app can render', function () {
    $plan = Plan::create([
        'name' => 'Starter', 'slug' => 'starter', 'price' => 399,
        'currency' => 'INR', 'interval' => 'monthly', 'is_active' => true,
    ]);
    $subscription = Subscription::create([
        'employer_id' => $this->employer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'subtotal_amount' => 399, 'gst_percent' => 18, 'gst_amount' => 71.82,
        'total_amount' => 470.82,
        'invoice_number' => 'KRG-2026-00001', 'invoiced_at' => now(),
        'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson("/api/v1/employer/invoices/{$subscription->id}")
        ->assertOk()
        ->assertJsonPath('invoice.number', 'KRG-2026-00001')
        ->assertJsonPath('invoice.total', 470.82)
        ->assertJsonPath('invoice.plan.name', 'Starter')
        ->assertJsonPath('buyer.name', 'Sri Sai Constructions');

    // And it is listed on the plans screen pointing at this endpoint.
    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/plans')
        ->assertOk()
        ->assertJsonPath('invoices.0.url', route('api.employer.invoices.show', $subscription));
});

it('keeps another employer out of an invoice', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $plan = Plan::create([
        'name' => 'Starter', 'slug' => 'starter-2', 'price' => 399,
        'currency' => 'INR', 'interval' => 'monthly', 'is_active' => true,
    ]);
    $subscription = Subscription::create([
        'employer_id' => $this->employer->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'invoice_number' => 'KRG-2026-00002', 'invoiced_at' => now(),
    ]);

    $this->actingAs($other, 'sanctum')
        ->getJson("/api/v1/employer/invoices/{$subscription->id}")
        ->assertForbidden();
});
