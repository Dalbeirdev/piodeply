<?php

namespace App\Models;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Assignment scopes, least to most specific. When two active policies of
     * the same type cover one machine, the higher specificity wins; ties go
     * to the newer policy. scope_id is 0 for 'all'.
     */
    public const SPECIFICITY = [
        'all'      => 0,
        'client'   => 1,
        'project'  => 2,
        'group'    => 3,
        'computer' => 4,
    ];

    protected $fillable = [
        'name', 'project_id', 'scope_type', 'scope_id', 'type', 'browsers', 'action',
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

    /* ─────────────────────── Assignment scope ────────────────────────── */

    /**
     * Legacy writers (the v1 API, templates, factories) still create
     * policies with just a project_id — default the scope from it so every
     * write path resolves correctly without each caller knowing about
     * scopes.
     */
    protected static function booted(): void
    {
        static::creating(function (self $policy) {
            if ($policy->scope_type === null || $policy->scope_type === '') {
                $policy->scope_type = 'project';
            }
            if ($policy->scope_type === 'project' && (int) $policy->scope_id === 0 && $policy->project_id !== null) {
                $policy->scope_id = $policy->project_id;
            }
        });
    }

    public function specificity(): int
    {
        return self::SPECIFICITY[$this->scope_type] ?? 0;
    }

    /** Human name of the scope target, e.g. "Group: Finance workstations". */
    public function scopeName(): string
    {
        return match ($this->scope_type) {
            'all'      => 'All machines',
            'client'   => 'Client: '.(Client::find($this->scope_id)?->company_name ?? '?'),
            'project'  => 'Project: '.($this->project?->name ?? Project::withTrashed()->find($this->scope_id)?->name ?? '?'),
            'group'    => 'Group: '.(ComputerGroup::find($this->scope_id)?->name ?? '?'),
            'computer' => 'Computer: '.(Computer::withTrashed()->find($this->scope_id)?->hostname ?? '?'),
            default    => $this->scope_type,
        };
    }

    /** Query of every computer this policy covers (before exclusions). */
    public function targetComputers(): \Illuminate\Database\Eloquent\Builder
    {
        return match ($this->scope_type) {
            'all'      => Computer::query(),
            'client'   => Computer::whereHas('project', fn ($q) => $q->withTrashed()->where('client_id', $this->scope_id)),
            'project'  => Computer::where('project_id', $this->scope_id),
            'group'    => Computer::whereHas('groups', fn ($q) => $q->whereKey($this->scope_id)),
            'computer' => Computer::whereKey($this->scope_id),
            default    => Computer::whereRaw('1 = 0'),
        };
    }

    /**
     * The active policies that decide this machine's desired state:
     * everything in scope, minus its exclusions, deduplicated per policy
     * type by specificity (Computer > Group > Project > Client > All),
     * newest winning a tie. This IS the inheritance model.
     *
     * @return Collection<int, self>
     */
    public static function resolveFor(Computer $computer): \Illuminate\Support\Collection
    {
        $clientId = $computer->project()->withTrashed()->first()?->client_id;
        $groupIds = $computer->groups()->pluck('computer_groups.id')->all();

        return static::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q
                ->where('scope_type', 'all')
                ->orWhere(fn ($w) => $w->where('scope_type', 'client')->where('scope_id', $clientId ?? -1))
                ->orWhere(fn ($w) => $w->where('scope_type', 'project')->where('scope_id', $computer->project_id))
                ->orWhere(fn ($w) => $w->where('scope_type', 'computer')->where('scope_id', $computer->id))
                ->when($groupIds !== [], fn ($w) => $w
                    ->orWhere(fn ($g) => $g->where('scope_type', 'group')->whereIn('scope_id', $groupIds))))
            ->whereDoesntHave('excludedComputers', fn ($q) => $q->whereKey($computer->id))
            ->get()
            ->groupBy(fn (self $policy) => $policy->type->value)
            ->map(fn ($candidates) => $candidates
                ->sortBy([['id', 'desc']])
                ->sortByDesc(fn (self $policy) => $policy->specificity())
                ->first())
            ->values();
    }

    /**
     * Policies a user may see: staff see everything; a client-bound user
     * sees what can affect their machines — their own client and project
     * scopes, instance-wide policies, and group/computer scopes that touch
     * one of their computers.
     */
    public function scopeVisibleTo(Builder $query, ?int $tenantClientId): Builder
    {
        if ($tenantClientId === null) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('scope_type', 'all')
            ->orWhere(fn ($w) => $w->where('scope_type', 'client')->where('scope_id', $tenantClientId))
            ->orWhere(fn ($w) => $w->where('scope_type', 'project')
                ->whereIn('scope_id', Project::withTrashed()->where('client_id', $tenantClientId)->select('id')))
            ->orWhere(fn ($w) => $w->where('scope_type', 'computer')
                ->whereIn('scope_id', Computer::whereHas('project', fn ($p) => $p->withTrashed()->where('client_id', $tenantClientId))->select('id')))
            ->orWhere(fn ($w) => $w->where('scope_type', 'group')
                ->whereIn('scope_id', \Illuminate\Support\Facades\DB::table('computer_computer_group')
                    ->join('computers', 'computers.id', '=', 'computer_computer_group.computer_id')
                    ->join('projects', 'projects.id', '=', 'computers.project_id')
                    ->where('projects.client_id', $tenantClientId)
                    ->select('computer_computer_group.computer_group_id'))));
    }

    /** @return list<Browser> */
    public function targetBrowsers(): array
    {
        // A Windows-security type has exactly one surface — the OS — no
        // matter what the browsers field says; and browser types never
        // target the OS pseudo-browser, even under "all".
        if ($this->type->isWindowsPolicy()) {
            return [Browser::Windows];
        }

        $selected = $this->browsers ?? [];

        if ($selected === [] || in_array('all', $selected, true)) {
            return array_values(array_filter(Browser::cases(), fn (Browser $b) => $b !== Browser::Windows));
        }

        return array_values(array_filter(array_map(
            fn (string $value) => Browser::tryFrom($value),
            $selected
        ), fn (?Browser $b) => $b !== null && $b !== Browser::Windows));
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
