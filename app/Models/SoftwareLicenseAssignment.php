<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One seat of a license pinned to one computer. */
class SoftwareLicenseAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['software_license_id', 'computer_id', 'assigned_by'];

    public function license(): BelongsTo
    {
        return $this->belongsTo(SoftwareLicense::class, 'software_license_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class)->withTrashed();
    }
}
