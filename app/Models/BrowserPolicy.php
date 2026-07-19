<?php

namespace App\Models;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class BrowserPolicy extends Model
{
    use HasFactory;
    use LogsActivity;

    public const ACTIONS = ['disable', 'enable'];

    public const STATUSES = ['active', 'inactive'];

    protected $fillable = [
        'name', 'project_id', 'type', 'browsers', 'action',
        'settings', 'status', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type'     => BrowserPolicyType::class,
            'browsers' => 'array',
            'settings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function excludedComputers(): BelongsToMany
    {
        return $this->belongsToMany(Computer::class, 'browser_policy_exclusions')->withTimestamps();
    }

    public function results(): HasMany
    {
        return $this->hasMany(BrowserPolicyResult::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return list<Browser> */
    public function targetBrowsers(): array
    {
        $selected = $this->browsers ?? [];

        if ($selected === [] || in_array('all', $selected, true)) {
            return Browser::cases();
        }

        return array_values(array_filter(array_map(
            fn (string $value) => Browser::tryFrom($value),
            $selected
        )));
    }

    /**
     * e.g. "Disable incognito / private browsing". Value-typed policies
     * (forced homepage, forcelist) carry no meaningful enable/disable, so
     * they read as their own label.
     */
    public function label(): string
    {
        if ($this->type->valueKind() !== null) {
            return $this->type->label();
        }

        return ucfirst($this->action) . ' ' . lcfirst($this->type->label());
    }

    /**
     * The agent-facing operation map: browser value → operation spec.
     *
     * @return array<string, array>
     */
    public function operations(): array
    {
        $map = [];
        foreach ($this->targetBrowsers() as $browser) {
            $map[$browser->value] = $this->type->operationFor($browser, $this->action, $this->settings);
        }

        return $map;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('browser-policies')
            ->logOnly(['name', 'project_id', 'type', 'browsers', 'action', 'status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
