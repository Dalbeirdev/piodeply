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
        'created_by', 'last_enforced_at',
    ];

    protected function casts(): array
    {
        return [
            'action'           => PolicyAction::class,
            'mode'             => PolicyMode::class,
            'version_mode'     => PolicyVersionMode::class,
            'last_enforced_at' => 'datetime',
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
            ->logOnly(['project_id', 'package_id', 'action', 'mode', 'version_mode', 'desired_version', 'priority'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
