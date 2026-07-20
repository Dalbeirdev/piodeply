<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named bundle of software-policy definitions ("Standard workstation")
 * applied to a project in one click. Templates are GLOBAL — visible to
 * every tenant that can apply policies — which is why only staff may
 * create or delete them: a tenant-made template would leak that tenant's
 * software choices to everyone else.
 */
class PolicyTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_builtin', 'created_by'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PolicyTemplateItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
