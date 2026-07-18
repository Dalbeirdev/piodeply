<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixed subscription plan: a device ceiling at a monthly and yearly price.
 * Money lives in integer cents; the accessors expose dollar figures for the
 * UI without ever doing float math on the stored value.
 */
class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'device_limit',
        'monthly_price_cents', 'yearly_price_cents', 'currency',
        'features', 'is_recommended', 'is_active', 'sort_order',
        'stripe_monthly_price_id', 'stripe_yearly_price_id',
    ];

    protected $casts = [
        'device_limit'        => 'integer',
        'monthly_price_cents' => 'integer',
        'yearly_price_cents'  => 'integer',
        'features'            => 'array',
        'is_recommended'      => 'boolean',
        'is_active'           => 'boolean',
        'sort_order'          => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('device_limit');
    }

    public function monthlyPrice(): float
    {
        return round($this->monthly_price_cents / 100, 2);
    }

    public function yearlyPrice(): float
    {
        return round($this->yearly_price_cents / 100, 2);
    }

    /**
     * What one machine costs per month on this plan, at the plan's ceiling —
     * the honest "per device" figure a tiered plan can quote.
     */
    public function perDeviceCents(): int
    {
        return $this->device_limit > 0
            ? (int) round($this->monthly_price_cents / $this->device_limit)
            : 0;
    }

    /**
     * Yearly saving versus paying monthly for a year, in cents. Non-negative:
     * a plan is never seeded with a yearly price above 12x its monthly.
     */
    public function yearlySavingsCents(): int
    {
        return max(0, $this->monthly_price_cents * 12 - $this->yearly_price_cents);
    }

    public function features(): array
    {
        return $this->features ?? [];
    }
}
