<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A named, hand-curated set of computers that cuts across clients and
 * projects — finance machines, kiosks, a pilot ring. A "device tag" is
 * simply membership of a group.
 */
class ComputerGroup extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['name', 'description', 'created_by'];

    public function computers(): BelongsToMany
    {
        return $this->belongsToMany(Computer::class)->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('computer-groups')
            ->logOnly(['name', 'description'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
