<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerSoftware extends Model
{
    use HasFactory;

    public const SOURCES = ['registry', 'msi', 'winget', 'choco'];

    protected $table = 'computer_software';

    protected $fillable = ['computer_id', 'name', 'version', 'publisher', 'source'];

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }
}
