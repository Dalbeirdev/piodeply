<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A deployment awaiting the account owner's decision. Filed automatically
 * when a member whose custom role requires approval tries to deploy;
 * approving queues the real job, rejecting closes it. Either way the row
 * stays as the audit trail.
 */
class DeploymentRequest extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'client_id', 'requester_id', 'computer_id', 'package_id',
        'action', 'target_version', 'status', 'decided_by', 'decided_at', 'job_id',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(DeploymentJob::class, 'job_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
