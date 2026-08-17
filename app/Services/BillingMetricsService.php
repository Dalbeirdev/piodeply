<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Client;
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
 *
 * The customer here is the CLIENT. These figures used to be computed from the
 * accounts table, which nothing in the codebase ever inserts into — one seeded
 * row, so every tile read zero no matter how many people were paying. The
 * subscription columns on clients are the ones webhooks actually maintain
 * (see ClientSubscriptionService), so they are the honest source.
 */
class BillingMetricsService
{
    /** Statuses that represent recurring revenue in force. */
    private const REVENUE_STATUSES = ['active', 'past_due'];

    /** Stripe's vocabulary for "subscribed, in some form". */
    private const LIVE_STATUSES = ['active', 'past_due', 'trialing'];

    /** Charging is failing or was never completed. */
    private const TROUBLE_STATUSES = ['past_due', 'unpaid', 'incomplete'];

    /**
     * Monthly recurring revenue, in cents, across all paying clients.
     *
     * subscription_cents is what the client is billed each period, written by
     * the invoice/subscription webhooks. There is no interval column, and
     * every plan sold through the portal is priced monthly, so it is taken as
     * a monthly figure.
     */
    public function mrrCents(): int
    {
        return (int) Client::query()
            ->whereIn('subscription_status', self::REVENUE_STATUSES)
            ->sum('subscription_cents');
    }

    public function arrCents(): int
    {
        return $this->mrrCents() * 12;
    }

    /** @return array<string,int> client counts keyed by subscription status */
    public function statusBreakdown(): array
    {
        return Client::query()
            ->whereNotNull('subscription_status')
            ->selectRaw('subscription_status, count(*) as total')
            ->groupBy('subscription_status')
            ->pluck('total', 'subscription_status')
            ->all();
    }

    public function activeTrials(): int
    {
        return Client::where('subscription_status', 'trialing')->count();
    }

    /** Trials whose window has passed without converting to a paying status. */
    public function expiredTrials(): int
    {
        return Client::whereNotNull('subscription_period_end')
            ->where('subscription_period_end', '<', now())
            ->whereIn('subscription_status', ['canceled', 'unpaid', 'incomplete'])
            ->count();
    }

    public function cancelledCount(): int
    {
        return Client::where('subscription_status', 'canceled')->count();
    }

    /** Clients whose charges are failing. */
    public function paymentIssues(): int
    {
        return Client::whereIn('subscription_status', self::TROUBLE_STATUSES)->count();
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

    /**
     * Revenue change against the preceding window of the same length, as a
     * percentage. Null when there is no earlier period to compare with —
     * showing "+100%" against nothing would be an invented number.
     */
    public function revenueTrendPercent(int $months = 12): ?int
    {
        $start = now()->copy()->startOfMonth()->subMonths($months - 1);
        $previousStart = $start->copy()->subMonths($months);

        $current = (int) Payment::where('status', 'paid')->where('created_at', '>=', $start)->sum('amount_total');
        $previous = (int) Payment::where('status', 'paid')
            ->whereBetween('created_at', [$previousStart, $start])
            ->sum('amount_total');

        if ($previous === 0) {
            return null;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }

    /** Revenue inside the charted window, which the headline figure labels. */
    public function revenueInPeriodCents(int $months = 12): int
    {
        return (int) Payment::where('status', 'paid')
            ->where('created_at', '>=', now()->copy()->startOfMonth()->subMonths($months - 1))
            ->sum('amount_total');
    }

    /**
     * Who is due to be charged next, soonest first — money expected in, as
     * opposed to money already taken. Only clients with a live subscription
     * and a known renewal date can appear.
     *
     * @return \Illuminate\Support\Collection<int,Client>
     */
    public function upcomingRenewals(int $limit = 10): Collection
    {
        return Client::query()
            ->whereIn('subscription_status', self::LIVE_STATUSES)
            ->whereNotNull('subscription_period_end')
            ->orderBy('subscription_period_end')
            ->limit($limit)
            ->get();
    }

    /** What the next round of renewals is worth in total, in cents. */
    public function upcomingRenewalCents(): int
    {
        return (int) Client::query()
            ->whereIn('subscription_status', self::LIVE_STATUSES)
            ->whereNotNull('subscription_period_end')
            ->sum('subscription_cents');
    }

    /**
     * Lifetime value: total revenue divided by the number of clients that
     * ever subscribed — including the ones who have since cancelled, or the
     * figure would climb every time a customer left.
     */
    public function lifetimeValueCents(): int
    {
        $customers = max(1, Client::whereNotNull('stripe_subscription_id')->count());

        return (int) round($this->totalRevenueCents() / $customers);
    }

    /** Churn: cancelled ÷ (live + cancelled), as a percentage. */
    public function churnPercent(): int
    {
        $live = Client::whereIn('subscription_status', self::LIVE_STATUSES)->count();
        $cancelled = $this->cancelledCount();
        $denom = $live + $cancelled;

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
