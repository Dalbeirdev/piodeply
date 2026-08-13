<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client-defined role: WHAT its holders may do (install/update/uninstall)
 * and WHERE. Three scope levels:
 *  - all:       every machine in the client's environment;
 *  - sites:     every machine in the chosen sites — machines that enrol
 *               into those sites later are covered automatically;
 *  - computers: exactly the listed machines.
 * An overlay on the ladder role, enforced at the deployment chokepoint and
 * in machine visibility — never a replacement for the tenancy rules,
 * always a further narrowing.
 */
class ClientRole extends Model
{
    use HasFactory;

    public const SCOPES = ['all', 'sites', 'computers'];

    public const PACKAGE_SCOPES = ['all', 'categories', 'packages'];

    protected $fillable = [
        'client_id', 'name', 'description',
        'can_install', 'can_update', 'can_uninstall', 'scope', 'package_scope',
        'requires_approval',
    ];

    protected function casts(): array
    {
        return [
            'can_install'       => 'boolean',
            'can_update'        => 'boolean',
            'can_uninstall'     => 'boolean',
            'requires_approval' => 'boolean',
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

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class);
    }

    public function packageCategories(): BelongsToMany
    {
        return $this->belongsToMany(PackageCategory::class, 'client_role_package_category');
    }

    /** May a holder of this role perform $action with $package on $computer? */
    public function allows(string $action, Computer $computer, ?Package $package = null): bool
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

        $machineOk = match ($this->scope) {
            'sites'     => $this->projects()->whereKey($computer->project_id)->exists(),
            'computers' => $this->computers()->whereKey($computer->id)->exists(),
            default     => true, // 'all'
        };

        return $machineOk && ($package === null || $this->allowsPackage($package));
    }

    /** The software dimension: is this package inside the role's list? */
    public function allowsPackage(Package $package): bool
    {
        return match ($this->package_scope) {
            'packages'   => $this->packages()->whereKey($package->id)->exists(),
            'categories' => $package->package_category_id !== null
                && $this->packageCategories()->whereKey($package->package_category_id)->exists(),
            default      => true, // 'all'
        };
    }

    /** Human summary for lists: "Install, Update · 2 sites". */
    public function summary(): string
    {
        $caps = array_keys(array_filter([
            'Install'   => $this->can_install,
            'Update'    => $this->can_update,
            'Uninstall' => $this->can_uninstall,
        ]));

        $where = match ($this->scope) {
            'sites' => $this->projects()->count().' '.str(project_term_lower())->plural($this->projects()->count()),
            'computers' => $this->computers()->count().' selected '.str('machine')->plural($this->computers()->count()),
            default => 'all machines',
        };

        $what = match ($this->package_scope) {
            'packages'   => $this->packages()->count().' allowed '.str('package')->plural($this->packages()->count()),
            'categories' => $this->packageCategories()->count().' '.str('category')->plural($this->packageCategories()->count()),
            default      => null,
        };

        return ($caps === [] ? 'No actions' : implode(', ', $caps)).' · '.$where
            .($what !== null ? ' · '.$what : '')
            .($this->requires_approval ? ' · needs approval' : '');
    }
}
