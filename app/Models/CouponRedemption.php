<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponRedemption extends Model
{
    use HasFactory;

    protected $fillable = ['coupon_id', 'account_id', 'amount_discounted_cents', 'redeemed_at'];

    protected $casts = [
        'amount_discounted_cents' => 'integer',
        'redeemed_at'             => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
