<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Affiliate;
use App\Models\AffiliateClick;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;

/**
 * The referral programme: record clicks, attribute a referred install to an
 * affiliate, accrue commission when it pays, and manage approvals + payouts.
 * All computation is local, so the engine is unit-tested without Stripe.
 */
class AffiliateService
{
    /** An approved affiliate for this code, or null. */
    public function resolve(?string $code): ?Affiliate
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return Affiliate::query()->approved()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))])
            ->first();
    }

    public function recordClick(string $code, ?string $ip = null, ?string $path = null, ?string $referer = null): ?AffiliateClick
    {
        $affiliate = $this->resolve($code);
        if ($affiliate === null) {
            return null;
        }

        return AffiliateClick::create([
            'affiliate_id' => $affiliate->id,
            'ip'           => $ip,
            'landing_path' => $path ? mb_substr($path, 0, 255) : null,
            'referer'      => $referer ? mb_substr($referer, 0, 255) : null,
        ]);
    }

    /** Attribute an install to an affiliate (first referrer wins, never overwritten). */
    public function stampAccountReferrer(Account $account, ?string $code): void
    {
        if ($account->referred_by_affiliate_id !== null) {
            return;
        }

        $affiliate = $this->resolve($code);
        if ($affiliate !== null) {
            $account->forceFill(['referred_by_affiliate_id' => $affiliate->id])->save();
        }
    }

    /**
     * Accrue commission for a paid invoice. Idempotent by (affiliate, invoice);
     * non-recurring affiliates only earn on the first invoice for an account.
     */
    public function accrueCommission(Account $account, ?string $invoiceId, int $baseCents): ?AffiliateCommission
    {
        $affiliate = $account->referrer;
        if ($affiliate === null || ! $affiliate->isApproved()) {
            return null;
        }

        if (! $affiliate->recurring
            && $affiliate->commissions()->where('account_id', $account->id)->exists()) {
            return null; // one-time affiliate already earned on this account
        }

        $key = $invoiceId ?: ('acct' . $account->id . '-first');
        $existing = AffiliateCommission::where('affiliate_id', $affiliate->id)
            ->where('source_invoice', $key)->first();
        if ($existing !== null) {
            return $existing; // redelivered invoice → same commission
        }

        return AffiliateCommission::create([
            'affiliate_id'      => $affiliate->id,
            'account_id'        => $account->id,
            'source_invoice'    => $key,
            'base_amount_cents' => $baseCents,
            'amount_cents'      => $affiliate->commissionFor($baseCents),
            'status'            => 'pending',
        ]);
    }

    public function approve(AffiliateCommission $commission): void
    {
        $commission->update(['status' => 'approved', 'approved_at' => now()]);
    }

    public function reject(AffiliateCommission $commission): void
    {
        $commission->update(['status' => 'rejected']);
    }

    /**
     * Record a payout request for the affiliate's available balance. Serialised
     * with a row lock so two concurrent requests can't both pass the balance
     * check and over-draw (double-payout race).
     */
    public function requestWithdrawal(Affiliate $affiliate, int $amountCents, ?string $method = null): AffiliateWithdrawal
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($affiliate, $amountCents, $method) {
            // Lock the affiliate row; the second concurrent payout waits here.
            $locked = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $available = $locked->availableBalanceCents();

            if ($amountCents < 1 || $amountCents > $available) {
                throw new \RuntimeException('Withdrawal exceeds the available balance.');
            }

            return AffiliateWithdrawal::create([
                'affiliate_id' => $locked->id,
                'amount_cents' => $amountCents,
                'status'       => 'requested',
                'method'       => $method,
            ]);
        });
    }

    public function payWithdrawal(AffiliateWithdrawal $withdrawal, ?string $reference = null): void
    {
        $withdrawal->update(['status' => 'paid', 'paid_at' => now(), 'reference' => $reference]);

        // Mark the covered commissions paid, oldest first.
        $remaining = $withdrawal->amount_cents;
        $commissions = $withdrawal->affiliate->commissions()
            ->where('status', 'approved')->orderBy('id')->get();
        foreach ($commissions as $commission) {
            if ($remaining <= 0) {
                break;
            }
            $commission->update(['status' => 'paid', 'paid_at' => now()]);
            $remaining -= $commission->amount_cents;
        }
    }

    /**
     * @return array{clicks:int, conversions:int, revenue_cents:int,
     *     pending_cents:int, approved_cents:int, paid_cents:int, available_cents:int}
     */
    public function stats(Affiliate $affiliate): array
    {
        $byStatus = fn (string $s) => (int) $affiliate->commissions()->where('status', $s)->sum('amount_cents');

        return [
            'clicks'         => $affiliate->clicks()->count(),
            'conversions'    => $affiliate->commissions()->distinct('account_id')->count('account_id'),
            'revenue_cents'  => (int) $affiliate->commissions()->sum('base_amount_cents'),
            'pending_cents'  => $byStatus('pending'),
            'approved_cents' => $byStatus('approved'),
            'paid_cents'     => $byStatus('paid'),
            'available_cents' => $affiliate->availableBalanceCents(),
        ];
    }
}
