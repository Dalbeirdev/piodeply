<?php

namespace App\Models;

use App\Enums\Browser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This machine has been on Edge 150.0.4078.83 since it was first seen on
 * 2026-08-01" — the fact computer_software cannot hold, because it is
 * overwritten wholesale on every report. Rows accumulate as a browser moves
 * through versions over a machine's life; which one is CURRENT is whichever
 * has the latest last_seen_at, not the latest id.
 */
class BrowserVersionObservation extends Model
{
    protected $fillable = ['computer_id', 'browser', 'version', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'browser'      => Browser::class,
            'first_seen_at' => 'datetime',
            'last_seen_at'  => 'datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
