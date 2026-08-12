<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client-defined role: WHAT its holders may do (install/update/uninstall)
 * and WHERE (every machine, or an explicit list). An overlay on the ladder
 * role, enforced at the deployment chokepoint and in machine visibility —
 * never a replacement for the tenancy rules, always a further narrowing.
 */
class ClientRole extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'name', 'description',
        'can_install', 'can_update', 'can_uninstall', 'all_computers',
    ];

    protected function casts(): array
    {
        return [
            'can_install'   => 'boolean',
            'can_update'    => 'boolean',
            'can_uninstall' => 'boolean',
            'all_computers' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function computers(): BelongsToMany
    {
        return $this->belongsToMany(Computer::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** May a holder of this role perform $action on $computer? */
    public function allows(string $action, Computer $computer): bool
    {
        $capability = match ($action) {
            'install', 'repair', 'rollback' => $this->can_install,
            'update'    => $this->can_update,
            'uninstall' => $this->can_uninstall,
            default     => false,
        };

        if (! $capability) {
            return false;
        }

        return $this->all_computers
            || $this->computers()->whereKey($computer->id)->exists();
    }

    /** Human summary for lists: "Install, Update · 10 machines". */
    public function summary(): string
    {
        $caps = array_keys(array_filter([
            'Install'   => $this->can_install,
            'Update'    => $this->can_update,
            'Uninstall' => $this->can_uninstall,
        ]));

        $where = $this->all_computers
            ? 'all machines'
            : $this->computers()->count().' selected '.str('machine')->plural($this->computers()->count());

        return ($caps === [] ? 'No actions' : implode(', ', $caps)).' · '.$where;
    }
}
