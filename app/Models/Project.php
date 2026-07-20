<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Project extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    public const API_KEY_PREFIX = 'pio_';

    protected $fillable = [
        'client_id', 'name', 'description', 'status',
        'api_key_hash', 'api_key_prefix', 'api_key_rotated_at', 'download_token',
    ];

    protected $hidden = ['api_key_hash'];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'api_key_rotated_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function computers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function apiKeys(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectApiKey::class);
    }

    public function softwarePolicies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SoftwarePolicy::class);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhereHas('client', fn (Builder $c) => $c->where('company_name', 'like', "%{$term}%")));
    }

    /**
     * Resolve a project from a plaintext agent API key. Returns null for
     * unknown/revoked keys and never touches plaintext storage. A project
     * can hold several keys at once; any active one authenticates, so
     * issuing or revoking one key never affects machines using another.
     */
    public static function findByApiKey(string $plainKey): ?self
    {
        if (! str_starts_with($plainKey, self::API_KEY_PREFIX)) {
            return null;
        }

        $key = ProjectApiKey::query()
            ->where('key_hash', hash('sha256', $plainKey))
            ->whereNull('revoked_at')
            ->first();

        if ($key === null) {
            return null;
        }

        $key->touchUsage();

        // The relation is withTrashed for record-keeping; a deleted
        // project's keys must not authenticate anything.
        return $key->project !== null && ! $key->project->trashed() ? $key->project : null;
    }

    public function downloadUrl(): string
    {
        // The agent-download route ships with the agent phase; the URL shape
        // is fixed now so it can be shared with clients early.
        return url('/download/agent/' . $this->download_token);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('projects')
            ->logOnly(['client_id', 'name', 'description', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
