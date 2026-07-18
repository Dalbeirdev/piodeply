<?php

namespace App\Console\Commands;

use App\Models\Plan;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;

/**
 * Mirrors the local plan catalogue into Stripe: one Product per plan, with a
 * monthly and a yearly recurring Price, then stores the Stripe IDs back on the
 * plan. Idempotent — it reuses anything already created, so it is safe to run
 * after every deploy or price change.
 *
 *   php artisan billing:sync-stripe          # create/verify
 *   php artisan billing:sync-stripe --dry-run # show what it would do
 */
class SyncStripeProducts extends Command
{
    protected $signature = 'billing:sync-stripe {--dry-run : Report actions without calling Stripe}';

    protected $description = 'Create/verify Stripe products and prices for every plan';

    public function handle(): int
    {
        if (empty(config('cashier.secret'))) {
            $this->error('No Stripe secret key configured (STRIPE_SECRET). Aborting.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $stripe = Cashier::stripe();

        foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
            $this->line("Plan: {$plan->name}");

            // 1. Product
            if (! $plan->stripe_product_id) {
                if ($dry) {
                    $this->line('  would create product');
                } else {
                    $product = $stripe->products->create([
                        'name'     => "PioDeploy — {$plan->name}",
                        'metadata' => ['plan_slug' => $plan->slug, 'device_limit' => $plan->device_limit],
                    ]);
                    $plan->stripe_product_id = $product->id;
                    $plan->save();
                    $this->info("  product {$product->id}");
                }
            } else {
                $this->line("  product {$plan->stripe_product_id} (exists)");
            }

            // 2. Prices (monthly + yearly)
            $this->ensurePrice($stripe, $plan, 'month', $plan->monthly_price_cents, 'stripe_monthly_price_id', $dry);
            $this->ensurePrice($stripe, $plan, 'year', $plan->yearly_price_cents, 'stripe_yearly_price_id', $dry);
        }

        $this->newLine();
        $this->info($dry ? 'Dry run complete.' : 'Stripe products and prices are in sync.');

        return self::SUCCESS;
    }

    private function ensurePrice($stripe, Plan $plan, string $interval, int $amount, string $column, bool $dry): void
    {
        if ($plan->{$column}) {
            $this->line("  {$interval}ly price {$plan->{$column}} (exists)");

            return;
        }

        if ($dry) {
            $this->line("  would create {$interval}ly price at {$amount} cents");

            return;
        }

        $price = $stripe->prices->create([
            'product'     => $plan->stripe_product_id,
            'currency'    => strtolower($plan->currency),
            'unit_amount' => $amount,
            'recurring'   => ['interval' => $interval],
            'metadata'    => ['plan_slug' => $plan->slug, 'interval' => $interval],
        ]);

        $plan->{$column} = $price->id;
        $plan->save();
        $this->info("  {$interval}ly price {$price->id}");
    }
}
