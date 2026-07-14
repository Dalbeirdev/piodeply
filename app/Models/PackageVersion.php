<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PackageVersion extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'package_id', 'version', 'installer_url', 'sha256', 'silent_args',
        'uninstall_args', 'release_date', 'file_size_bytes', 'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'release_date'    => 'date',
            'file_size_bytes' => 'integer',
            'is_latest'       => 'boolean',
        ];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('packages')
            ->logOnly(['package_id', 'version', 'installer_url', 'sha256', 'is_latest'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
