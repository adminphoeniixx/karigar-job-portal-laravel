<?php

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Employer app QA round of 2026-11-18: "Finish setup" needs a whole profile,
 * the job form's gender and shift fields, and job profiles that suggest a
 * job's category and skills.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value, 'name' => '']);
    $this->employer->employerProfile()->create([]);
});

it('lets each sign-up step save on its own, and skip, without finishing', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', ['hiring_as' => 'business'])
        ->assertOk()
        ->assertJsonPath('data.hiring_as', 'business');

    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', [])
        ->assertOk();
});

it('refuses "Finish setup" on a blank profile and names every missing field', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', ['finish' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'hiring_as', 'company_name', 'industry', 'hiring_categories', 'city', 'state'])
        ->assertJsonPath('errors.hiring_categories.0', 'Pick at least one kind of karigar you hire.');
});

it('counts what earlier steps saved when finishing', function () {
    $this->employer->update(['name' => 'Ramesh']);
    $this->employer->employerProfile->update([
        'hiring_as' => 'business',
        'company_name' => 'Ramesh Furniture Works',
        'industry' => 'Manufacturing',
    ]);

    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', [
            'finish' => true,
            'hiring_categories' => ['Wood Carving'],
            'city' => 'Jodhpur',
            'state' => 'Rajasthan',
        ])
        ->assertOk()
        ->assertJsonPath('data.hiring_categories', ['Wood Carving']);
});

it('does not ask an individual for a business name, but refuses an emptied field', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->patchJson('/api/v1/employer/profile', [
            'finish' => true,
            'name' => 'Sunita',
            'hiring_as' => 'individual',
            'industry' => 'Home Services',
            'hiring_categories' => [''],
            'city' => 'Delhi',
            'state' => 'Delhi',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['hiring_categories'])
        ->assertJsonMissingValidationErrors(['company_name']);
});

function shiftJob(array $extra = []): array
{
    return $extra + [
        'title' => 'Tailor for boutique',
        'description' => 'Stitching work',
        'vacancies' => 2,
        'contact_mode' => 'apply',
        'status' => 'draft',
    ];
}

it('saves who the job is for, and shows it to karigars', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob(['gender' => 'female']))
        ->assertCreated()
        ->assertJsonPath('job.gender', 'female')
        ->assertJsonPath('job.gender_label', 'Female');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob(['gender' => 'anyone']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('gender');

    $this->getJson('/api/v1/reference')
        ->assertJsonPath('genders', ['male', 'female', 'both'])
        ->assertJsonPath('shift_defaults.night', ['start' => '21:00', 'end' => '06:00']);
});

it('lists every shift of a rotational job, in order', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'rotational',
            // An older single pair of times is dropped for the list.
            'shift_start' => '09:00',
            'shift_end' => '18:00',
            'shift_timings' => [
                ['start' => '06:00', 'end' => '14:00'],
                ['start' => '14:00', 'end' => '22:00'],
                ['start' => '22:00', 'end' => '06:00'],
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('job.shift_start', null)
        ->assertJsonPath('job.shift_timings.0.name', '1st shift')
        ->assertJsonPath('job.shift_timings.2.label', '10:00 PM – 6:00 AM')
        ->assertJsonPath('job.shift_hours_label', '1st 6:00 AM – 2:00 PM · 2nd 2:00 PM – 10:00 PM · 3rd 10:00 PM – 6:00 AM');
});

it('refuses a rotational shift with a missing or zero-length time, or too many shifts', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'rotational',
            'shift_timings' => [['start' => '06:00'], ['start' => '14:00', 'end' => '14:00']],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shift_timings.0.end', 'shift_timings.1.end']);

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'rotational',
            'shift_timings' => array_fill(0, 7, ['start' => '06:00', 'end' => '14:00']),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('shift_timings');
});

it('saves a flexible job\'s hours and a core window that fits inside them', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'flexible',
            'work_hours' => 8,
            'core_start' => '12:00',
            'core_end' => '16:00',
        ]))
        ->assertCreated()
        // Per day unless told otherwise.
        ->assertJsonPath('job.work_hours_period', 'day')
        ->assertJsonPath('job.shift_hours_label', '8 hrs/day · Core 12:00 PM – 4:00 PM');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'flexible',
            'work_hours' => 40,
            'work_hours_period' => 'week',
        ]))
        ->assertCreated()
        ->assertJsonPath('job.shift_hours_label', '40 hrs/week');
});

it('refuses core hours longer than the working day, or without working hours', function () {
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'flexible',
            'work_hours' => 4,
            'core_start' => '10:00',
            'core_end' => '16:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('core_end');

    // 30 hrs/week over a 6-day week is 5 hrs/day: a 6-hour core is too long.
    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'flexible',
            'work_hours' => 30,
            'work_hours_period' => 'week',
            'core_start' => '22:00',
            'core_end' => '04:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('core_end');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'flexible',
            'core_start' => '12:00',
            'core_end' => '16:00',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_hours');

    $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob(['shift' => 'flexible', 'work_hours' => 25]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('work_hours');
});

it('drops hours left over from another shift type when the shift changes', function () {
    $job = $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob(['shift' => 'flexible', 'work_hours' => 8, 'core_start' => '12:00', 'core_end' => '16:00']))
        ->assertCreated()
        ->json('job.id');

    $this->actingAs($this->employer, 'sanctum')
        ->putJson("/api/v1/employer/jobs/{$job}", shiftJob(['shift' => 'day', 'shift_start' => '09:00', 'shift_end' => '18:00']))
        ->assertOk();

    $saved = JobListing::find($job);
    expect($saved->work_hours)->toBeNull()
        ->and($saved->core_start)->toBeNull()
        ->and($saved->shiftHoursLabel())->toBe('9:00 AM – 6:00 PM');
});

it('keeps a rotational job\'s shifts when an older app build edits it', function () {
    $job = $this->actingAs($this->employer, 'sanctum')
        ->postJson('/api/v1/employer/jobs', shiftJob([
            'shift' => 'rotational',
            'shift_timings' => [['start' => '06:00', 'end' => '14:00'], ['start' => '14:00', 'end' => '22:00']],
        ]))
        ->json('job.id');

    // An older build knows nothing of shift_timings.
    $this->actingAs($this->employer, 'sanctum')
        ->putJson("/api/v1/employer/jobs/{$job}", shiftJob(['shift' => 'rotational', 'title' => 'Tailor (2 shifts)']))
        ->assertOk();

    expect(JobListing::find($job)->shift_timings)->toHaveCount(2);
});

function craftCategories(): void
{
    Category::create(['name' => 'Tailoring', 'is_active' => true, 'sort_order' => 1,
        'skills' => ['Cutting', 'Stitching', 'Blouse stitching', 'Alteration'],
        'job_titles' => ['Tailor', 'Darzi', 'Blouse Tailor', 'Sewing Machine Operator']]);
    Category::create(['name' => 'Weaving', 'is_active' => true, 'sort_order' => 2,
        'skills' => ['Handloom weaving', 'Powerloom operation'],
        'job_titles' => ['Weaver', 'Powerloom Operator']]);
    Category::create(['name' => 'Bunai / Knitting', 'is_active' => true, 'sort_order' => 3,
        'skills' => ['Hand knitting'],
        'job_titles' => ['Knitter', 'Knitting Machine Operator']]);
    // Hidden: never suggested.
    Category::create(['name' => 'Driver', 'is_active' => false, 'sort_order' => 9, 'job_titles' => ['Driver']]);
    Category::flushCache();
}

it('offers each active category\'s job titles as job profiles', function () {
    craftCategories();

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')
        ->assertOk()
        ->assertJsonPath('job_profiles.0', ['title' => 'Tailor', 'category' => 'Tailoring'])
        ->assertJsonMissing(['title' => 'Driver', 'category' => 'Driver']);

    $this->getJson('/api/v1/reference')->assertJsonPath('job_profiles.4', ['title' => 'Weaver', 'category' => 'Weaving']);
});

it('suggests a category and its skills from a job title', function (string $title, ?string $category, ?string $profile, ?string $firstSkill) {
    craftCategories();

    $response = $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/jobs/suggest-category?'.http_build_query(['title' => $title]))
        ->assertOk()
        ->assertJsonPath('suggestion.category', $category);

    if ($category !== null) {
        $response->assertJsonPath('suggestion.job_profile', $profile)
            ->assertJsonPath('suggestion.skills.0', $firstSkill);
    }
})->with([
    'a picked profile' => ['Darzi', 'Tailoring', 'Darzi', 'Cutting'],
    'a profile inside a sentence' => ['Need blouse tailor urgently', 'Tailoring', 'Blouse Tailor', 'Blouse stitching'],
    'another form of the word' => ['Handloom weavers', 'Weaving', null, 'Handloom weaving'],
    'two crafts tie' => ['Machine operator', null, null, null],
    'a hidden category' => ['Truck driver', null, null, null],
    'nothing alike' => ['Accountant', null, null, null],
]);

it('answers the web job form too, with a 422 instead of a redirect', function () {
    craftCategories();

    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-category?title=Tailor')
        ->assertOk()
        ->assertJsonPath('suggestion.category', 'Tailoring');

    $this->actingAs($this->employer)
        ->getJson('/employer/jobs/suggest-category')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('title');
});

it('lets the admin edit a category\'s job titles', function () {
    craftCategories();
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $tailoring = Category::where('name', 'Tailoring')->first();

    $this->actingAs($admin)
        ->patch("/admin/categories/{$tailoring->id}", ['job_titles' => [' Master Tailor ', '', 'Master Tailor', 'Darzi']])
        ->assertRedirect();

    expect($tailoring->fresh()->job_titles)->toBe(['Master Tailor', 'Darzi']);

    $this->actingAs($this->employer, 'sanctum')->getJson('/api/v1/employer/jobs/form-options')
        ->assertJsonPath('job_profiles.0', ['title' => 'Master Tailor', 'category' => 'Tailoring']);
});
