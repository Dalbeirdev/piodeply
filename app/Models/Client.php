<?php

namespace App\Models;

use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Client extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_name', 'email', 'phone',
        'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
        'timezone', 'logo_path', 'status', 'monthly_report',
        'billing_email', 'billing_address', 'billing_tax_id',
        'notes',
        'portal_name', 'tray_name', 'show_tray_icon',
    ];

    /**
     * A deleted client hands its email back.
     *
     * Client emails are unique, and the index counts soft-deleted rows — so
     * a deleted client used to own its address forever. The person behind it
     * could never sign up again, the operator had no way to release it, and
     * approving their application died on a constraint violation. Deleting
     * now parks the address under a reversible marker instead of holding it.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $client) {
            // A force delete removes the row, so the address frees itself.
            if ($client->isForceDeleting() || $client->emailIsParked()) {
                return;
            }

            $client->forceFill(['email' => self::PARK_PREFIX.$client->getKey().'.'.$client->email])->saveQuietly();
        });

        static::restoring(function (self $client) {
            $original = $client->parkedEmail();

            // Only take the address back if nobody claimed it meanwhile;
            // otherwise the restore would fail on the same unique index.
            if ($original !== null && ! static::withTrashed()->where('email', $original)->whereKeyNot($client->getKey())->exists()) {
                $client->email = $original;
            }
        });
    }

    /** Prefix marking an address released by deletion. */
    public const PARK_PREFIX = 'deleted';

    public function emailIsParked(): bool
    {
        return preg_match('/^'.self::PARK_PREFIX.'\d+\./', (string) $this->email) === 1;
    }

    /** The address this client had before deletion, or null if never parked. */
    public function parkedEmail(): ?string
    {
        if (! $this->emailIsParked()) {
            return null;
        }

        return preg_replace('/^'.self::PARK_PREFIX.'\d+\./', '', (string) $this->email);
    }

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'monthly_report' => 'boolean',
            'subscription_machines' => 'integer',
            'subscription_cents' => 'integer',
            'subscription_period_end' => 'datetime',
            'subscription_past_due_since' => 'datetime',
            'dunning_stage' => 'integer',
            'dunning_last_sent_at' => 'datetime',
            'billing_suspended_at' => 'datetime',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(ClientContact::class)->where('is_primary', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('company_name', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%")
            ->orWhere('city', 'like', "%{$term}%"));
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('clients')
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
