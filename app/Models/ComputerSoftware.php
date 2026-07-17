<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerSoftware extends Model
{
    use HasFactory;

    public const SOURCES = ['registry', 'msi', 'winget', 'choco'];

    protected $table = 'computer_software';

    protected $fillable = ['computer_id', 'name', 'version', 'available_version', 'publisher', 'source'];

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /**
     * Whether the machine's package manager is offering something newer.
     * Compared rather than trusted: winget occasionally reports an "available"
     * that is not actually ahead, and "141.0 -> 141.0 available" would be
     * noise an operator learns to ignore.
     */
    public function hasUpdate(): bool
    {
        // A blank installed version is unknown, not "older than everything":
        // version_compare('141.0', '', '>') is true, which would report an
        // update for every package whose version we failed to read.
        return $this->available_version !== null
            && trim((string) $this->version) !== ''
            && version_compare($this->available_version, $this->version, '>');
    }

    public function scopeWithUpdateAvailable(Builder $query): Builder
    {
        return $query->whereNotNull('available_version');
    }
}
