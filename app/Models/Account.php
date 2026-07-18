<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;

/**
 * The MSP account — the single billing tenant for this install and the Cashier
 * "customer". One subscription lives here; the current plan's device limit
 * caps the total number of Computers across the account's projects.
 *
 * There is one Account per install; `current()` is the canonical accessor.
 */
class Account extends Model
{
    use Billable, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'plan_id', 'billing_interval', 'status',
        'device_limit', 'device_limit_overridden', 'grace_ends_at',
        'trial_reminder_sent_at',
    ];

    protected $casts = [
        'trial_ends_at'           => 'datetime',
        'grace_ends_at'           => 'datetime',
        'trial_reminder_sent_at'  => 'datetime',
        'device_limit'            => 'integer',
        'device_limit_overridden' => 'boolean',
    ];

    /** New accounts are not yet on any plan — reflect the DB default in memory. */
    protected $attributes = [
        'status' => 'none',
    ];

    /** The one account for this install (created on first access). */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create(['name' => config('app.name', 'PioDeploy')]);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    // ── Device limit (Module 11 basis) ─────────────────────────────────

    /** Total managed machines across the whole install. */
    public function deviceCount(): int
    {
        return Computer::count();
    }

    /** The ceiling in force: an admin override, else the plan's limit. */
    public function effectiveDeviceLimit(): ?int
    {
        if ($this->device_limit !== null) {
            return $this->device_limit;
        }

        return $this->plan?->device_limit;
    }

    /** True when the fleet has grown past the plan's ceiling. */
    public function isOverDeviceLimit(): bool
    {
        $limit = $this->effectiveDeviceLimit();

        return $limit !== null && $this->deviceCount() > $limit;
    }

    public function remainingDevices(): ?int
    {
        $limit = $this->effectiveDeviceLimit();

        return $limit === null ? null : max(0, $limit - $this->deviceCount());
    }

    // ── Cashier customer identity ──────────────────────────────────────

    /** The admin who receives billing email (receipts, trial reminders). */
    public function billingContact(): ?User
    {
        return User::role([Role::SuperAdmin->value, Role::Admin->value])
            ->orderBy('id')->first();
    }

    public function stripeName(): ?string
    {
        return $this->name;
    }

    /**
     * Stripe wants an email for receipts; use the account's billing contact —
     * the first Super Admin / Admin — falling back to the app's from address.
     */
    public function stripeEmail(): ?string
    {
        $admin = User::role([Role::SuperAdmin->value, Role::Admin->value])
            ->orderBy('id')->first();

        return $admin?->email ?? config('mail.from.address');
    }

    // ── Billing state helpers ──────────────────────────────────────────

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function inGracePeriod(): bool
    {
        return $this->grace_ends_at !== null && $this->grace_ends_at->isFuture();
    }
}
