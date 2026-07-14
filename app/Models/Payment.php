<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'provider', 'reference', 'customer_email', 'plan', 'quantity',
        'amount_total', 'currency', 'status', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
