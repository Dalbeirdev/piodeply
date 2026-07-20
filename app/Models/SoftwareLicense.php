<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * A paid software license, owned by exactly one client. The key is
 * encrypted at rest; what the database and every staff screen carry is a
 * fingerprint (last 4), never the value. Decryption happens only through
 * revealKeyFor(), which refuses everyone outside the owning tenant.
 */
class SoftwareLicense extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'package_id', 'name', 'vendor',
        'seats', 'expires_at', 'notes', 'created_by',
    ];

    protected $hidden = ['license_key_encrypted'];

    protected function casts(): array
    {
        return [
            'seats'      => 'integer',
            'expires_at' => 'date',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SoftwareLicenseAssignment::class);
    }

    public function setKey(?string $plainKey): void
    {
        $plainKey = trim((string) $plainKey);

        if ($plainKey === '') {
            return; // blank = keep the stored key, mirroring the Stripe form
        }

        $this->forceFill([
            'license_key_encrypted' => Crypt::encryptString($plainKey),
            'key_last4'             => substr($plainKey, -4),
        ]);
    }

    /**
     * The only door to the plaintext. Owning tenant only — staff have the
     * fingerprint and the metadata; the key value is the client's secret,
     * which is what makes "staff can view but never reuse" real.
     */
    public function revealKeyFor(User $user): ?string
    {
        if ($user->tenantClientId() !== $this->client_id) {
            abort(403, 'License keys are visible only to the organisation that owns them.');
        }

        return $this->license_key_encrypted !== null
            ? Crypt::decryptString($this->license_key_encrypted)
            : null;
    }

    public function seatsUsed(): int
    {
        return $this->assignments()->count();
    }

    public function hasFreeSeat(): bool
    {
        return $this->seats === null || $this->seatsUsed() < $this->seats;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function expiresSoon(): bool
    {
        return $this->expires_at !== null
            && ! $this->isExpired()
            && $this->expires_at->lte(now()->addDays(30));
    }
}
