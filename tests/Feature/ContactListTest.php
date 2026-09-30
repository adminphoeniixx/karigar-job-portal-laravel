<?php

use App\Enums\ApplicationStatus;
use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\JobListing;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use Database\Seeders\DemoContactsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 1999, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 25, 'contact_unlock_limit' => 10, 'contact_database_limit' => 1000],
        'is_active' => true,
    ]);

    Subscription::create([
        'employer_id' => $this->employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->job = contactJob($this->employer, 'Handloom weaver');
});

function contactJob(User $employer, string $title): JobListing
{
    return $employer->jobListings()->create([
        'title' => $title, 'description' => 'Craft work', 'category' => 'Weaving',
        'status' => JobStatus::Active->value, 'vacancies' => 2, 'city' => 'Jaipur', 'state' => 'Rajasthan',
    ]);
}

function karigar(string $name, array $profile): User
{
    $worker = User::factory()->create(['role' => UserRole::Worker->value, 'name' => $name]);
    $worker->workerProfile()->create($profile);

    return $worker;
}

it('lists database contacts and applicant contacts apart', function () {
    $fromDatabase = karigar('Meena Weaver', ['phone' => '9000000011', 'skills' => ['Weaving'], 'state' => 'Rajasthan']);
    $applicant = karigar('Suresh Potter', ['phone' => '9000000012', 'skills' => ['Pottery'], 'state' => 'Gujarat']);

    $this->actingAs($this->employer)->post("/employer/workers/{$fromDatabase->workerProfile->id}/unlock");
    $application = $this->job->applications()->create(['worker_id' => $applicant->id, 'status' => ApplicationStatus::Pending->value]);
    $this->actingAs($this->employer)->post("/employer/applications/{$application->id}/unlock");

    $this->actingAs($this->employer)->get('/employer/workers/contacts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workers/Contacts')
            ->where('tab', 'database')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Meena Weaver')
            ->where('contacts.data.0.phone', '9000000011')
            ->where('usage.used', 2)
            ->where('usage.used_database', 1)
            ->where('usage.used_applicants', 1)
            ->where('usage.database_total', 1)
            ->where('usage.applicants_total', 1));

    $this->actingAs($this->employer)->get('/employer/workers/contacts/applicants')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workers/Contacts')
            ->where('tab', 'applicants')
            ->has('contacts.data', 1)
            ->where('contacts.data.0.name', 'Suresh Potter')
            ->where('contacts.data.0.job.title', 'Handloom weaver')
            ->where('contacts.data.0.stage', 'pending')
            ->has('jobs', 1));

    expect(WorkerContactUnlock::where('worker_id', $fromDatabase->id)->value('source'))->toBe('directory')
        ->and(WorkerContactUnlock::where('worker_id', $applicant->id)->value('source'))->toBe('application');
});

it('never shows another employer\'s contacts', function () {
    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $worker = karigar('Kavita Embroiderer', ['phone' => '9000000013']);

    WorkerContactUnlock::create(['employer_id' => $other->id, 'worker_id' => $worker->id, 'source' => 'directory']);
    $otherJob = contactJob($other, 'Embroidery');
    $otherJob->applications()->create(['worker_id' => $worker->id, 'status' => ApplicationStatus::Pending->value, 'contact_unlocked' => true]);

    $this->actingAs($this->employer)->get('/employer/workers/contacts')
        ->assertInertia(fn ($page) => $page->has('contacts.data', 0));
    $this->actingAs($this->employer)->get('/employer/workers/contacts/applicants')
        ->assertInertia(fn ($page) => $page->has('contacts.data', 0));
});

it('filters database contacts by name, skill, state and period', function () {
    $meena = karigar('Meena Weaver', ['phone' => '9000000011', 'skills' => ['Weaving', 'Dyeing'], 'state' => 'Rajasthan', 'city' => 'Jaipur']);
    $ravi = karigar('Ravi Carver', ['phone' => '9000000014', 'skills' => ['Wood Carving'], 'state' => 'Gujarat', 'city' => 'Surat']);

    WorkerContactUnlock::create(['employer_id' => $this->employer->id, 'worker_id' => $meena->id, 'source' => 'directory']);
    $old = WorkerContactUnlock::create(['employer_id' => $this->employer->id, 'worker_id' => $ravi->id, 'source' => 'directory']);
    $old->forceFill(['created_at' => now()->subMonths(2)])->save();

    $names = fn (array $query) => collect(
        $this->actingAs($this->employer)->get('/employer/workers/contacts?'.http_build_query($query))->viewData('page')['props']['contacts']['data']
    )->pluck('name')->all();

    expect($names(['q' => 'meena']))->toBe(['Meena Weaver'])
        ->and($names(['q' => '9000000014']))->toBe(['Ravi Carver'])
        ->and($names(['skill' => 'dyeing']))->toBe(['Meena Weaver'])
        ->and($names(['skill' => 'Weav']))->toBe([]) // whole skills only
        ->and($names(['state' => 'Gujarat']))->toBe(['Ravi Carver'])
        ->and($names(['period' => 'cycle']))->toBe(['Meena Weaver'])
        ->and($names(['sort' => 'name']))->toBe(['Meena Weaver', 'Ravi Carver'])
        ->and($names(['sort' => 'oldest']))->toBe(['Ravi Carver', 'Meena Weaver']);
});

it('filters applicant contacts by job and stage', function () {
    $second = contactJob($this->employer, 'Block printer');
    $a = karigar('Asha Printer', ['phone' => '9000000015']);
    $b = karigar('Bhola Weaver', ['phone' => '9000000016']);

    $this->job->applications()->create(['worker_id' => $b->id, 'status' => ApplicationStatus::Pending->value, 'contact_unlocked' => true]);
    $second->applications()->create([
        'worker_id' => $a->id, 'status' => ApplicationStatus::Pending->value, 'contact_unlocked' => true, 'shortlisted_at' => now(),
    ]);
    // A locked applicant is not a contact yet.
    $second->applications()->create(['worker_id' => karigar('Locked One', ['phone' => '9000000017'])->id, 'status' => ApplicationStatus::Pending->value]);

    $names = fn (array $query) => collect(
        $this->actingAs($this->employer)->get('/employer/workers/contacts/applicants?'.http_build_query($query))->viewData('page')['props']['contacts']['data']
    )->pluck('name')->sort()->values()->all();

    expect($names([]))->toBe(['Asha Printer', 'Bhola Weaver'])
        ->and($names(['job' => $second->id]))->toBe(['Asha Printer'])
        ->and($names(['stage' => 'shortlisted']))->toBe(['Asha Printer'])
        ->and($names(['stage' => 'pending']))->toBe(['Bhola Weaver']);
});

it('serves both lists to the employer app', function () {
    $worker = karigar('Meena Weaver', ['phone' => '9000000011', 'skills' => ['Weaving']]);
    $this->actingAs($this->employer, 'sanctum')->postJson("/api/v1/employer/workers/{$worker->workerProfile->id}/unlock")->assertOk();

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/contacts/database?skill=Weaving')
        ->assertOk()
        ->assertJsonPath('contacts.total', 1)
        ->assertJsonPath('contacts.data.0.phone', '9000000011')
        ->assertJsonPath('usage.used_database', 1)
        ->assertJsonPath('usage.database_total', 1);

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/contacts/applicants?stage=hired')
        ->assertOk()
        ->assertJsonPath('contacts.total', 0)
        ->assertJsonPath('jobs.0.title', 'Handloom weaver');

    $this->actingAs($this->employer, 'sanctum')
        ->getJson('/api/v1/employer/contacts/applicants?stage=nonsense')
        ->assertStatus(422);
});

it('seeds demo database contacts for the test employer, once', function () {
    $this->employer->update(['phone' => '9000000001']);
    // Four weeks into the billing cycle, as on the live test account.
    Subscription::first()->update(['starts_at' => now()->subDays(27), 'ends_at' => now()->addDays(3)]);

    $this->seed(DemoContactsSeeder::class);
    $this->seed(DemoContactsSeeder::class);

    $page = $this->actingAs($this->employer)->get('/employer/workers/contacts?skill=wood+carving')->viewData('page')['props'];

    expect($page['usage']['database_total'])->toBe(100)
        ->and($page['usage']['used_database'])->toBe(40)
        ->and($page['contacts']['total'])->toBeGreaterThan(0)
        ->and(collect($page['contacts']['data'])->every(fn ($c) => in_array('Wood Carving', $c['skills'], true)))->toBeTrue()
        ->and(WorkerContactUnlock::where('employer_id', $this->employer->id)->count())->toBe(100)
        ->and(User::where('email', 'like', 'demo.karigar%@karigar.test')->count())->toBe(100);
});

it('puts the contact counts on the Worker Database page', function () {
    $worker = karigar('Meena Weaver', ['phone' => '9000000011']);
    WorkerContactUnlock::create(['employer_id' => $this->employer->id, 'worker_id' => $worker->id, 'source' => 'directory']);

    $this->actingAs($this->employer)->get('/employer/workers')
        ->assertInertia(fn ($page) => $page->where('contactCounts.database_total', 1)->where('contactCounts.applicants_total', 0));
});
