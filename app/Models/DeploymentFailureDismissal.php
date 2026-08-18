<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * "I know about this, I've dealt with it" for one failure cause. Scoped to
 * the specific job that was failing when dismissed — a later, fresh failure
 * of the same cause has a higher job id and reappears on the queue rather
 * than staying silenced by an old acknowledgement.
 */
class DeploymentFailureDismissal extends Model
{
    protected $fillable = ['cause_key', 'last_seen_job_id', 'dismissed_by', 'dismissed_at'];

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }

    public function dismissedBy()
    {
        return $this->belongsTo(User::class, 'dismissed_by');
    }
}
