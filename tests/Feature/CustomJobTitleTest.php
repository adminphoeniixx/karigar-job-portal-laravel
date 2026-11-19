<?php

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\CustomJobTitle;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => null]);

    $this->admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->tailoring = Category::create(['name' => 'Tailoring', 'is_active' => true, 'job_titles' => ['Tailor', 'Darzi']]);
    $this->embroidery = Category::create(['name' => 'Kadhai / Embroidery', 'is_active' => true, 'job_titles' => ['Embroidery Karigar']]);
    Category::flushCache();
});

function titledJob(User $employer, string $title, ?string $category = 'Kadhai / Embroidery'): JobListing
{
    return $employer->jobListings()->create([
        'title' => $title, 'description' => 'Hand work.', 'category' => $category,
        'status' => JobStatus::Active, 'vacancies' => 1, 'city' => 'Jaipur', 'state' => 'Rajasthan',
        'published_at' => now(),
    ]);
}

it('notes a job title that no category lists, once per title', function () {
    titledJob($this->employer, 'Zari Karigar');
    titledJob($this->employer, '  zari karigar ');
    titledJob($this->employer, 'Darzi', 'Tailoring');
    titledJob($this->employer, 'tailor', 'Tailoring');

    expect(CustomJobTitle::pluck('title', 'normalized')->all())->toBe(['zari karigar' => 'Zari Karigar']);
});

it('notes a title changed on an edit, and nothing on other edits', function () {
    $job = titledJob($this->employer, 'Darzi', 'Tailoring');

    $job->update(['vacancies' => 3]);
    expect(CustomJobTitle::count())->toBe(0);

    $job->update(['title' => 'Knitting Master']);
    expect(CustomJobTitle::pluck('normalized')->all())->toBe(['knitting master']);
});

it('still saves the job when noting its title fails', function () {
    Schema::drop('custom_job_titles');

    expect(titledJob($this->employer, 'Zari Karigar')->exists)->toBeTrue();
});

it('shows the admin each new title with who used it and where', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    titledJob($this->employer, 'Zari Karigar');
    titledJob($other, 'zari karigar');
    titledJob($other, 'Zari Karigar', 'Tailoring');
    titledJob($this->employer, 'Knitting Master', null);
    titledJob($this->employer, 'aa');
    CustomJobTitle::where('normalized', 'aa')->update(['hidden_at' => now()]);

    $this->actingAs($this->admin)->get('/admin/categories')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Categories')
            ->has('customTitles', 2)
            ->where('customTitles.0.title', 'Zari Karigar')
            ->where('customTitles.0.employers', 2)
            ->where('customTitles.0.jobs', 3)
            ->where('customTitles.0.category', 'Kadhai / Embroidery')
            ->where('customTitles.1.title', 'Knitting Master')
            ->where('customTitles.1.category', null));
});

it('adds a title to a category, so the job form offers it from then on', function () {
    titledJob($this->employer, 'zari karigar');
    $row = CustomJobTitle::firstOrFail();

    $this->actingAs($this->admin)
        ->post("/admin/categories/custom-titles/{$row->id}/add", ['category_id' => $this->embroidery->id, 'title' => 'Zari Karigar'])
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    expect($this->embroidery->fresh()->job_titles)->toBe(['Embroidery Karigar', 'Zari Karigar'])
        ->and(CustomJobTitle::count())->toBe(0)
        ->and(Category::cachedJobProfiles())->toContain(['title' => 'Zari Karigar', 'category' => 'Kadhai / Embroidery']);

    titledJob($this->employer, 'ZARI KARIGAR');
    expect(CustomJobTitle::count())->toBe(0);
});

it('does not list a title twice when the category has it already', function () {
    titledJob($this->employer, 'Kurta Master', 'Tailoring');
    $row = CustomJobTitle::firstOrFail();
    $this->tailoring->update(['job_titles' => ['Tailor', 'Darzi', 'kurta master']]);

    $this->actingAs($this->admin)
        ->post("/admin/categories/custom-titles/{$row->id}/add", ['category_id' => $this->tailoring->id, 'title' => 'Kurta Master'])
        ->assertRedirect();

    expect($this->tailoring->fresh()->job_titles)->toBe(['Tailor', 'Darzi', 'kurta master'])
        ->and(CustomJobTitle::count())->toBe(0);
});

it('refuses a category whose title list is full', function () {
    titledJob($this->employer, 'Zari Karigar');
    $row = CustomJobTitle::firstOrFail();
    $this->embroidery->update(['job_titles' => array_map(fn (int $i) => "Title {$i}", range(1, 40))]);

    $this->actingAs($this->admin)
        ->post("/admin/categories/custom-titles/{$row->id}/add", ['category_id' => $this->embroidery->id, 'title' => 'Zari Karigar'])
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'error');

    expect($this->embroidery->fresh()->job_titles)->toHaveCount(40)
        ->and(CustomJobTitle::find($row->id))->not->toBeNull();
});

it('hides a title for good', function () {
    titledJob($this->employer, 'aaa');
    $row = CustomJobTitle::firstOrFail();

    $this->actingAs($this->admin)->post("/admin/categories/custom-titles/{$row->id}/hide")->assertRedirect();
    titledJob($this->employer, 'AAA');

    expect($row->fresh()->hidden_at)->not->toBeNull()
        ->and(CustomJobTitle::count())->toBe(1)
        ->and(CustomJobTitle::forAdmin())->toBe([]);
});

it('keeps employers away from the new titles', function () {
    titledJob($this->employer, 'Zari Karigar');
    $row = CustomJobTitle::firstOrFail();

    $this->actingAs($this->employer)
        ->post("/admin/categories/custom-titles/{$row->id}/add", ['category_id' => $this->embroidery->id, 'title' => 'Zari Karigar'])
        ->assertForbidden();
    $this->actingAs($this->employer)->post("/admin/categories/custom-titles/{$row->id}/hide")->assertForbidden();

    expect($row->fresh()->hidden_at)->toBeNull()
        ->and($this->embroidery->fresh()->job_titles)->toBe(['Embroidery Karigar']);
});
