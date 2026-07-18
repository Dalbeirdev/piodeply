<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a quote's internal thread — a system note when it arrives, or
 * an admin's follow-up as they work it.
 */
class QuoteMessage extends Model
{
    use HasFactory;

    protected $fillable = ['enterprise_quote_id', 'author', 'body'];

    public function quote(): BelongsTo
    {
        return $this->belongsTo(EnterpriseQuote::class, 'enterprise_quote_id');
    }
}
