<?php

namespace App\Models;

use App\Enums\JobAction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SoftwarePolicy extends Model
{
    use HasFactory;
    use LogsActivity;

    /** Policy actions are a subset of job actions. */
    public const ACTIONS = ['install', 'update', 'uninstall'];

    protected $fillable = [
        'project_id', 'package_id', 'action', 'priority', 'is_active',
        'created_by', 'last_enforced_at',
    ];

    protected function casts(): array
    {
        return [
            'action'           => JobAction::class,
            'is_active'        => 'boolean',
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

    /** e.g. "Auto Update Chrome", "Remove Java" — for lists and logs. */
    public function label(): string
    {
        $verb = match ($this->action) {
            JobAction::Install => 'Install',
            JobAction::Update => 'Auto Update',
            JobAction::Uninstall => 'Remove',
            default => ucfirst($this->action->value),
        };

        return "{$verb} {$this->package->name}";
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('policies')
            ->logOnly(['project_id', 'package_id', 'action', 'is_active', 'priority'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
