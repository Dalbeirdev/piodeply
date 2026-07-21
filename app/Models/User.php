<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasProfilePhoto;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Tenancy: a user bound to a client only sees that client's data.
     * Staff users have no client binding and see everything their
     * permissions allow.
     *
     * Fails closed: a tenant-facing (Client-role) account that was
     * created without a client binding must see nothing, not the whole
     * fleet — 0 matches no client, so every scope comes back empty.
     */
    public function tenantClientId(): ?int
    {
        if ($this->client_id !== null) {
            return $this->client_id;
        }

        return $this->hasRole(\App\Enums\Role::Client->value) ? 0 : null;
    }

    /**
     * Owns their organisation: billing, the team, everything. Managers and
     * below run the fleet but never the account — that separation is what
     * lets an owner hand out real authority without handing over the card.
     */
    public function isClientOwner(): bool
    {
        return $this->hasRole(\App\Enums\Role::ClientOwner->value);
    }

    /** Projects this user is explicitly confined to (none = unrestricted). */
    public function assignedProjects(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * The project ids this user may touch, or null for "all within their
     * tenant" (staff, owners, and any team member never explicitly
     * confined). Cached per request: tenancy checks run on every row of
     * every list.
     */
    public function visibleProjectIds(): ?array
    {
        if ($this->tenantClientId() === null) {
            return null; // staff — tenancy does not confine them
        }

        return once(function () {
            $ids = $this->assignedProjects()->pluck('projects.id')->all();

            return $ids === [] ? null : $ids;
        });
    }

    /** The per-project confinement check policies lean on. */
    public function canAccessProject(?int $projectId): bool
    {
        $allowed = $this->visibleProjectIds();

        return $allowed === null || in_array($projectId, $allowed, true);
    }

    /**
     * Local initials avatar (inline SVG) — the Jetstream default uses an
     * external avatar service, which breaks on offline/locked-down networks.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $initials = collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $segment) => mb_strtoupper(mb_substr($segment, 0, 1)))
            ->join('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'
            . '<rect width="100" height="100" fill="#0f766e"/>'
            . '<text x="50" y="54" font-family="ui-sans-serif,system-ui,sans-serif" font-size="40" '
            . 'fill="#ffffff" text-anchor="middle" dominant-baseline="middle">'
            . e($initials)
            . '</text></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
