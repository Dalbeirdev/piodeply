<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pricing-page application: who wants an account, what they'll pay, and
 * where it stands. Becomes a real Client + login only on admin approval.
 */
class Signup extends Model
{
    use HasFactory;

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_AWAITING_VERIFICATION = 'awaiting_verification';

    public const STATUS_PAID = 'paid';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_name', 'contact_name', 'email', 'password_hash',
        'phone', 'country', 'machines', 'monthly_cents', 'currency',
        'payment_method', 'status', 'stripe_session_id', 'payment_reference', 'paid_at',
        'approved_by', 'approved_at', 'rejection_reason', 'client_id',
    ];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'machines'      => 'integer',
            'monthly_cents' => 'integer',
            'paid_at'       => 'datetime',
            'approved_at'   => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /** Still needs an admin decision. */
    public function isOpen(): bool
    {
        return ! in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    public function monthlyLabel(): string
    {
        return strtoupper($this->currency).' '.number_format($this->monthly_cents / 100, 2).'/mo';
    }
}
