<?php

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;
use App\Support\PricingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    Plan::create(['name' => 'Standard', 'slug' => 'standard', 'type' => 'job', 'price' => 999, 'currency' => 'INR', 'interval' => '30_days', 'features' => ['job_post_limit' => 3, 'featured' => true], 'is_active' => true]);
    Plan::create(['name' => 'Database Basic', 'slug' => 'database-basic', 'type' => 'database', 'price' => 299, 'currency' => 'INR', 'interval' => '30_days', 'features' => ['contact_unlock_limit' => 50], 'is_active' => true]);
    Plan::create(['name' => 'Old', 'slug' => 'old', 'type' => 'job', 'price' => 10, 'currency' => 'INR', 'interval' => '30_days', 'features' => [], 'is_active' => false]);
});

it('shows the active plans and credit packs to a guest', function () {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Pricing')
            ->where('viewer', 'guest')
            ->has('plans', 2)
            ->where('plans.0.name', 'Database Basic')
            ->where('plans.1.featured', true)
            ->has('plans.1.feature_list')
            ->has('packs'));
});

it('tells karigars the plans are for employers', function () {
    $worker = User::factory()->create(['role' => UserRole::Worker->value]);

    $this->actingAs($worker)->get('/pricing')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('viewer', 'other'));
});

it('sends a new employer from Buy straight to that plan\'s checkout', function () {
    $plan = Plan::where('slug', 'standard')->first();

    $this->get('/employer/login?redirect='.urlencode("/subscription?plan={$plan->id}"))
        ->assertRedirect(route('otp.show', 'employer'));

    $this->post('/otp/send', ['phone' => '9876501234']);

    $this->post('/employer/otp/verify', ['phone' => '9876501234', 'otp' => Cache::get('phone_otp.9876501234')])
        ->assertRedirect("/subscription?plan={$plan->id}");

    expect(User::where('phone', '9876501234')->first()->role)->toBe(UserRole::Employer);
});

it('still sends a new employer to the profile form when they did not come from Buy', function () {
    $this->post('/otp/send', ['phone' => '9876501235']);

    $this->post('/employer/otp/verify', ['phone' => '9876501235', 'otp' => Cache::get('phone_otp.9876501235')])
        ->assertRedirect('/employer/profile');
});

it('shows the page text the admin wrote, built-in text where they left a box blank', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->put('/admin/plans/page', [
        'headline' => 'Hire karigars without the guesswork.',
        'job_title' => 'Hiring plans',
        'intro' => '',
        'gst_note' => 'All prices + {gst}% GST.',
        'faqs' => [['q' => 'Refunds?', 'a' => 'Within 7 days, plus {gst}% GST back.']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    auth()->logout();

    $this->get('/pricing')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('page.headline', 'Hire karigars without the guesswork.')
        ->where('page.job_title', 'Hiring plans')
        ->where('page.intro', PricingPage::defaults()['intro'])
        ->where('page.gst_note', 'All prices + 18% GST.')
        ->has('page.faqs', 1)
        ->where('page.faqs.0.a', 'Within 7 days, plus 18% GST back.'));
});

it('hides the FAQ when the admin removes every question', function () {
    $admin = User::factory()->create(['role' => UserRole::Admin->value]);

    $this->actingAs($admin)->put('/admin/plans/page', ['faqs' => []])->assertSessionHasNoErrors();

    expect(PricingPage::forPage()['faqs'])->toBe([]);
});

it('keeps the page text out of reach of employers', function () {
    $employer = User::factory()->create(['role' => UserRole::Employer->value]);

    $this->actingAs($employer)->put('/admin/plans/page', ['headline' => 'x', 'faqs' => []])->assertForbidden();
});
