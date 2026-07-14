<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One demo account per role for local evaluation. Never runs outside
 * the local environment. Idempotent by email; shared dev password.
 */
class DemoUsersSeeder extends Seeder
{
    public const PASSWORD = 'admin@123';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $accounts = [
            ['Admin User', 'admin@piodeploy.local', Role::Admin, false],
            ['Manager User', 'manager@piodeploy.local', Role::Manager, false],
            ['Technician User', 'technician@piodeploy.local', Role::Technician, false],
            ['Viewer User', 'viewer@piodeploy.local', Role::Viewer, false],
            ['Client User', 'client@piodeploy.local', Role::Client, true],
        ];

        $client = Client::orderBy('id')->first();

        foreach ($accounts as [$name, $email, $role, $needsClient]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make(self::PASSWORD)]
            );

            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
                'client_id'         => $needsClient ? $client?->id : $user->client_id,
            ])->save();

            $user->syncRoles([$role->value]);
        }
    }
}
