<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrowserPolicyResult extends Model
{
    use HasFactory;

    public const STATUSES = [
        'compliant', 'pending_restart', 'non_compliant',
        'unsupported', 'not_installed', 'error',
    ];

    protected $fillable = [
        'browser_policy_id', 'computer_id', 'browser',
        'status', 'detail', 'old_value', 'new_value', 'reported_at',
    ];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(BrowserPolicy::class, 'browser_policy_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class)->withTrashed();
    }
}
