<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the apna-priced job plans, cheapest first, with one recommended', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::where('is_active', true)->where('type', Plan::TYPE_JOB)->orderBy('price')->get();

    expect($plans->pluck('slug')->all())->toBe(['single-job', 'job-1-month', 'job-3-months', 'job-6-months'])
        ->and($plans->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([699.0, 1949.0, 3649.0, 7099.0])
        ->and($plans->map->durationDays()->all())->toBe([30, 30, 90, 180])
        ->and($plans->map->jobLiveDays()->all())->toBe([15, 15, 15, 15])
        ->and($plans->pluck('discount_percent')->all())->toBe([null, 7, 13, 22])
        ->and($plans->filter->isRecommended()->pluck('slug')->all())->toBe(['job-3-months']);
});

it('hides the old job plans and leaves admin edits alone when seeded again', function () {
    $old = Plan::create(['name' => 'Basic', 'slug' => 'basic', 'type' => Plan::TYPE_JOB, 'price' => 499, 'currency' => 'INR', 'interval' => Plan::INTERVAL_DAYS, 'duration_days' => 30, 'features' => ['job_post_limit' => 1], 'is_active' => true]);
    $this->seed(PlanSeeder::class);
    Plan::where('slug', 'job-1-month')->update(['price' => 1799]);

    $this->seed(PlanSeeder::class);

    expect($old->fresh()->is_active)->toBeFalse()
        ->and((float) Plan::where('slug', 'job-1-month')->value('price'))->toBe(1799.0);
});

it('makes an unlock cheaper on every step up', function () {
    $this->seed(PlanSeeder::class);

    $perUnlock = Plan::where('type', Plan::TYPE_JOB)->orderBy('price')->get()
        ->map(fn (Plan $p) => (float) $p->price / $p->contactUnlockLimit())
        ->all();

    expect($perUnlock)->toBe(collect($perUnlock)->sortDesc()->values()->all());
});

it('reads the plan limits the way the pricing page shows them', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::where('slug', 'single-job')->first()->featureList())
        ->toContain('1 job post, live for 15 days', '20 database unlocks every 30 days', 'Access to 1,000 karigar contacts')
        ->and(Plan::where('slug', 'job-6-months')->first()->featureList())
        ->toContain('13 job posts, each live for 15 days', '400 database unlocks every 180 days')
        ->and(Plan::where('slug', 'job-6-months')->first()->interval_label)->toBe('180 days');
});

it('caps job posts on every job plan, cheaper per post as the plan grows', function () {
    $this->seed(PlanSeeder::class);

    $jobPlans = Plan::where('type', Plan::TYPE_JOB)->orderBy('price')->get();
    $perPost = $jobPlans->map(fn (Plan $plan) => (float) $plan->price / $plan->jobPostLimit())->all();

    expect($jobPlans->every(fn (Plan $plan) => $plan->jobPostLimit() > 0))->toBeTrue()
        ->and($perPost)->toBe(collect($perPost)->sortDesc()->values()->all());
});

it('seeds three database plans that post no jobs', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::where('type', Plan::TYPE_DATABASE)->orderBy('price')->get();

    expect($plans->pluck('slug')->all())->toBe(['database-basic', 'database-standard', 'database-pro'])
        ->and($plans->first()->featureList())->toContain('Access to 1,000 karigar contacts', '50 database unlocks every 30 days')
        ->and(collect($plans->first()->featureList())->contains(fn ($line) => str_contains($line, 'job post')))->toBeFalse();
});
