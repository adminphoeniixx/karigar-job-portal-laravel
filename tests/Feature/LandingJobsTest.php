<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget(Category::CACHE_KEY);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
});

/** @param  array<string, mixed>  $attributes */
function postLandingJob(User $employer, string $category, string $title, array $attributes = []): void
{
    $employer->jobListings()->create([
        'title' => $title,
        'description' => 'Demo listing',
        'category' => $category,
        'skills' => [],
        'city' => 'Jaipur',
        'state' => 'Rajasthan',
        'vacancies' => 1,
        'wage_min' => 500,
        'wage_max' => 900,
        'status' => JobStatus::Active,
        ...$attributes,
    ]);
}

it('only lists jobs in crafts the crafts index actually offers', function () {
    Category::create(['name' => 'Pottery / Handmade Pots', 'sort_order' => 1]);

    // A leftover from before the catalogue was narrowed to handmade crafts.
    // It is still a real, active job — it just is not one of our crafts, so it
    // has no business sitting under the crafts index.
    postLandingJob($this->employer, 'Plumbing', 'Plumber for apartment project');
    postLandingJob($this->employer, 'Pottery / Handmade Pots', 'Potter for a Jaipur studio');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('latestJobs', 1)
            ->where('latestJobs.0.title', 'Potter for a Jaipur studio'));
});

it('drops a craft from the list the moment the admin deactivates it', function () {
    $category = Category::create(['name' => 'Crochet', 'sort_order' => 1]);
    postLandingJob($this->employer, 'Crochet', 'Crochet work — home based');

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->has('latestJobs', 1));

    $category->update(['is_active' => false]);
    Cache::forget(Category::CACHE_KEY);

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->has('latestJobs', 0));
});
