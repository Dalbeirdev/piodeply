<?php

namespace App\Models;

use App\Enums\PolicyAction;
use App\Enums\PolicyMode;
use App\Enums\PolicyVersionMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SoftwarePolicy extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Named priorities, mapped onto the 1–10 job priority scale. */
    public const PRIORITIES = [
        'Critical' => 1,
        'High'     => 3,
        'Normal'   => 5,
        'Low'      => 8,
    ];

    protected $fillable = [
        'project_id', 'package_id', 'action', 'mode',
        'version_mode', 'desired_version', 'priority',
        'frequency', 'window_days', 'window_start', 'window_end',
        'test_delay_days', 'production_delay_days', 'rollout_started_at',
        'created_by', 'last_enforced_at',
    ];

    protected function casts(): array
    {
        return [
            'action'             => PolicyAction::class,
            'mode'               => PolicyMode::class,
            'version_mode'       => PolicyVersionMode::class,
            'frequency'          => \App\Enums\PolicyFrequency::class,
            'window_days'        => 'array',
            'rollout_started_at' => 'datetime',
            'last_enforced_at'   => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function excludedComputers(): BelongsToMany
    {
        return $this->belongsToMany(Computer::class, 'software_policy_exclusions')
            ->withTimestamps();
    }

    /** May this policy queue jobs? (Audit computes compliance only.) */
    public function isEnforceable(): bool
    {
        return $this->mode === PolicyMode::Enforce && $this->package->is_active;
    }

    /** Does compliance get computed at all? */
    public function isActive(): bool
    {
        return $this->mode !== PolicyMode::Disabled;
    }

    /** e.g. "Auto Update Chrome", "Install 7-Zip 24.09 exactly". */
    public function label(): string
    {
        $verb = match ($this->action) {
            PolicyAction::Uninstall => 'Remove',
            default => $this->action->label(),
        };

        $version = match ($this->version_mode) {
            PolicyVersionMode::Exact => " {$this->desired_version} exactly",
            PolicyVersionMode::Minimum => " ≥ {$this->desired_version}",
            PolicyVersionMode::Maximum => " ≤ {$this->desired_version} (frozen)",
            default => '',
        };

        return "{$verb} {$this->package->name}{$version}";
    }

    /* ---- Scheduling ---- */

    /** No window configured means "run anytime". */
    public function hasWindow(): bool
    {
        return ! empty($this->window_days) && $this->window_start !== null && $this->window_end !== null;
    }

    /**
     * Is the maintenance window open right now? Handles overnight windows
     * (e.g. 22:00–04:00, where the window belongs to its starting day).
     */
    public function isInWindow(?\Carbon\CarbonInterface $at = null): bool
    {
        if (! $this->hasWindow()) {
            return true;
        }

        $at ??= now();
        $time = $at->format('H:i:s');
        $start = $this->window_start;
        $end = $this->window_end;

        if ($start <= $end) {
            return in_array($at->isoWeekday(), $this->window_days, false)
                && $time >= $start && $time <= $end;
        }

        // Overnight: today's window started yesterday evening or ends tomorrow.
        return (in_array($at->isoWeekday(), $this->window_days, false) && $time >= $start)
            || (in_array($at->copy()->subDay()->isoWeekday(), $this->window_days, false) && $time <= $end);
    }

    /** When does the given ring become eligible for this rollout? */
    public function ringEligibleAt(\App\Enums\DeploymentRing $ring): ?\Carbon\CarbonInterface
    {
        $start = $this->rollout_started_at ?? $this->created_at;

        return match ($ring) {
            \App\Enums\DeploymentRing::Emergency,
            \App\Enums\DeploymentRing::Pilot => $start,
            \App\Enums\DeploymentRing::Test => $start?->copy()->addDays($this->test_delay_days),
            \App\Enums\DeploymentRing::Production => $start?->copy()->addDays($this->production_delay_days),
        };
    }

    public function windowLabel(): string
    {
        if (! $this->hasWindow()) {
            return 'Anytime';
        }

        $days = collect($this->window_days)
            ->sort()
            ->map(fn (int $day) => \Carbon\Carbon::create(2024, 1, $day)->isoFormat('ddd')) // 2024-01-01 is a Monday
            ->join(', ');

        return $days . ' ' . substr($this->window_start, 0, 5) . '–' . substr($this->window_end, 0, 5);
    }

    public function priorityLabel(): string
    {
        return match (true) {
            $this->priority <= 2 => 'Critical',
            $this->priority <= 4 => 'High',
            $this->priority <= 6 => 'Normal',
            default => 'Low',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('policies')
            ->logOnly(['project_id', 'package_id', 'action', 'mode', 'version_mode', 'desired_version', 'priority',
                'frequency', 'window_days', 'window_start', 'window_end', 'test_delay_days', 'production_delay_days'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
