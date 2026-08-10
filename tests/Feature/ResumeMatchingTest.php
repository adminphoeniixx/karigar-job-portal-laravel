<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Jobs\ScoreApplication;
use App\Models\JobApplication;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ApplicationStatusNotification;
use App\Services\AiMatcher;
use App\Services\ResumeParser;
use App\Services\ResumeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * A real resume PDF from tests/fixtures — the same two files used to demo the
 * feature: one plumber (matches the job below), one accountant (does not).
 */
function resumeFixture(string $name): UploadedFile
{
    return new UploadedFile(
        base_path("tests/fixtures/resumes/{$name}"),
        $name,
        'application/pdf',
        null,
        true,
    );
}

beforeEach(function () {
    Notification::fake();
    config(['services.ai.key' => null, 'scout.driver' => null]);

    $this->worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $this->worker->workerProfile()->create(['city' => 'Jaipur', 'skills' => []]);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Test Employer']);

    $this->job = $this->employer->jobListings()->create([
        'title' => 'Plumber needed',
        'description' => 'Urgent plumbing work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'vacancies' => 2,
        'contact_mode' => 'apply',
        'requires_worker_fee' => false,
        'status' => JobStatus::Active,
    ]);
});

// ---------------------------------------------------------------- parsing

it('pulls text out of both fixture resumes', function () {
    $parser = app(ResumeParser::class);

    $plumber = $parser->text(resumeFixture('plumber-strong-match.pdf'));
    $accountant = $parser->text(resumeFixture('accountant-mismatch.pdf'));

    expect($plumber)->toContain('Suresh Kumar')
        ->and($plumber)->toContain('Pipe fitting')
        ->and($accountant)->toContain('Anjali Menon')
        ->and($accountant)->toContain('Tally');
});

it('returns null for a PDF with no text layer', function () {
    // A valid-enough PDF shell with no text objects at all.
    $file = UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n");

    expect(app(ResumeParser::class)->text($file))->toBeNull();
});

it('caps stored text so a padded PDF cannot bloat the prompt', function () {
    expect(ResumeParser::MAX_CHARS)->toBe(8000);

    $text = app(ResumeParser::class)->text(resumeFixture('plumber-strong-match.pdf'));

    expect(mb_strlen($text))->toBeLessThanOrEqual(ResumeParser::MAX_CHARS);
});

// ---------------------------------------------------------------- upload

it('lets a worker upload a resume and stores the file privately', function () {
    Storage::fake(ResumeStore::DISK);

    $this->actingAs($this->worker)
        ->post('/worker/resume', ['resume' => resumeFixture('plumber-strong-match.pdf')])
        ->assertRedirect();

    $profile = $this->worker->workerProfile->fresh();

    expect($profile->resume_path)->not->toBeNull()
        ->and($profile->resume_name)->toBe('plumber-strong-match.pdf')
        ->and($profile->resume_text)->toContain('Suresh Kumar')
        ->and($profile->resume_uploaded_at)->not->toBeNull();

    Storage::disk(ResumeStore::DISK)->assertExists($profile->resume_path);
});

it('rejects a non-PDF upload', function () {
    $this->actingAs($this->worker)
        ->post('/worker/resume', ['resume' => UploadedFile::fake()->create('resume.docx', 20)])
        ->assertSessionHasErrors('resume');

    expect($this->worker->workerProfile->fresh()->resume_path)->toBeNull();
});

it('rejects a PDF whose text cannot be read', function () {
    $this->actingAs($this->worker)
        ->post('/worker/resume', [
            'resume' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n"),
        ])
        ->assertSessionHasErrors('resume');

    expect($this->worker->workerProfile->fresh()->resume_path)->toBeNull();
});

it('replaces the previous resume rather than piling files up', function () {
    Storage::fake(ResumeStore::DISK);
    $store = app(ResumeStore::class);
    $profile = $this->worker->workerProfile;

    $store->put($profile, resumeFixture('plumber-strong-match.pdf'));
    $first = $profile->fresh()->resume_path;

    $store->put($profile->fresh(), resumeFixture('accountant-mismatch.pdf'));
    $second = $profile->fresh()->resume_path;

    expect($second)->not->toBe($first)
        ->and($profile->fresh()->resume_text)->toContain('Anjali Menon');

    Storage::disk(ResumeStore::DISK)->assertMissing($first);
});

it('clears the file and the text when a worker removes their resume', function () {
    Storage::fake(ResumeStore::DISK);
    $store = app(ResumeStore::class);
    $store->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));
    $path = $this->worker->workerProfile->fresh()->resume_path;

    $this->actingAs($this->worker)->delete('/worker/resume')->assertRedirect();

    $profile = $this->worker->workerProfile->fresh();
    expect($profile->resume_path)->toBeNull()
        ->and($profile->resume_text)->toBeNull()
        ->and($profile->resume_name)->toBeNull();

    Storage::disk(ResumeStore::DISK)->assertMissing($path);
});

it('serves the resume over the API too', function () {
    Storage::fake(ResumeStore::DISK);

    $this->actingAs($this->worker, 'sanctum')
        ->postJson('/api/v1/worker/resume', ['resume' => resumeFixture('plumber-strong-match.pdf')])
        ->assertCreated()
        ->assertJsonPath('resume.name', 'plumber-strong-match.pdf');

    $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/worker/resume')
        ->assertOk()
        ->assertJsonPath('resume.name', 'plumber-strong-match.pdf');

    $this->actingAs($this->worker, 'sanctum')->deleteJson('/api/v1/worker/resume')
        ->assertOk()
        ->assertJsonPath('resume', null);
});

// ---------------------------------------------------------------- download

it('lets the employer download an applicant resume but nobody else', function () {
    Storage::fake(ResumeStore::DISK);
    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $application = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);

    $this->actingAs($this->employer)
        ->get("/employer/applications/{$application->id}/resume")
        ->assertOk();

    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Somebody Else']);

    $this->actingAs($other)
        ->get("/employer/applications/{$application->id}/resume")
        ->assertForbidden();
});

// ---------------------------------------------------------------- web pages

it('gives the worker profile page a resume summary and keeps the parsed text out of it', function () {
    Storage::fake(ResumeStore::DISK);
    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $this->actingAs($this->worker)->get('/worker/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('resume.name', 'plumber-strong-match.pdf')
            ->where('resume.max_characters', ResumeParser::MAX_CHARS)
            ->where('resume.characters', fn (int $chars) => $chars > 0)
            // The 8k of extracted text has no business in a page payload.
            ->missing('profile.resume_text')
            ->missing('profile.resume_path')
            ->etc());
});

it('offers the resume inside the apply panel on both job pages', function () {
    Storage::fake(ResumeStore::DISK);

    // Before uploading, the panel still renders — it just has nothing to show.
    $this->actingAs($this->worker)->get("/worker/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('resume', null)->etc());

    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $this->actingAs($this->worker)->get("/worker/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('resume.name', 'plumber-strong-match.pdf')->etc());

    $this->actingAs($this->worker)->get("/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('resume.name', 'plumber-strong-match.pdf')->etc());
});

it('keeps the resume off the public job page for anyone who cannot apply', function () {
    Storage::fake(ResumeStore::DISK);
    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    // Guest.
    $this->get("/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('resume', null)->etc());

    // Employer browsing their own listing.
    $this->actingAs($this->employer)->get("/jobs/{$this->job->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('resume', null)->etc());
});

it('lets a worker open their own resume, and 404s before they upload one', function () {
    Storage::fake(ResumeStore::DISK);

    $this->actingAs($this->worker)->get('/worker/resume')->assertNotFound();

    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $this->actingAs($this->worker)->get('/worker/resume')->assertOk();
});

it('shows the employer every applicant score and resume, best match first', function () {
    Storage::fake(ResumeStore::DISK);
    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $other = User::factory()->create(['role' => UserRole::Worker->value]);
    $other->workerProfile()->create(['city' => 'Jaipur', 'skills' => []]);

    // The strong applicant applied first, so date order alone would bury them.
    $strong = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
        'ai_score' => 91,
        'ai_recommendation' => 'strong_match',
        'ai_summary' => 'Experienced plumber',
        'ai_matched_skills' => ['Plumbing'],
        'ai_red_flags' => [],
        'ai_scored_at' => now(),
    ]);
    $strong->forceFill(['created_at' => now()->subDays(2)])->save();

    JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $other->id,
        'status' => ApplicationStatus::Pending,
        'ai_score' => 12,
        'ai_recommendation' => 'weak',
        'ai_summary' => 'Wrong trade',
        'ai_matched_skills' => [],
        'ai_red_flags' => ['Wrong trade'],
        'ai_scored_at' => now(),
    ]);

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sort', 'best_match')
            ->where('applications.0.ai.score', 91)
            ->where('applications.0.ai.red_flags', [])
            ->where('applications.0.resume.name', 'plumber-strong-match.pdf')
            ->where('applications.1.ai.score', 12)
            ->where('applications.1.ai.red_flags', ['Wrong trade'])
            ->where('applications.1.resume', null)
            ->etc());

    $this->actingAs($this->employer)->get("/employer/jobs/{$this->job->id}/applicants?sort=recent")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sort', 'recent')
            ->where('applications.0.ai.score', 12)
            ->etc());
});

it('queues only the unscored applicants when the employer runs AI scoring', function () {
    Queue::fake();

    $scored = JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $this->worker->id,
        'status' => ApplicationStatus::Pending,
        'ai_score' => 91,
        'ai_scored_at' => now(),
    ]);

    $unscored = User::factory()->create(['role' => UserRole::Worker->value]);
    JobApplication::create([
        'job_listing_id' => $this->job->id,
        'worker_id' => $unscored->id,
        'status' => ApplicationStatus::Pending,
    ]);

    $this->actingAs($this->employer)->post("/employer/jobs/{$this->job->id}/rescore")->assertRedirect();

    Queue::assertPushed(ScoreApplication::class, 1);

    // force=1 re-runs everyone, so the two here land on top of the one above.
    $this->actingAs($this->employer)->post("/employer/jobs/{$this->job->id}/rescore", ['force' => true]);

    Queue::assertPushed(ScoreApplication::class, 3);

    expect($scored->fresh()->ai_score)->toBe(91);
});

it('does not let another employer rescore a job that is not theirs', function () {
    Queue::fake();

    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $other->employerProfile()->create(['company_name' => 'Somebody Else']);

    $this->actingAs($other)->post("/employer/jobs/{$this->job->id}/rescore")->assertForbidden();

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------- matcher

it('hands the resume text to the model when scoring', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' => '{"score":95,"recommendation":"strong_match","summary":"Experienced plumber","matched_skills":["Plumbing"],"red_flags":[]}']]]]),
    ]);
    Storage::fake(ResumeStore::DISK);
    app(ResumeStore::class)->put($this->worker->workerProfile, resumeFixture('plumber-strong-match.pdf'));

    $result = app(AiMatcher::class)->score($this->job, $this->worker->fresh()->load('workerProfile'));

    expect($result['score'])->toBe(95);

    Http::assertSent(function ($request) {
        $prompt = json_encode($request->data()['messages']);

        return str_contains($prompt, 'Resume text') && str_contains($prompt, 'Suresh Kumar');
    });
});

it('leaves the prompt resume-free when the worker has not uploaded one', function () {
    config(['services.ai.key' => 'test-key']);
    Http::fake([
        '*' => Http::response(['choices' => [['message' => ['content' => '{"score":50,"recommendation":"maybe","summary":"ok","matched_skills":[],"red_flags":[]}']]]]),
    ]);

    app(AiMatcher::class)->score($this->job, $this->worker);

    Http::assertSent(fn ($request) => ! str_contains(json_encode($request->data()['messages']), 'Resume text'));
});

// ---------------------------------------------------------------- auto-reject

function applicationForScoring(): JobApplication
{
    return JobApplication::create([
        'job_listing_id' => test()->job->id,
        'worker_id' => test()->worker->id,
        'status' => ApplicationStatus::Pending,
    ]);
}

it('auto-rejects and notifies a poor match once the admin switches it on', function () {
    Setting::set(ScoreApplication::REJECT_ENABLED_KEY, '1');
    Setting::set(ScoreApplication::REJECT_BELOW_KEY, '30');

    // No skills against a 3-skill job scores 0, plus 20 for sharing the job's
    // city — so 20, still under the 30 floor.
    $application = applicationForScoring();
    dispatch_sync(new ScoreApplication($application->id));
    $application->refresh();

    expect($application->ai_score)->toBe(20)
        ->and($application->status)->toBe(ApplicationStatus::Rejected)
        ->and($application->status_changed_at)->not->toBeNull();

    Notification::assertSentTo($this->worker, ApplicationStatusNotification::class);
});

it('leaves a poor match pending while auto-reject is off', function () {
    $application = applicationForScoring();
    dispatch_sync(new ScoreApplication($application->id));

    expect($application->fresh()->status)->toBe(ApplicationStatus::Pending);
    Notification::assertNothingSent();
});

it('never auto-rejects an applicant the employer already shortlisted', function () {
    Setting::set(ScoreApplication::REJECT_ENABLED_KEY, '1');
    Setting::set(ScoreApplication::REJECT_BELOW_KEY, '30');

    $application = applicationForScoring();
    $application->update(['shortlisted_at' => now()]);

    dispatch_sync(new ScoreApplication($application->id));

    expect($application->fresh()->status)->toBe(ApplicationStatus::Pending);
    Notification::assertNothingSent();
});

it('never auto-rejects an applicant with an interview booked', function () {
    Setting::set(ScoreApplication::REJECT_ENABLED_KEY, '1');
    Setting::set(ScoreApplication::REJECT_BELOW_KEY, '30');

    $application = applicationForScoring();
    $application->update(['interview_at' => now()->addDay()]);

    dispatch_sync(new ScoreApplication($application->id));

    expect($application->fresh()->status)->toBe(ApplicationStatus::Pending);
});

it('respects the admin floor, sparing a score that sits on it', function () {
    Setting::set(ScoreApplication::REJECT_ENABLED_KEY, '1');
    Setting::set(ScoreApplication::REJECT_BELOW_KEY, '30');

    // One of three skills plus 20 for the same city = ~43, comfortably above 30.
    $this->worker->workerProfile->update(['skills' => ['Plumbing']]);

    $application = applicationForScoring();
    dispatch_sync(new ScoreApplication($application->id));

    expect($application->fresh()->ai_score)->toBeGreaterThanOrEqual(30)
        ->and($application->fresh()->status)->toBe(ApplicationStatus::Pending);
});

it('shortlists rather than rejects when both gates could fire', function () {
    Setting::set(ScoreApplication::ENABLED_KEY, '1');
    Setting::set(ScoreApplication::THRESHOLD_KEY, '40');
    Setting::set(ScoreApplication::REJECT_ENABLED_KEY, '1');
    // A deliberately silly floor that would otherwise swallow everything.
    Setting::set(ScoreApplication::REJECT_BELOW_KEY, '40');

    $this->worker->workerProfile->update([
        'skills' => ['Plumbing', 'Pipe Fitting', 'Welding'],
        'experience_years' => 5,
    ]);

    $application = applicationForScoring();
    dispatch_sync(new ScoreApplication($application->id));
    $application->refresh();

    expect($application->shortlisted_at)->not->toBeNull()
        ->and($application->status)->toBe(ApplicationStatus::Pending);
});

// ---------------------------------------------------------------- admin

it('exposes the auto-reject settings to the admin screen', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->get('/admin/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.ai_auto_reject_enabled', false)
            ->where('settings.ai_auto_reject_below', ScoreApplication::DEFAULT_REJECT_BELOW));
});

it('lets an admin set the reject floor but not above the weak ceiling', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $base = [
        'first_post_free_enabled' => true,
        'kyc_verification_enabled' => true,
        'ai_auto_shortlist_enabled' => false,
        'ai_auto_shortlist_threshold' => 80,
        'ai_auto_reject_enabled' => true,
    ];

    $this->actingAs($admin)
        ->patch('/admin/settings', $base + ['ai_auto_reject_below' => 25])
        ->assertRedirect();

    expect(Setting::int(ScoreApplication::REJECT_BELOW_KEY))->toBe(25);

    $this->actingAs($admin)
        ->patch('/admin/settings', $base + ['ai_auto_reject_below' => 70])
        ->assertSessionHasErrors('ai_auto_reject_below');
});
