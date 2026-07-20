<?php

namespace App\Services;

use App\Enums\Role;
use App\Mail\AccountApprovedMail;
use App\Services\ClientSubscriptionService;
use App\Models\Client;
use App\Models\Signup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Turns a verified signup into a working account: a Client (the tenant),
 * an owner User bound to it, and the welcome email. One transaction — a
 * half-created account (client without login, login without tenant) is
 * worse than a failed approval that can simply be clicked again.
 */
class SignupApprovalService
{
    public function approve(Signup $signup, User $approver): User
    {
        if (! $signup->isOpen()) {
            throw new \DomainException('This signup has already been decided.');
        }

        if (User::where('email', $signup->email)->exists()) {
            throw new \DomainException("A user with {$signup->email} already exists — approve manually via Users.");
        }

        $owner = DB::transaction(function () use ($signup, $approver) {
            $client = Client::create([
                'company_name' => $signup->company_name,
                'email'        => $signup->email,
                'phone'        => $signup->phone,
                'country'      => $signup->country,
                'status'       => 'active',
            ]);

            $owner = new User([
                'name'  => $signup->contact_name,
                'email' => $signup->email,
            ]);
            // Hashed at signup; assigning via fill would double-hash it.
            $owner->forceFill([
                'password'          => $signup->password_hash,
                'client_id'         => $client->id,
                'email_verified_at' => now(), // payment + admin review vouch for the address
            ])->save();

            // Client Owner, client-bound: full management of their own
            // tenant (projects, computers, policies, their Team) and
            // nothing outside it — the exact scope the tenancy layer
            // already enforces and tests.
            $owner->assignRole(Role::ClientOwner->value);

            $signup->forceFill([
                'status'      => Signup::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'client_id'   => $client->id,
            ])->save();

            // The Stripe subscription created at checkout now belongs to a
            // real client — link them so renewal webhooks land somewhere.
            app(ClientSubscriptionService::class)->syncClientFromSignup($signup->fresh());

            activity('signups')
                ->causedBy($approver)
                ->performedOn($client)
                ->withProperties(['signup_id' => $signup->id, 'email' => $signup->email])
                ->log('signup_approved');

            return $owner;
        });

        // Outside the transaction: mail failure must not roll back the
        // account. The mailer queues/retries on its own.
        Mail::to($signup->email)->send(new AccountApprovedMail($signup));

        return $owner;
    }

    public function reject(Signup $signup, User $approver, string $reason): void
    {
        if (! $signup->isOpen()) {
            throw new \DomainException('This signup has already been decided.');
        }

        $signup->forceFill([
            'status'           => Signup::STATUS_REJECTED,
            'approved_by'      => $approver->id,
            'approved_at'      => now(),
            'rejection_reason' => trim($reason) !== '' ? trim($reason) : null,
        ])->save();

        activity('signups')
            ->causedBy($approver)
            ->withProperties(['signup_id' => $signup->id, 'email' => $signup->email])
            ->log('signup_rejected');
    }
}
