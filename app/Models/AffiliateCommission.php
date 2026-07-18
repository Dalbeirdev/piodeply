<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    use HasFactory;

    protected $fillable = [
        'affiliate_id', 'account_id', 'source_invoice',
        'base_amount_cents', 'amount_cents', 'status', 'approved_at', 'paid_at',
    ];

    protected $casts = [
        'base_amount_cents' => 'integer',
        'amount_cents'      => 'integer',
        'approved_at'       => 'datetime',
        'paid_at'           => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
