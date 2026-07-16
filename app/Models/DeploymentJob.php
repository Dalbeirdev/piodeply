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
        'installed_version_before', 'installed_version_after',
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

    /**
     * A "task" is one computer + package + action. The same task asked for
     * repeatedly is one thing that happened N times, not N things.
     */
    private const SAME_TASK = 'x.computer_id = deployment_jobs.computer_id
                               and x.package_id = deployment_jobs.package_id
                               and x.action = deployment_jobs.action';

    /** Adds repeat_count: how many times this task has been queued. */
    public function scopeWithRepeatCount(Builder $query): Builder
    {
        return $query
            ->select('deployment_jobs.*')
            ->selectRaw('(select count(*) from deployment_jobs x where '.self::SAME_TASK.') as repeat_count');
    }

    /** Keeps only the newest job of each task — the current-state view. */
    public function scopeOnlyLatestPerTask(Builder $query): Builder
    {
        return $query->whereRaw(
            'deployment_jobs.id = (select max(x.id) from deployment_jobs x where '.self::SAME_TASK.')'
        );
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
     * winget exit codes that mean "nothing needed doing". The agent treats
     * these as success (see WingetInstaller.cs, which must stay in step), but
     * "Succeeded" alone hides the difference between installing something and
     * finding it already there.
     */
    private const WINGET_ALREADY_INSTALLED = -1978335189;            // 0x8A15002B

    private const WINGET_NO_APPLICABLE_UPGRADE = -1978335188;        // 0x8A15002C

    private const WINGET_ALREADY_INSTALLED_NO_UPGRADE = -1978335135; // 0x8A150061

    /**
     * Why this job is where it is, in words. Every branch reads from what was
     * actually recorded — an unexplained outcome says so rather than
     * inventing a reason.
     */
    public function reasonLabel(): string
    {
        return match ($this->status) {
            JobStatus::Failed => $this->failure_reason ?? 'Failed without reporting a reason',

            JobStatus::Cancelled => 'Cancelled before it ran',

            JobStatus::Blocked => $this->depends_on_job_id !== null
                ? "Waiting on job #{$this->depends_on_job_id} to succeed"
                : 'Blocked',

            JobStatus::Running => 'Running on the machine now',

            JobStatus::Pending => $this->attempts > 0
                ? 'Retrying after: ' . ($this->failure_reason ?? 'an unreported failure')
                    . " (attempt {$this->attempts} of {$this->max_attempts})"
                : 'Queued — waiting for the agent to check in',

            JobStatus::Succeeded => $this->successReason(),
        };
    }

    private function successReason(): string
    {
        return match ($this->exit_code) {
            self::WINGET_ALREADY_INSTALLED,
            self::WINGET_ALREADY_INSTALLED_NO_UPGRADE => 'Already installed — nothing was changed',
            self::WINGET_NO_APPLICABLE_UPGRADE => 'Already up to date — no newer version offered',
            default => match ($this->action) {
                JobAction::Uninstall => 'Removed',
                default => $this->installed_version_after !== null
                    ? "Completed — now on {$this->installed_version_after}"
                    : 'Completed',
            },
        };
    }

    /**
     * What this job does to the machine's version, in one string:
     * "138.0 → 141.0" when both ends are known, "138.0 → latest" while the
     * destination is still the agent's to resolve, a bare version for a
     * fresh install, and null when nothing is knowable (binary packages).
     *
     * Once the agent reports back, the destination is what was actually
     * found installed rather than what was asked for — an "→ latest" job
     * resolves to a real number, and a target that did not take is visible
     * instead of being assumed.
     */
    public function versionLabel(): ?string
    {
        $from = $this->installed_version_before;
        $to = $this->installed_version_after ?? $this->intendedVersion();

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
