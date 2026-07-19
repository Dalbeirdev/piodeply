<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An admin-saved bundle of browser policies that can be applied to any
 * project in one click. Built-in templates are code-defined and never
 * stored here.
 */
class BrowserPolicyTemplate extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['name', 'description', 'policies', 'created_by'];

    protected function casts(): array
    {
        return ['policies' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('browser-policies')
            ->logOnly(['name', 'policies'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
