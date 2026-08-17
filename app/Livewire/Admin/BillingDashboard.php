<?php

namespace App\Livewire\Admin;

use App\Services\BillingMetricsService;
use Livewire\Component;

/**
 * The admin billing overview: MRR / ARR, the subscription funnel, revenue and
 * its trend, churn / LTV, and coupon / affiliate totals — all from local data.
 */
class BillingDashboard extends Component
{
    /** Charted window, in months. Only these three are offered. */
    public int $months = 12;

    public function mount(): void
    {
        $this->authorize('manage-billing');
    }

    public function updatedMonths($value): void
    {
        // Bound from a select, so clamp rather than trust it.
        $this->months = in_array((int) $value, [3, 6, 12], true) ? (int) $value : 12;
    }

    public function render(BillingMetricsService $metrics)
    {
        $series = $metrics->revenueSeries($this->months);
        $max = max(1, collect($series)->max('cents'));

        return view('livewire.admin.billing-dashboard', [
            'm'              => $metrics->summary(),
            'series'         => $series,
            'seriesMax'      => $max,
            'recentPayments' => $metrics->recentPayments(10),
            'renewals'       => $metrics->upcomingRenewals(10),
            'renewalCents'   => $metrics->upcomingRenewalCents(),
            'periodCents'    => $metrics->revenueInPeriodCents($this->months),
            'trendPercent'   => $metrics->revenueTrendPercent($this->months),
        ])->layout('layouts.app');
    }
}
