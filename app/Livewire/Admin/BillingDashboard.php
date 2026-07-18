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
    public function mount(): void
    {
        $this->authorize('manage-billing');
    }

    public function render(BillingMetricsService $metrics)
    {
        $series = $metrics->revenueSeries(12);
        $max = max(1, collect($series)->max('cents'));

        return view('livewire.admin.billing-dashboard', [
            'm'              => $metrics->summary(),
            'series'         => $series,
            'seriesMax'      => $max,
            'recentPayments' => $metrics->recentPayments(10),
        ])->layout('layouts.app');
    }
}
