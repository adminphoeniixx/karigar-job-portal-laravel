<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\RazorpayService;
use Illuminate\Console\Command;
use Throwable;

class SyncRazorpayPlans extends Command
{
    protected $signature = 'razorpay:sync-plans {--force : Recreate a Razorpay plan even if one is already linked}';

    protected $description = 'Create Razorpay plans (GST included) for local plans that have none, or whose price or GST rate changed';

    public function handle(RazorpayService $razorpay): int
    {
        if (! $razorpay->configured()) {
            $this->error('Razorpay keys are not configured. Set RAZORPAY_KEY and RAZORPAY_SECRET in .env.');

            return self::FAILURE;
        }

        $plans = Plan::query()->get()
            ->when(! $this->option('force'), fn ($plans) => $plans->reject->razorpayPlanIsCurrent());

        if ($plans->isEmpty()) {
            $this->info('Every plan already has a Razorpay plan at the current price and GST. Use --force to recreate.');

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            try {
                $id = $razorpay->createPlan($plan);
                $this->line("  <info>✓</info> {$plan->name} → {$id}");
            } catch (Throwable $e) {
                $this->error("  ✗ {$plan->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
