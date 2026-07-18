<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateWithdrawal extends Model
{
    use HasFactory;

    protected $fillable = ['affiliate_id', 'amount_cents', 'status', 'method', 'reference', 'paid_at'];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at'      => 'datetime',
    ];

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}
