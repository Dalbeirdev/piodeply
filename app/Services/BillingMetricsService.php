<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Payment;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;

/**
 * Rolls the local billing tables up into the numbers an operator watches: MRR /
 * ARR, the subscription funnel, revenue, churn, LTV, and coupon / affiliate
 * totals. Everything is derived from our own data, so it is fully testable
 * without Stripe and never makes a network call.
 */
class BillingMetricsService
{
    /** Statuses that represent recurring revenue in force. */
    private const REVENUE_STATUSES = ['active', 'past_due'];

    /** Monthly recurring revenue, in cents, across all paying accounts. */
    public function mrrCents(): int
    {
        return (int) Account::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->with('plan')
            ->get()
            ->sum(fn (Account $a) => $this->monthlyEquivalentCents($a));
    }

    public function arrCents(): int
    {
        return $this->mrrCents() * 12;
    }

    private function monthlyEquivalentCents(Account $account): int
    {
        $plan = $account->plan;
        if ($plan === null) {
            return 0;
        }

        return $account->billing_interval === 'year'
            ? (int) round($plan->yearly_price_cents / 12)
            : $plan->monthly_price_cents;
    }

    /** @return array<string,int> account counts keyed by billing status */
    public function statusBreakdown(): array
    {
        return Account::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    public function activeTrials(): int
    {
        return Account::where('status', 'trialing')->count();
    }

    /** Trials whose window has passed without converting to a paying status. */
    public function expiredTrials(): int
    {
        return Account::whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->whereIn('status', ['none', 'canceled', 'suspended'])
            ->count();
    }

    public function cancelledCount(): int
    {
        return Account::where('status', 'canceled')->count();
    }

    /** Accounts currently failing payment (past-due or suspended). */
    public function paymentIssues(): int
    {
        return Account::whereIn('status', ['past_due', 'suspended'])->count();
    }

    public function refundCount(): int
    {
        return WebhookEvent::where('type', 'charge.refunded')->count();
    }

    // ── Revenue ────────────────────────────────────────────────────────

    public function totalRevenueCents(): int
    {
        return (int) Payment::where('status', 'paid')->sum('amount_total');
    }

    /** @return Collection<int,Payment> the latest paid payments */
    public function recentPayments(int $limit = 10): Collection
    {
        return Payment::where('status', 'paid')->latest()->limit($limit)->get();
    }

    /**
     * Revenue per month for the trailing window.
     *
     * @return list<array{month: string, cents: int}>
     */
    public function revenueSeries(int $months = 12): array
    {
        $start = now()->copy()->startOfMonth()->subMonths($months - 1);

        $byMonth = Payment::where('status', 'paid')
            ->where('created_at', '>=', $start)
            ->get(['amount_total', 'created_at'])
            ->groupBy(fn (Payment $p) => $p->created_at->format('Y-m'));

        return collect(range($months - 1, 0))
            ->map(function (int $ago) use ($byMonth) {
                $month = now()->copy()->startOfMonth()->subMonths($ago);
                $key = $month->format('Y-m');

                return [
                    'month' => $month->format('M'),
                    'cents' => (int) ($byMonth->get($key)?->sum('amount_total') ?? 0),
                ];
            })
            ->all();
    }

    /** Lifetime value: total revenue divided by the paying-customer count. */
    public function lifetimeValueCents(): int
    {
        $customers = max(1, Account::whereNotNull('plan_id')->count());

        return (int) round($this->totalRevenueCents() / $customers);
    }

    /** Churn: cancelled ÷ (active + cancelled), as a percentage. */
    public function churnPercent(): int
    {
        $active = Account::whereIn('status', ['active', 'past_due', 'trialing'])->count();
        $cancelled = $this->cancelledCount();
        $denom = $active + $cancelled;

        return $denom === 0 ? 0 : (int) round($cancelled / $denom * 100);
    }

    // ── Coupons & affiliates ───────────────────────────────────────────

    /** @return array{redemptions:int, active:int, discount_cents:int} */
    public function couponStats(): array
    {
        return [
            'redemptions'    => CouponRedemption::count(),
            'active'         => Coupon::where('is_active', true)->count(),
            'discount_cents' => (int) CouponRedemption::sum('amount_discounted_cents'),
        ];
    }

    /** @return array{affiliates:int, pending_cents:int, approved_cents:int, paid_cents:int} */
    public function affiliateStats(): array
    {
        $byStatus = fn (string $s) => (int) AffiliateCommission::where('status', $s)->sum('amount_cents');

        return [
            'affiliates'     => Affiliate::count(),
            'pending_cents'  => $byStatus('pending'),
            'approved_cents' => $byStatus('approved'),
            'paid_cents'     => $byStatus('paid'),
        ];
    }

    /** Everything the dashboard needs, in one call. */
    public function summary(): array
    {
        return [
            'mrr_cents'       => $this->mrrCents(),
            'arr_cents'       => $this->arrCents(),
            'revenue_cents'   => $this->totalRevenueCents(),
            'ltv_cents'       => $this->lifetimeValueCents(),
            'churn_percent'   => $this->churnPercent(),
            'status'          => $this->statusBreakdown(),
            'active_trials'   => $this->activeTrials(),
            'expired_trials'  => $this->expiredTrials(),
            'cancelled'       => $this->cancelledCount(),
            'payment_issues'  => $this->paymentIssues(),
            'refunds'         => $this->refundCount(),
            'coupons'         => $this->couponStats(),
            'affiliates'      => $this->affiliateStats(),
        ];
    }
}
