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
        'computer_id', 'package_id', 'package_version_id',
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
}
