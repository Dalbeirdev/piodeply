<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * The one place that turns a device count into a price. The public calculator,
 * the plans API, and (from Phase 3) checkout all resolve through here, so a
 * quote can never disagree with what a customer is charged.
 *
 * Plans are tiered, not linear: a fleet is quoted the smallest plan that fits
 * it. Fleets larger than the biggest plan fall through to an enterprise quote.
 */
class PricingService
{
    /** Above this device count there is no fixed plan — it's a sales quote. */
    public const ENTERPRISE_THRESHOLD = 5000;

    /** @return Collection<int, Plan> active plans, cheapest first */
    public function plans(): Collection
    {
        return Plan::query()->active()->ordered()->get();
    }

    /**
     * The smallest active plan whose ceiling covers this many devices, or null
     * when the fleet needs an enterprise quote (more than the largest plan).
     */
    public function recommendFor(int $devices): ?Plan
    {
        $devices = max(1, $devices);

        return $this->plans()
            ->first(fn (Plan $plan) => $plan->device_limit >= $devices);
    }

    public function isEnterprise(int $devices): bool
    {
        return $devices > self::ENTERPRISE_THRESHOLD || $this->recommendFor($devices) === null;
    }

    /**
     * A complete quote for a device count. Everything is derived from the
     * recommended plan, so the figures shown always match a real plan the
     * customer can buy.
     *
     * @return array{
     *     devices: int, is_enterprise: bool, plan: ?Plan,
     *     plan_name: ?string, device_limit: ?int,
     *     monthly_cents: int, yearly_cents: int,
     *     monthly: float, yearly: float,
     *     per_device_cents: int, per_device: float,
     *     savings_cents: int, savings: float, savings_percent: int
     * }
     */
    public function calculate(int $devices): array
    {
        $devices = max(1, $devices);
        $plan = $this->recommendFor($devices);

        if ($plan === null) {
            return [
                'devices'          => $devices,
                'is_enterprise'    => true,
                'plan'             => null,
                'plan_name'        => null,
                'device_limit'     => null,
                'monthly_cents'    => 0,
                'yearly_cents'     => 0,
                'monthly'          => 0.0,
                'yearly'           => 0.0,
                'per_device_cents' => 0,
                'per_device'       => 0.0,
                'savings_cents'    => 0,
                'savings'          => 0.0,
                'savings_percent'  => 0,
            ];
        }

        $yearlyIfMonthly = $plan->monthly_price_cents * 12;
        $savingsCents = $plan->yearlySavingsCents();
        $savingsPercent = $yearlyIfMonthly > 0
            ? (int) round($savingsCents / $yearlyIfMonthly * 100)
            : 0;

        return [
            'devices'          => $devices,
            'is_enterprise'    => false,
            'plan'             => $plan,
            'plan_name'        => $plan->name,
            'device_limit'     => $plan->device_limit,
            'monthly_cents'    => $plan->monthly_price_cents,
            'yearly_cents'     => $plan->yearly_price_cents,
            'monthly'          => $plan->monthlyPrice(),
            'yearly'           => $plan->yearlyPrice(),
            'per_device_cents' => $plan->perDeviceCents(),
            'per_device'       => round($plan->perDeviceCents() / 100, 2),
            'savings_cents'    => $savingsCents,
            'savings'          => round($savingsCents / 100, 2),
            'savings_percent'  => $savingsPercent,
        ];
    }
}
