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

            // A stored id that Stripe no longer knows is worse than an empty
            // one: it reads as "already synced" here while every checkout
            // dies on "No such price". Swapping the account behind
            // STRIPE_SECRET orphans the whole catalogue this way, so drop
            // what Stripe cannot confirm and let the create path rebuild it.
            $this->forgetVanishedIds($stripe, $plan, $dry);

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

    /**
     * Clear the ids Stripe cannot confirm. Only a definitive "this does not
     * exist" counts — a network blip or a bad key must never be read as
     * absence, or a sync run during an outage would orphan a live catalogue
     * and bill customers on freshly duplicated prices.
     */
    private function forgetVanishedIds($stripe, Plan $plan, bool $dry): void
    {
        if ($plan->stripe_product_id !== null && $this->isMissing(fn () => $stripe->products->retrieve($plan->stripe_product_id))) {
            $this->warn("  product {$plan->stripe_product_id} is not in this Stripe account - rebuilding");

            // Prices live inside the product, so they cannot outlive it.
            $plan->stripe_product_id = null;
            $plan->stripe_monthly_price_id = null;
            $plan->stripe_yearly_price_id = null;
            $dry || $plan->save();

            return;
        }

        foreach (['stripe_monthly_price_id' => 'monthly', 'stripe_yearly_price_id' => 'yearly'] as $column => $label) {
            $priceId = $plan->{$column};

            if ($priceId === null || ! $this->isUnusablePrice($stripe, $priceId)) {
                continue;
            }

            $this->warn("  {$label} price {$priceId} is missing or archived - rebuilding");
            $plan->{$column} = null;
            $dry || $plan->save();
        }
    }

    /** An archived price still resolves but can no longer be subscribed to. */
    private function isUnusablePrice($stripe, string $priceId): bool
    {
        try {
            return ! $stripe->prices->retrieve($priceId)->active;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($this->meansAbsent($e)) {
                return true;
            }

            throw $e;
        }
    }

    /** @param  callable():mixed  $lookup */
    private function isMissing(callable $lookup): bool
    {
        try {
            $lookup();

            return false;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($this->meansAbsent($e)) {
                return true;
            }

            throw $e;
        }
    }

    private function meansAbsent(\Stripe\Exception\InvalidRequestException $e): bool
    {
        return $e->getStripeCode() === 'resource_missing' || $e->getHttpStatus() === 404;
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
