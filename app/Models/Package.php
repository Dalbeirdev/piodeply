<?php

namespace App\Models;

use App\Enums\Architecture;
use App\Enums\InstallerType;
use App\Enums\PackageMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Package extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /** Safe package-manager id shape (same rule the agent relies on). */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9.\-+_]*$/';

    protected $fillable = [
        'package_category_id', 'client_id', 'name', 'slug', 'vendor', 'homepage',
        'description', 'license', 'installer_type', 'architecture',
        'winget_id', 'winget_scopeless', 'choco_id', 'is_active', 'management_mode',
    ];

    /**
     * The migration's DB-level default only helps a raw INSERT; Eloquent's
     * create() does not re-fetch the row afterwards, so a package made
     * without stating management_mode would hold PHP null in memory right
     * up until the next fresh() — and isDeployable() would crash on it the
     * same request it was created in. This is what actually keeps every
     * existing and future package "deploy" by default.
     */
    protected $attributes = [
        'management_mode' => 'deploy',
    ];

    protected function casts(): array
    {
        return [
            'installer_type'   => InstallerType::class,
            'architecture'     => Architecture::class,
            'is_active'        => 'boolean',
            'winget_scopeless' => 'boolean',
            'management_mode'  => PackageMode::class,
        ];
    }

    /**
     * Whether a job may EVER be queued for this package — the single check
     * every deployment path (direct, bulk, policy remediation) must agree
     * on, or a package that "cannot install" reappears through whichever
     * route forgot to ask.
     */
    public function isDeployable(): bool
    {
        return $this->is_active && $this->management_mode->isDeployable();
    }

    /**
     * Why an attempt was refused, for the interface to show BEFORE a click
     * rather than after an agent reports back. Null when deployable — the
     * caller decides what "no reason" means for its own UI.
     */
    public function blockedReason(): ?string
    {
        if (! $this->is_active) {
            return "\"{$this->name}\" has been removed from the catalogue.";
        }

        if (! $this->management_mode->isDeployable()) {
            return "\"{$this->name}\" is {$this->management_mode->label()} — ".$this->management_mode->clientExplanation();
        }

        return null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class, 'package_category_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PackageVersion::class)->orderByDesc('is_latest')->orderByDesc('id');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(PackageVersion::class)->where('is_latest', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Not "active/inactive" — whether a click on this row would actually
     * queue a job. A package can be active and still show green on the old
     * badge while being OS-managed or Store/MSIX, which is exactly the
     * "true, and useless" trap PackageMode was built to end (see its own
     * docblock). One definition, shared by the catalogue's summary cards
     * and its filter.
     */
    public function scopeManagementStatus(Builder $query, string $status): Builder
    {
        return match ($status) {
            'deployable' => $query->where('is_active', true)->where('management_mode', PackageMode::Deploy),
            'blocked'    => $query->where('is_active', true)->where('management_mode', '!=', PackageMode::Deploy),
            'inactive'   => $query->where('is_active', false),
            default      => $query,
        };
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** NULL client = the shared catalogue; set = private to that client. */
    public function isPrivate(): bool
    {
        return $this->client_id !== null;
    }

    /**
     * The tenancy rule in one place: a private package serves ITS client's
     * projects and nobody else's. This is what makes "the Super Admin can
     * see it but never reuse it for another client" true — the guard reads
     * the data, not the caller's role.
     */
    public function isUsableFor(Project $project): bool
    {
        return $this->client_id === null || $this->client_id === $project->client_id;
    }

    /** What this user's package lists contain: the catalogue + their own. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $tenantId = $user->tenantClientId();

        return $query->when($tenantId !== null, fn (Builder $q) => $q
            ->where(fn (Builder $w) => $w->whereNull('client_id')->orWhere('client_id', $tenantId)));
    }

    /**
     * visibleTo, further narrowed by the user's custom-role package scope:
     * deploy pickers show only the software the role allows. The funnel in
     * DeploymentService::queue re-checks, so this is convenience, not the
     * security boundary.
     */
    public function scopeDeployableBy(Builder $query, User $user): Builder
    {
        $query = $query->visibleTo($user);
        $overlay = $user->clientRole;

        return match ($overlay?->package_scope) {
            'packages'   => $query->whereIn('packages.id', $overlay->packages()->pluck('packages.id')),
            'categories' => $query->whereIn('package_category_id', $overlay->packageCategories()->pluck('package_categories.id')),
            default      => $query,
        };
    }

    /** Packages deployable to a given project: the catalogue + its client's. */
    public function scopeUsableFor(Builder $query, Project $project): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->whereNull('client_id')->orWhere('client_id', $project->client_id));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$term}%")
            ->orWhere('vendor', 'like', "%{$term}%")
            ->orWhere('winget_id', 'like', "%{$term}%")
            ->orWhere('choco_id', 'like', "%{$term}%"));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('packages')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
