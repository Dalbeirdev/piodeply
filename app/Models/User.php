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
     */
    public function tenantClientId(): ?int
    {
        return $this->client_id;
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
            'password' => 'hashed',
        ];
    }
}
