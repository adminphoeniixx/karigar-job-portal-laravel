<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds four plans, cheapest first, with one recommended', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::where('is_active', true)->where('type', Plan::TYPE_JOB)->orderBy('price')->get();

    expect($plans->pluck('slug')->all())->toBe(['basic', 'standard', 'pro', 'enterprise'])
        ->and($plans->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([499.0, 999.0, 1999.0, 4999.0])
        ->and($plans->filter->isRecommended()->pluck('slug')->all())->toBe(['standard']);
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

    expect(Plan::where('slug', 'basic')->first()->featureList())
        ->toContain('1 job post, live for 30 days', '25 database unlocks every 30 days', 'Access to 1,000 karigar contacts')
        ->and(Plan::where('slug', 'enterprise')->first()->featureList())
        ->toContain('20 job posts, each live for 30 days', '500 database unlocks every 30 days');
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
