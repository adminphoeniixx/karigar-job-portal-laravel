<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\User;
use App\Models\WorkerRejection;
use App\Notifications\NewJobNotification;
use App\Support\JobDetailLabels;
use App\Support\JobRequirementDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

/**
 * The job details WorkIndia's job form asks for (2026-11-19): job type,
 * education, English, age, languages, documents, reach, relocation, bonus,
 * fee purpose, working and interview days, urgency, other requirements. Also
 * the title lock, the suggested requirements per category, the matching
 * karigars preview, and nearby-only jobs in the feed and alerts.
 */
uses(RefreshDatabase::class);

// Jaipur, and points 5 and 25 km north of it.
const WI_LAT = 26.9124;
const WI_LNG = 75.7873;
const WI_5KM = 26.9574;
const WI_25KM = 27.1374;

beforeEach(function () {
    config(['scout.driver' => 'null']);
    JobRequirementDefaults::flush();

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->employer->employerProfile()->create(['company_name' => 'Jaipur Darzi House']);
    giveJobPlan($this->employer);
});

function wiPayload(array $overrides = []): array
{
    return [
        'title' => 'Ladies Tailor', 'description' => 'Stitching blouses and suits for a boutique.', 'category' => 'Tailoring',
        'vacancies' => 2, 'status' => 'active', 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'latitude' => WI_LAT, 'longitude' => WI_LNG,
        ...$overrides,
    ];
}

function wiDetails(): array
{
    return [
        'job_type' => 'part_time',
        'education' => '10th Pass',
        'english' => 'basic',
        'age_min' => 18,
        'age_max' => 40,
        'languages' => ['Hindi', 'Hindi', 'Urdu'],
        'assets' => ['aadhaar', 'own_tools'],
        'reach' => 'nearby',
        'relocation_allowed' => true,
        'incentive_max' => 2000,
        'incentive_type' => '  Per-piece rate ',
        'requires_worker_fee' => true,
        'worker_fee_amount' => 500,
        'worker_fee_purpose' => 'uniform',
        'work_days' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
        'interview_days' => ['mon', 'wed', 'fri'],
        'interview_start' => '11:00',
        'interview_end' => '16:00',
        'interview_address' => 'Shop 12, MI Road',
        'hiring_urgency' => 'immediate',
        'other_requirements' => 'Must know hand embroidery on silk.',
    ];
}

function wiKarigar(string $name, array $profile): User
{
    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => $name, 'phone' => '98'.random_int(10000000, 99999999)]);
    $worker->workerProfile()->create([
        'skills' => ['Tailoring'], 'city' => 'Jaipur', 'state' => 'Rajasthan', 'available' => true,
        ...$profile,
    ]);

    return $worker;
}

// ───────────────────────── SAVING ─────────────────────────

it('saves every new detail and returns it with labels to both apps', function () {
    // No fee here: the safety check holds a job that charges one for a look.
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload([...wiDetails(), 'requires_worker_fee' => false]))
        ->assertCreated()
        ->assertJsonPath('job.status', 'active')
        ->assertJsonPath('job.job_type', 'part_time')
        ->assertJsonPath('job.job_type_label', 'Part time')
        ->assertJsonPath('job.education_label', '10th pass or above')
        ->assertJsonPath('job.english_label', 'Thoda English')
        ->assertJsonPath('job.age_label', '18–40 yrs')
        ->assertJsonPath('job.languages', ['Hindi', 'Urdu'])
        ->assertJsonPath('job.asset_labels', ['Aadhaar card', 'Own tools'])
        ->assertJsonPath('job.reach_label', 'Karigars within 10 km')
        ->assertJsonPath('job.relocation_allowed', true)
        ->assertJsonPath('job.incentive_label', 'Up to ₹2,000 a month · Per-piece rate')
        ->assertJsonPath('job.worker_fee_purpose', null)
        ->assertJsonPath('job.work_days_label', 'Mon–Sat')
        ->assertJsonPath('job.interview_days_label', 'Mon, Wed, Fri')
        ->assertJsonPath('job.interview_hours_label', '11:00 AM – 4:00 PM')
        ->assertJsonPath('job.interview_address', 'Shop 12, MI Road')
        ->assertJsonPath('job.hiring_urgency_label', 'Hiring immediately')
        ->assertJsonPath('job.other_requirements', 'Must know hand embroidery on silk.')
        ->assertJsonPath('job.title_locked', true);

    $job = JobListing::firstOrFail();
    expect($job->incentive_type)->toBe('Per-piece rate');

    $worker = wiKarigar('Sunita Devi', ['latitude' => WI_5KM, 'longitude' => WI_LNG]);
    $this->actingAs($worker, 'sanctum')->getJson("/api/v1/jobs/{$job->id}")
        ->assertOk()
        ->assertJsonPath('data.education_label', '10th pass or above')
        ->assertJsonPath('data.interview_hours_label', '11:00 AM – 4:00 PM')
        ->assertJsonPath('data.hiring_urgency', 'immediate');

    // The list card says part time and urgent.
    $this->actingAs($worker, 'sanctum')->getJson('/api/v1/jobs?all=1')
        ->assertJsonPath('data.0.job_type', 'part_time')
        ->assertJsonPath('data.0.urgent', true);
});

it('says what a fee is for', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload([...wiDetails(), 'status' => 'draft']))
        ->assertCreated()
        ->assertJsonPath('job.worker_fee_purpose', 'uniform')
        ->assertJsonPath('job.worker_fee_purpose_label', 'Uniform / T-shirt / bag');
});

it('starts a new job as full time, and tidies what does not apply', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload([
        'requires_worker_fee' => false, 'worker_fee_purpose' => 'uniform',
        'incentive_max' => '', 'incentive_type' => 'Commission',
        'interview_mode' => 'remote', 'interview_address' => 'Shop 12',
        'languages' => [], 'work_days' => ['', null],
    ]))->assertCreated();

    expect(JobListing::firstOrFail())
        ->job_type->toBe('full_time')
        ->worker_fee_purpose->toBeNull()
        ->incentive_type->toBeNull()
        ->interview_address->toBeNull()
        ->languages->toBeNull()
        ->work_days->toBeNull();
});

it('refuses details it does not know or that do not add up', function (array $details, array $errors) {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload($details))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);
})->with([
    'under 18' => [['age_min' => 16], ['age_min']],
    'max age below min' => [['age_min' => 30, 'age_max' => 25], ['age_max']],
    'interview ends first' => [['interview_start' => '16:00', 'interview_end' => '11:00'], ['interview_end']],
    'one interview time' => [['interview_start' => '11:00'], ['interview_end']],
    'unknown asset' => [['assets' => ['laptop']], ['assets.0']],
    'unknown language' => [['languages' => ['Klingon']], ['languages.0']],
    'unknown education' => [['education' => 'PhD'], ['education']],
    'unknown english' => [['english' => 'native'], ['english']],
    'unknown reach' => [['reach' => 'state'], ['reach']],
    'unknown job type' => [['job_type' => 'contract'], ['job_type']],
    'unknown fee purpose' => [['requires_worker_fee' => true, 'worker_fee_amount' => 100, 'worker_fee_purpose' => 'bribe'], ['worker_fee_purpose']],
]);

it('keeps the details on an edit from an older app build that does not send them', function () {
    $id = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload(wiDetails()))->json('job.id');

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", wiPayload([
        'description' => 'Blouses, suits and lehengas.', 'requires_worker_fee' => true, 'worker_fee_amount' => 500,
    ]))->assertOk();

    expect(JobListing::findOrFail($id))
        ->description->toBe('Blouses, suits and lehengas.')
        ->job_type->toBe('part_time')
        ->education->toBe('10th Pass')
        ->assets->toBe(['aadhaar', 'own_tools'])
        ->interview_start->toBe('11:00')
        ->hiring_urgency->toBe('immediate');
});

it('labels a freshers-only job and reads the three experience choices', function () {
    $job = new JobListing(['experience_min' => 0, 'experience_max' => 0]);
    expect($job->experienceLabel())->toBe('Freshers only')
        ->and($job->experienceLevel())->toBe('fresher')
        ->and((new JobListing)->experienceLevel())->toBe('any')
        ->and((new JobListing(['experience_min' => 2]))->experienceLevel())->toBe('experienced');
});

it('says days the way people do', function () {
    expect(JobDetailLabels::days(['mon', 'tue', 'wed', 'thu', 'fri', 'sat']))->toBe('Mon–Sat')
        ->and(JobDetailLabels::days(['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat']))->toBe('Every day')
        ->and(JobDetailLabels::days(['fri', 'mon', 'wed']))->toBe('Mon, Wed, Fri')
        ->and(JobDetailLabels::days([]))->toBeNull();
});

// ───────────────────────── TITLE LOCK ─────────────────────────

it('keeps the title of a job that has been live', function () {
    $id = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload())->json('job.id');

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", wiPayload(['title' => 'Embroidery karigar']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);

    // Case and spacing can still be fixed.
    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", wiPayload(['title' => '  ladies   TAILOR ']))
        ->assertOk();

    // The website refuses it too.
    $this->actingAs($this->employer)->patch("/employer/jobs/{$id}", wiPayload(['title' => 'Embroidery karigar']))
        ->assertSessionHasErrors('title');

    expect(JobListing::findOrFail($id)->title)->not->toBe('Embroidery karigar');
});

it('lets a draft change its title', function () {
    $id = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload(['status' => 'draft']))
        ->assertCreated()
        ->assertJsonPath('job.title_locked', false)
        ->json('job.id');

    $this->actingAs($this->employer, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", wiPayload(['title' => 'Embroidery karigar', 'status' => 'draft']))
        ->assertOk();

    expect(JobListing::findOrFail($id)->title)->toBe('Embroidery karigar');
});

it('gives someone else 403 before telling them anything about the job', function () {
    $id = $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload())->json('job.id');
    $other = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($other, 'sanctum')->patchJson("/api/v1/employer/jobs/{$id}", wiPayload(['title' => 'Mine now']))
        ->assertForbidden();
});

// ───────────────────────── SUGGESTED REQUIREMENTS ─────────────────────────

it('suggests what most jobs in a category ask, and the usual values below three answers', function () {
    foreach (['10th Pass', '10th Pass', 'Below 10th'] as $i => $education) {
        $this->employer->jobListings()->create(wiPayload([
            'title' => "Tailor {$i}", 'status' => JobStatus::Active->value, 'education' => $education, 'english' => 'none', 'gender' => 'female',
        ]));
    }
    $this->employer->jobListings()->create(wiPayload(['title' => 'Weaver', 'category' => 'Weaving', 'status' => JobStatus::Active->value, 'education' => 'Graduate']));
    JobRequirementDefaults::flush();

    expect(JobRequirementDefaults::for('Tailoring'))
        ->values->education->toBe('10th Pass')
        ->values->gender->toBe('female')
        ->values->english->toBe('none')
        ->jobs->toBe(3)
        // Reach was never answered: the usual value, tagged as such.
        ->from_jobs->toContain('education', 'gender', 'english')->not->toContain('reach')
        // One answer is not enough: Weaving keeps the usual value.
        ->and(JobRequirementDefaults::for('Weaving')['values']['education'])->toBe('Below 10th')
        ->and(JobRequirementDefaults::for('Pottery')['values'])->toBe(JobRequirementDefaults::FALLBACK);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')
        ->assertOk()
        ->assertJsonPath('requirement_defaults.Tailoring.values.education', '10th Pass')
        ->assertJsonPath('details.job_types.1', ['value' => 'part_time', 'label' => 'Part time'])
        ->assertJsonPath('details.nearby_km', 10)
        ->assertJsonPath('details.age_min', 18);
});

it('gives the web job form its detail options, defaults and support line', function () {
    $this->actingAs($this->employer)->get('/employer/jobs/create')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('jobs/Form')
            ->has('detailOptions.english_levels', 4)
            ->has('requirementDefaults')
            ->has('support.phone'));
});

// ───────────────────────── MATCHING KARIGARS ─────────────────────────

it('counts the karigars who fit the job, and shows five without their numbers', function () {
    wiKarigar('Sunita Devi', ['latitude' => WI_5KM, 'longitude' => WI_LNG, 'gender' => 'female', 'education' => '12th Pass', 'experience_years' => 4]);
    wiKarigar('Rekha Sharma', ['latitude' => WI_25KM, 'longitude' => WI_LNG, 'gender' => 'female', 'education' => '10th Pass']);
    wiKarigar('Ramesh Kumar', ['latitude' => WI_5KM, 'longitude' => WI_LNG, 'gender' => 'male']);
    wiKarigar('Meena Bai', ['city' => 'Jaipur', 'latitude' => null, 'longitude' => null, 'gender' => 'female', 'education' => 'Below 10th']);
    wiKarigar('Potter Lal', ['skills' => ['Pottery'], 'latitude' => WI_5KM, 'longitude' => WI_LNG]);
    wiKarigar('Away Devi', ['available' => false, 'latitude' => WI_5KM, 'longitude' => WI_LNG]);

    $query = fn (array $q) => '/employer/jobs/matching-karigars?'.http_build_query([
        'category' => 'Tailoring', 'latitude' => WI_LAT, 'longitude' => WI_LNG, 'city' => 'Jaipur', ...$q,
    ]);

    // Anywhere in the city: 30 km of the pin, or the same city without a pin.
    $all = $this->actingAs($this->employer)->getJson($query(['reach' => 'city']))->assertOk();
    expect($all->json('count'))->toBe(4)->and($all->json('radius_km'))->toBe(30);
    $cards = collect($all->json('karigars'));
    expect($cards->pluck('name'))->toContain('Sunita D.', 'Rekha S.', 'Meena B.')
        ->and($cards->every(fn (array $card) => ! array_key_exists('phone', $card)))->toBeTrue()
        ->and(json_encode($cards))->not->toContain('Sharma');

    // Nearby: 10 km, plus same-city karigars without a pin.
    expect($this->actingAs($this->employer)->getJson($query(['reach' => 'nearby']))->json('count'))->toBe(3);

    // Women only, 12th pass or above (a karigar who never said passes).
    expect($this->actingAs($this->employer)->getJson($query(['reach' => 'city', 'gender' => 'female', 'education' => '12th Pass']))->json('count'))->toBe(1);

    // Experienced: 3 years or more.
    expect($this->actingAs($this->employer)->getJson($query(['experience_level' => 'experienced', 'experience_min' => 3]))->json('count'))->toBe(1);

    // A karigar the employer turned down in the Worker Database is left out.
    WorkerRejection::create(['employer_id' => $this->employer->id, 'worker_id' => User::where('name', 'Sunita Devi')->value('id')]);
    expect($this->actingAs($this->employer)->getJson($query(['reach' => 'city']))->json('count'))->toBe(3);

    // Nothing to match on yet: no craft, or no place.
    expect($this->actingAs($this->employer)->getJson('/employer/jobs/matching-karigars?city=Jaipur')->json('count'))->toBe(0);

    // The app gets the same.
    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/matching-karigars?'.http_build_query(['category' => 'Tailoring', 'city' => 'Jaipur']))
        ->assertOk()
        ->assertJsonStructure(['count', 'radius_km', 'karigars']);
});

// ───────────────────────── NEARBY JOBS ─────────────────────────

it('shows a nearby-only job to karigars within 10 km, and alerts only them', function () {
    Notification::fake();

    $near = wiKarigar('Near Devi', ['latitude' => WI_5KM, 'longitude' => WI_LNG]);
    $far = wiKarigar('Far Devi', ['latitude' => WI_25KM, 'longitude' => WI_LNG]);

    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload(['reach' => 'nearby']))->assertCreated();
    $this->employer->jobListings()->create(wiPayload(['title' => 'Blouse Tailor', 'status' => JobStatus::Active->value, 'reach' => 'city']));

    Notification::assertSentTo($near, NewJobNotification::class);
    Notification::assertNotSentTo($far, NewJobNotification::class);

    $titles = fn (User $worker) => collect($this->actingAs($worker, 'sanctum')->getJson('/api/v1/jobs')->json('data'))->pluck('title')->all();

    expect($titles($near))->toContain('Ladies Tailor', 'Blouse Tailor')
        ->and($titles($far))->toContain('Blouse Tailor')->not->toContain('Ladies Tailor');
});

// ───────────────────────── SAFETY CHECK ─────────────────────────

it('holds a job whose other requirements ask karigars for money', function () {
    $this->actingAs($this->employer, 'sanctum')->postJson('/api/v1/employer/jobs', wiPayload([
        'other_requirements' => 'Pay registration fee of ₹2000 before joining.',
    ]))->assertCreated();

    expect(JobListing::firstOrFail())
        ->status->toBe(JobStatus::Draft)
        ->published_at->toBeNull();
});
