<?php

use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the job plans, cheapest first, with Growth recommended and no made-up discounts', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::where('is_active', true)->where('type', Plan::TYPE_JOB)->orderBy('price')->get();

    expect($plans->pluck('name')->all())->toBe(['Single Job', 'Starter', 'Growth', 'Factory'])
        ->and($plans->pluck('price')->map(fn ($p) => (float) $p)->all())->toBe([499.0, 999.0, 1499.0, 2499.0])
        ->and($plans->map->durationDays()->all())->toBe([15, 30, 30, 30])
        ->and($plans->map->jobLiveDays()->all())->toBe([15, 30, 30, 30])
        ->and($plans->map->jobPostLimit()->all())->toBe([1, 1, 1, 3])
        ->and($plans->map->includedAiCredits()->all())->toBe([5, 10, 20, 40])
        ->and($plans->pluck('discount_percent')->all())->toBe([null, null, null, null])
        ->and($plans->filter->isRecommended()->pluck('name')->all())->toBe(['Growth']);
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

    $single = Plan::where('slug', 'single-job')->first();

    expect($single->featureList())
        ->toBe(['1 job post, live for 15 days', '20 database contact unlocks', 'AI-ranked applicants', '5 AI calling credits'])
        ->and($single->limitFeatureList())
        ->toContain('1 job post, live for 15 days', '20 database unlocks for 15 days', '5 AI calling credits')
        ->and($single->interval_label)->toBe('15 days');
});

it('caps job posts on every job plan', function () {
    $this->seed(PlanSeeder::class);

    // Plans step up on unlocks and AI credits, not posts: Single Job,
    // Starter and Growth all post one job.
    expect(Plan::where('type', Plan::TYPE_JOB)->get()->every(fn (Plan $plan) => $plan->jobPostLimit() > 0))->toBeTrue();
});

it('seeds three database plans that post no jobs', function () {
    $this->seed(PlanSeeder::class);

    $plans = Plan::where('type', Plan::TYPE_DATABASE)->orderBy('price')->get();

    expect($plans->pluck('slug')->all())->toBe(['database-basic', 'database-standard', 'database-pro'])
        ->and($plans->first()->featureList())->toContain('Access to 1,000 karigar contacts', '30 database unlocks for 30 days')
        ->and(collect($plans->first()->featureList())->contains(fn ($line) => str_contains($line, 'job post')))->toBeFalse();
});
