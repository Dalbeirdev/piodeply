<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A discount an account can apply at checkout. `value` is interpreted by
 * `type`: a percentage (1..100), a fixed amount in cents, or a number of extra
 * trial days. `duration` mirrors Stripe (once / repeating / forever).
 */
class Coupon extends Model
{
    use HasFactory;

    public const TYPES = ['percent', 'fixed', 'trial_days'];

    public const DURATIONS = ['once', 'repeating', 'forever'];

    protected $fillable = [
        'coupon_category_id', 'code', 'name', 'description',
        'type', 'value', 'currency', 'duration', 'duration_in_months',
        'plan_id', 'redeem_by', 'max_redemptions', 'max_per_customer',
        'auto_apply', 'is_active', 'stripe_coupon_id', 'times_redeemed',
    ];

    protected $casts = [
        'value'              => 'integer',
        'duration_in_months' => 'integer',
        'plan_id'            => 'integer',
        'redeem_by'          => 'datetime',
        'max_redemptions'    => 'integer',
        'max_per_customer'   => 'integer',
        'auto_apply'         => 'boolean',
        'is_active'          => 'boolean',
        'times_redeemed'     => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(CouponCategory::class, 'coupon_category_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isExpired(): bool
    {
        return $this->redeem_by !== null && $this->redeem_by->isPast();
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->times_redeemed >= $this->max_redemptions;
    }

    /** A short human label, e.g. "20% off" or "$10 off" or "+30 trial days". */
    public function label(): string
    {
        return match ($this->type) {
            'percent'    => "{$this->value}% off",
            'fixed'      => '$' . number_format($this->value / 100, 2) . ' off',
            'trial_days' => "+{$this->value} trial days",
            default      => $this->code,
        };
    }
}
