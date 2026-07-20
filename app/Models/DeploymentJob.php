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
     * A failure explained in plain terms and, where possible, what to do about
     * it. The raw "winget exited with -1073741515" is exact and useless unless
     * you already read Windows status codes; this sits above it, never instead.
     * Null when the exit code carries no known meaning — no invented advice.
     */
    public function failureHint(): ?string
    {
        return match ($this->exit_code) {
            // Windows NTSTATUS: the installer could not launch.
            -1073741515 => 'A required runtime is missing on the machine (0xC0000135, DLL not found) — '
                         . 'usually the Visual C++ Redistributable, or winget’s own App Installer runtime on a '
                         . 'minimal VM. Install it on the machine, then retry: '
                         . 'winget install Microsoft.VCRedist.2015+.x64',
            -1073741502 => 'A runtime failed to initialise on the machine (0xC0000142). Reboot it and retry; '
                         . 'if it persists, a Visual C++ Redistributable is missing or damaged.',
            -1073741819 => 'The installer crashed on the machine (0xC0000005, access violation) — often a '
                         . 'corrupt download or an incompatible build. Retry; if it repeats, the package needs a look.',

            // MSI install codes.
            1603 => 'A fatal error inside the installer (MSI 1603) — frequently a half-removed previous version, '
                  . 'or not enough disk. Check the machine’s free space and any leftover install.',
            1618 => 'Another install was already running on the machine (MSI 1618). This is transient — retry.',
            1619 => 'The installer package could not be opened (MSI 1619) — a bad or blocked download URL.',
            1625, 1643 => 'An organisation policy on the machine forbids this install (MSI 1625/1643) — a GPO or '
                        . 'restriction is blocking it.',

            // Access.
            -2147024891 => 'Access denied on the machine (0x80070005). The agent runs as SYSTEM, so this is usually '
                         . 'the target folder or registry key being locked by another process or policy.',

            // winget package-source problems.
            -1978335146 => 'winget found no machine-wide installer for this package (0x8A150056). The agent installs '
                . 'for all users (--scope machine); this package only publishes a per-user installer, which under '
                . 'the SYSTEM account would vanish into the SYSTEM profile. Package it as an EXE/MSI with '
                . 'machine-wide silent switches instead.',
            -1978335216 => 'winget has no installer for this machine’s architecture (0x8A150010) — e.g. an x64-only '
                         . 'package on an Arm device.',
            -1978334975 => 'The downloaded installer’s hash did not match (0x8A150041) — a corrupt or tampered '
                         . 'download. Retry; if it repeats, the package version needs checking.',

            default => null,
        };
    }

    /**
     * Why re-running this job could never work, if so. Retrying is offered on
     * anything failed, but some failures are in the job itself rather than the
     * machine — running it again just fails again, three more times.
     */
    public function impossibleReason(): ?string
    {
        if ($this->action === JobAction::Rollback && $this->target_version === null) {
            return 'No version was pinned, so there is nothing to roll back to. '
                 . 'Cancel this and queue a rollback with a version.';
        }

        return null;
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
