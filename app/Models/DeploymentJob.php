<?php

namespace App\Models;

use App\Enums\JobAction;
use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeploymentJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'computer_id', 'package_id', 'package_version_id', 'target_version',
        'installed_version_before',
        'action', 'status', 'priority', 'depends_on_job_id',
        'attempts', 'max_attempts', 'created_by',
        'claimed_at', 'finished_at', 'exit_code', 'output_log', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'action'      => JobAction::class,
            'status'      => JobStatus::class,
            'claimed_at'  => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function packageVersion(): BelongsTo
    {
        return $this->belongsTo(PackageVersion::class);
    }

    public function dependency(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_job_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where('status', JobStatus::Pending)
            ->orderBy('priority')       // 1 = highest first
            ->orderBy('id');            // FIFO within a priority
    }

    public function canRetry(): bool
    {
        return $this->attempts < $this->max_attempts;
    }

    /**
     * The version this job aims for: a pinned target (winget/choco) takes
     * precedence over the catalogue binary it was built from. Null means
     * "whatever the package source calls current", which only the agent
     * can resolve.
     */
    public function intendedVersion(): ?string
    {
        return $this->target_version ?? $this->packageVersion?->version;
    }

    /**
     * What this job does to the machine's version, in one string:
     * "138.0 → 141.0" when both ends are known, "138.0 → latest" when the
     * agent resolves the target, a bare version for a fresh install, and
     * null when nothing useful is knowable (binary packages).
     */
    public function versionLabel(): ?string
    {
        $from = $this->installed_version_before;
        $to = $this->intendedVersion();

        // Removal has no destination version.
        if ($this->action === JobAction::Uninstall) {
            return $from;
        }

        return match (true) {
            $from !== null && $to !== null => "{$from} → {$to}",
            $from !== null => "{$from} → latest",
            $to !== null => $to,
            default => null,
        };
    }
}
