<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\JobApplication;
use App\Models\User;
use App\Services\Screening\CallScript;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The script is read aloud, not displayed, so the writing system is part of
 * the behaviour rather than a formatting detail. A Hindi voice handed Roman
 * text has to transliterate before it can speak and gets it wrong often enough
 * to sound foreign — which is what a listener reports as "the pronunciation is
 * off". None of that is visible in a diff, so it is pinned here.
 */
uses(RefreshDatabase::class);

function scriptFor(array $spokenLanguages): CallScript
{
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $employer->employerProfile()->create(['company_name' => 'Sri Sai Constructions', 'city' => 'Chennai']);

    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'phone' => '9876500011']);
    $worker->workerProfile()->create([
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'available' => true,
        'spoken_languages' => $spokenLanguages,
    ]);

    $job = $employer->jobListings()->create([
        'title' => 'Plumber for apartment project',
        'description' => 'Site work',
        'category' => 'Plumbing',
        'skills' => ['Plumbing'],
        'city' => 'Chennai',
        'state' => 'Tamil Nadu',
        'vacancies' => 1,
        'wage_min' => 800,
        'wage_max' => 1000,
        'status' => JobStatus::Active,
    ]);

    return CallScript::for(JobApplication::create([
        'job_listing_id' => $job->id,
        'worker_id' => $worker->id,
    ])->load('job.employer.employerProfile', 'worker.workerProfile'));
}

it('writes the Hindi greeting in Devanagari, not in Roman letters', function () {
    $greeting = scriptFor(['Hindi'])->greeting;

    expect($greeting)->toMatch('/[\x{0900}-\x{097F}]/u')
        ->and($greeting)->toContain('नमस्ते')
        // The Roman spellings this replaced. A voice reads these with an
        // English mouth, which is the whole reason for the change.
        ->and($greeting)->not->toContain('Namaste')
        ->and($greeting)->not->toContain('kar rahi hoon')
        ->and($greeting)->not->toContain('baat kar sakte hain');
});

it('leaves the English words in English inside the Hindi greeting', function () {
    // The register is still worksite Hinglish — only the script changed. A
    // worker says "application", not its Devanagari transliteration, and the
    // brand is a brand.
    expect(scriptFor(['Hindi'])->greeting)
        ->toContain('Super Karigar')
        ->toContain('application')
        ->toContain('minute');
});

it('tells the model to reply in Devanagari, with English words left in English', function () {
    $instructions = scriptFor(['Hindi'])->instructions;

    expect($instructions)->toContain('Devanagari')
        ->and($instructions)->toContain('not in Roman transliteration');
});

it('writes its worked examples in Devanagari too', function () {
    // The model copies the shape of these, so a Roman example would quietly
    // undo the rule above.
    $instructions = scriptFor(['Hindi'])->instructions;

    expect($instructions)->toContain('final amount employer ही तय करेगा')
        ->and($instructions)->not->toContain('final amount employer hi tay karega');
});

it('does not impose Devanagari on a language that does not use it', function () {
    $script = scriptFor(['Tamil']);

    expect($script->language)->toBe('ta')
        ->and($script->instructions)->not->toContain('Devanagari');
});
