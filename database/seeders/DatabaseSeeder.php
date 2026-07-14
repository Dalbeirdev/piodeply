<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(PackagesSeeder::class);
        $this->call(DemoUsersSeeder::class); // local env only

        // Dev bootstrap account — change the password before any shared use.
        $superAdmin = User::firstOrCreate(
            ['email' => 'superadmin@piodeploy.local'],
            [
                'name'              => 'Super Admin',
                'password'          => bcrypt('ChangeMe-Now!1'),
                'email_verified_at' => now(),
            ]
        );
        $superAdmin->syncRoles([Role::SuperAdmin->value]);
    }
}
