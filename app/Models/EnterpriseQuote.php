<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A request from a fleet that outgrew the largest fixed plan. Captured from
 * the public pricing page; worked by an admin through the status stages.
 */
class EnterpriseQuote extends Model
{
    use HasFactory;

    /** The lifecycle stages a quote moves through. */
    public const STATUSES = ['new', 'contacted', 'won', 'lost'];

    protected $fillable = [
        'company_name', 'contact_name', 'email', 'phone', 'country',
        'device_count', 'current_rmm', 'expected_growth', 'notes', 'status', 'ip',
    ];

    protected $casts = [
        'device_count' => 'integer',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(QuoteMessage::class)->latest();
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['new', 'contacted']);
    }
}
