<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One received Stripe webhook. `stripe_id` is unique, which gives us
 * idempotency for free: a redelivered event maps to the same row.
 */
class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = ['stripe_id', 'type', 'status', 'payload', 'attempts', 'error', 'processed_at'];

    protected $casts = [
        'payload'      => 'array',
        'attempts'     => 'integer',
        'processed_at' => 'datetime',
    ];

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }
}
