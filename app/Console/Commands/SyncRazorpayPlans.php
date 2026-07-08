<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\RazorpayService;
use Illuminate\Console\Command;
use Throwable;

class SyncRazorpayPlans extends Command
{
    protected $signature = 'razorpay:sync-plans {--force : Recreate a Razorpay plan even if one is already linked}';

    protected $description = 'Create Razorpay plans for local plans missing a razorpay_plan_id and store the returned id';

    public function handle(RazorpayService $razorpay): int
    {
        if (! $razorpay->configured()) {
            $this->error('Razorpay keys are not configured. Set RAZORPAY_KEY and RAZORPAY_SECRET in .env.');

            return self::FAILURE;
        }

        $plans = Plan::query()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('razorpay_plan_id'))
            ->get();

        if ($plans->isEmpty()) {
            $this->info('All plans already linked to Razorpay. Use --force to recreate.');

            return self::SUCCESS;
        }

        foreach ($plans as $plan) {
            try {
                $id = $razorpay->createPlan($plan);
                $plan->update(['razorpay_plan_id' => $id]);
                $this->line("  <info>✓</info> {$plan->name} → {$id}");
            } catch (Throwable $e) {
                $this->error("  ✗ {$plan->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
