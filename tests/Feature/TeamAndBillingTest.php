<?php

use App\Enums\JobStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['scout.driver' => 'null']);

    $this->owner = User::factory()->create(['role' => UserRole::Employer->value, 'phone' => '9800000001']);

    $this->plan = Plan::create([
        'name' => 'Pro', 'slug' => 'pro', 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 10, 'contact_unlock_limit' => 50],
        'is_active' => true,
    ]);

    Subscription::create([
        'employer_id' => $this->owner->id, 'plan_id' => $this->plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);

    $this->job = $this->owner->jobListings()->create([
        'title' => 'Plumber needed', 'description' => 'Fix pipes',
        'status' => JobStatus::Active->value, 'vacancies' => 1,
    ]);
});

function addMember(User $owner, string $phone, string $role): User
{
    $member = User::create([
        'name' => 'Member '.$phone, 'email' => $phone.'@phone.karigar', 'phone' => $phone,
        'password' => 'password', 'role' => 'employer', 'email_verified_at' => now(),
    ]);
    $owner->teamMembers()->create(['user_id' => $member->id, 'role' => $role]);

    return $member;
}

// ───────────────────────── TEAM MANAGEMENT ─────────────────────────

it('lets the owner add a team member by phone, creating their account', function () {
    $this->actingAs($this->owner)
        ->post('/employer/team', ['name' => 'Rakesh', 'phone' => '9811111111', 'role' => 'manager'])
        ->assertRedirect();

    $member = User::where('phone', '9811111111')->first();
    expect($member)->not->toBeNull()
        ->and($member->teamMembership->employer_id)->toBe($this->owner->id)
        ->and($member->teamRole())->toBe('manager');
});

it('blocks non-owners from managing the team', function () {
    $manager = addMember($this->owner, '9822222222', 'manager');

    $this->actingAs($manager)->get('/employer/team')->assertForbidden();
    $this->actingAs($manager)
        ->post('/employer/team', ['phone' => '9833333333', 'role' => 'recruiter'])
        ->assertForbidden();
});

it('lets a manager see and post jobs on the owner account', function () {
    $manager = addMember($this->owner, '9822222222', 'manager');

    // Sees the owner's jobs
    $this->actingAs($manager)->get('/employer/jobs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('jobs.data.0.title', 'Plumber needed'));

    // Posts a job that lands on the owner's account (owner's subscription applies)
    $this->actingAs($manager)->post('/employer/jobs', [
        'title' => 'Electrician wanted', 'description' => 'Wiring', 'vacancies' => 1,
        'status' => 'active', 'contact_mode' => 'apply',
    ])->assertRedirect('/employer/jobs');

    expect($this->owner->jobListings()->count())->toBe(2)
        ->and($manager->jobListings()->count())->toBe(0);
});

it('lets a recruiter view applicants and shortlist but not post jobs', function () {
    $recruiter = addMember($this->owner, '9844444444', 'recruiter');
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $application = $this->job->applications()->create(['worker_id' => $worker->id]);

    $this->actingAs($recruiter)->get("/employer/jobs/{$this->job->id}/applicants")->assertOk();

    $this->actingAs($recruiter)
        ->post("/employer/applications/{$application->id}/shortlist")
        ->assertRedirect();
    expect($application->fresh()->shortlisted_at)->not->toBeNull();

    // But cannot post or edit jobs
    $this->actingAs($recruiter)->post('/employer/jobs', [
        'title' => 'X', 'description' => 'Y', 'vacancies' => 1, 'status' => 'active', 'contact_mode' => 'apply',
    ])->assertForbidden();
    $this->actingAs($recruiter)->patch("/employer/jobs/{$this->job->id}", [
        'title' => 'Hacked', 'description' => 'Y', 'vacancies' => 1, 'status' => 'active', 'contact_mode' => 'apply',
    ])->assertForbidden();
});

it('keeps other employers away from the owner jobs', function () {
    $stranger = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($stranger)->get("/employer/jobs/{$this->job->id}/applicants")->assertForbidden();
});

// ───────────────────────── GST + INVOICE ─────────────────────────

it('issues a GST tax invoice when a subscription is activated', function () {
    config(['billing.gst_percent' => 18, 'billing.invoice_prefix' => 'KRG']);

    $subscription = Subscription::create([
        'employer_id' => $this->owner->id, 'plan_id' => $this->plan->id,
        'subtotal_amount' => 499, 'gst_percent' => 18,
        'gst_amount' => 89.82, 'total_amount' => 588.82,
        'status' => SubscriptionStatus::Created->value,
    ]);

    $subscription->activateWithInvoice();

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->invoice_number)->toBe(sprintf('KRG-%s-%05d', now()->format('Y'), $subscription->id))
        ->and($subscription->invoiced_at)->not->toBeNull();

    // Re-activation (e.g. webhook retries) keeps the same invoice number.
    $number = $subscription->invoice_number;
    $subscription->activateWithInvoice();
    expect($subscription->fresh()->invoice_number)->toBe($number);
});

it('shows the tax invoice to its owner only', function () {
    $subscription = Subscription::create([
        'employer_id' => $this->owner->id, 'plan_id' => $this->plan->id,
        'subtotal_amount' => 499, 'gst_percent' => 18, 'gst_amount' => 89.82, 'total_amount' => 588.82,
        'status' => SubscriptionStatus::Created->value,
    ]);
    $subscription->activateWithInvoice();

    $this->actingAs($this->owner)
        ->get("/subscription/{$subscription->id}/invoice")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/Invoice')
            ->where('invoice.number', $subscription->invoice_number)
            ->where('invoice.total', '588.82'));

    $other = User::factory()->create(['role' => UserRole::Employer->value]);
    $this->actingAs($other)->get("/subscription/{$subscription->id}/invoice")->assertForbidden();
});

// ───────────────────────── ADMIN DIRECTORIES + REPORTS ─────────────────────────

it('shows separate employer and karigar directories with location filters', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);
    $this->owner->employerProfile()->create(['company_name' => 'Sharma Co', 'city' => 'Jaipur', 'state' => 'Rajasthan']);

    $worker = User::factory()->create(['role' => UserRole::Worker->value]);
    $worker->workerProfile()->create(['city' => 'Pune', 'state' => 'Maharashtra', 'skills' => ['Plumbing']]);

    $this->actingAs($admin)->get('/admin/employers?state=Rajasthan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Employers')
            ->has('employers.data', 1)
            ->where('employers.data.0.company', 'Sharma Co'));

    $this->actingAs($admin)->get('/admin/karigars?state=Maharashtra&skill=Plumbing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Karigars')->has('workers.data', 1));

    $this->actingAs($admin)->get('/admin/karigars?state=Rajasthan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('workers.data', 0));
});

it('renders admin reports with filters and exports CSV', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->get('/admin/reports?state=Rajasthan')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Reports')
            ->has('tiles')->has('monthly')->has('topCities'));

    $csv = $this->actingAs($admin)->get('/admin/reports/export?type=jobs')
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8')
        ->streamedContent();
    expect($csv)->toContain('Title');
});

it('accepts regional language locales', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    foreach (['mr', 'bn', 'ta', 'te', 'gu'] as $locale) {
        $this->actingAs($worker)->post('/locale', ['locale' => $locale])->assertRedirect();
        expect($worker->fresh()->locale)->toBe($locale);
    }

    $this->actingAs($worker)->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});
