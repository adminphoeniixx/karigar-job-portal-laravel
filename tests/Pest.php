<?php

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * An active job plan for an employer. Applicants are only visible with one
 * (ApplicantAccess), so tests about working applicants start here. Unlocks
 * and job posts are unmetered, as they were before plans gated applicants.
 */
function giveJobPlan(User $employer): Subscription
{
    $plan = Plan::firstOrCreate(['slug' => 'test-job-plan'], [
        'name' => 'Test Job Plan', 'type' => Plan::TYPE_JOB, 'price' => 499, 'currency' => 'INR', 'interval' => 'monthly',
        'features' => ['job_post_limit' => 0, 'contact_unlock_limit' => 0, 'contact_database_limit' => 1000],
        'is_active' => true,
    ]);

    return Subscription::create([
        'employer_id' => $employer->id, 'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active->value, 'starts_at' => now(), 'ends_at' => now()->addMonth(),
    ]);
}
