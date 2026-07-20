<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent credential for a project. Only the SHA-256 hash is stored; the
 * plaintext is shown once at creation and never again. Revocation is a
 * timestamp, not a delete, so "which key stopped that fleet and when" stays
 * answerable.
 */
class ProjectApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'label', 'key_hash', 'key_prefix',
        'last_used_at', 'revoked_at', 'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at'   => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * Stamps usage at most every 10 minutes: agents heartbeat every minute,
     * and "last used roughly when" is worth one write per key per interval,
     * not one per heartbeat per machine.
     */
    public function touchUsage(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinutes(10))) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
