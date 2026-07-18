<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A referral partner. `code` is the ?ref= slug; `commission_rate` is a percent
 * (percentage type) or a flat cents amount (fixed type). `recurring` decides
 * whether commission accrues on every invoice or only the first.
 */
class Affiliate extends Model
{
    use HasFactory;

    public const TYPES = ['percentage', 'fixed'];

    protected $fillable = [
        'user_id', 'name', 'email', 'code', 'commission_type',
        'commission_rate', 'recurring', 'status', 'payout_method',
    ];

    protected $casts = [
        'commission_rate' => 'integer',
        'recurring'       => 'boolean',
    ];

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** The commission for a given charge, in cents. */
    public function commissionFor(int $baseCents): int
    {
        return $this->commission_type === 'fixed'
            ? $this->commission_rate
            : (int) round($baseCents * min(100, $this->commission_rate) / 100);
    }

    /** Approved-but-unpaid commission the affiliate can withdraw, in cents. */
    public function availableBalanceCents(): int
    {
        $earned = (int) $this->commissions()->where('status', 'approved')->sum('amount_cents');
        $withdrawn = (int) $this->withdrawals()->whereIn('status', ['requested', 'paid'])->sum('amount_cents');

        return max(0, $earned - $withdrawn);
    }

    public function referralUrl(): string
    {
        return url('/register') . '?ref=' . $this->code;
    }
}
