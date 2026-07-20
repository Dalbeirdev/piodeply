<?php

namespace App\Models;

use App\Enums\Architecture;
use App\Enums\InstallerType;
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
        'winget_id', 'choco_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'installer_type' => InstallerType::class,
            'architecture'   => Architecture::class,
            'is_active'      => 'boolean',
        ];
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
