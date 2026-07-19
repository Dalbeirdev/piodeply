<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Computer extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'project_id', 'ring', 'agent_uuid', 'agent_version', 'last_seen_at',
        'hostname', 'serial_number', 'manufacturer', 'model',
        'os_name', 'os_version', 'windows_build',
        'cpu', 'ram_bytes', 'disk_total_bytes', 'disk_free_bytes',
        'public_ip', 'private_ip', 'mac_address',
        'secure_boot', 'tpm_enabled', 'tpm_version', 'environment',
    ];

    protected function casts(): array
    {
        return [
            'ring' => \App\Enums\DeploymentRing::class,
            'last_seen_at' => 'datetime',
            'ram_bytes' => 'integer',
            'disk_total_bytes' => 'integer',
            'disk_free_bytes' => 'integer',
            'secure_boot' => 'boolean',
            'tpm_enabled' => 'boolean',
            'environment' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function software(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComputerSoftware::class);
    }

    public function browserPolicyResults(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BrowserPolicyResult::class);
    }

    /** @return list<string> */
    public static function softwareSources(): array
    {
        return ComputerSoftware::SOURCES;
    }

    public function client(): HasOneThrough
    {
        return $this->hasOneThrough(
            Client::class,
            Project::class,
            'id',         // projects.id
            'id',         // clients.id
            'project_id', // computers.project_id
            'client_id',  // projects.client_id
        )->withTrashed('projects.deleted_at');
    }

    /* ---- Online status is derived from the last heartbeat ---- */

    public static function onlineThreshold(): int
    {
        return (int) app(\App\Services\SettingsService::class)
            ->get('agent.online_threshold_seconds');
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::onlineThreshold()));
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen_at', '>', now()->subSeconds(self::onlineThreshold()));
    }

    public function scopeOffline(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('last_seen_at')
            ->orWhere('last_seen_at', '<=', now()->subSeconds(self::onlineThreshold())));
    }

    /* ---- Agent version ---- */

    /** The newest agent build the server publishes and self-updates toward. */
    public static function latestAgentVersion(): string
    {
        return \App\Services\EnrollmentScriptService::CURRENT_AGENT_VERSION;
    }

    /**
     * A machine is on an outdated agent when it has reported a version and
     * that version is behind the latest published build. A machine that has
     * never reported one is "unknown", not "outdated" — it is not counted, so
     * a never-enrolled stub can't inflate the update backlog.
     */
    public function isAgentOutdated(): bool
    {
        return $this->agent_version !== null
            && version_compare($this->agent_version, self::latestAgentVersion(), '<');
    }

    /**
     * Machines whose reported agent version is not the latest. Uses a string
     * inequality (SQL-friendly); the fleet only ever runs versions the server
     * has published, so "not equal to latest" and "older than latest" are the
     * same set in practice. isAgentOutdated() does the exact semver compare.
     */
    public function scopeAgentOutdated(Builder $query): Builder
    {
        return $query->whereNotNull('agent_version')
            ->where('agent_version', '!=', self::latestAgentVersion());
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('hostname', 'like', "%{$term}%")
            ->orWhere('serial_number', 'like', "%{$term}%")
            ->orWhere('model', 'like', "%{$term}%")
            ->orWhere('public_ip', 'like', "%{$term}%")
            ->orWhere('private_ip', 'like', "%{$term}%")
            ->orWhere('mac_address', 'like', "%{$term}%"));
    }

    /* ---- Presentation helpers ---- */

    public function ramForHumans(): ?string
    {
        return $this->ram_bytes === null ? null : self::bytesForHumans($this->ram_bytes);
    }

    public function diskForHumans(): ?string
    {
        if ($this->disk_total_bytes === null) {
            return null;
        }

        $total = self::bytesForHumans($this->disk_total_bytes);

        return $this->disk_free_bytes === null
            ? $total
            : self::bytesForHumans($this->disk_free_bytes) . ' free / ' . $total;
    }

    public static function bytesForHumans(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $value >= 100 ? 0 : 1) . ' ' . $units[$i];
    }

    /**
     * Heartbeats mutate last_seen_at constantly — keep them out of the
     * activity log; only meaningful assignment/identity changes are logged.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('computers')
            ->logOnly(['project_id', 'hostname', 'agent_version'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
