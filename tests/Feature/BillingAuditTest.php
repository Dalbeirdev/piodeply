<?php

namespace Tests\Feature;

use App\Livewire\Billing\Subscription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_billing_mutation_is_written_to_the_audit_log(): void
    {
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));

        Livewire::actingAs($admin)->test(Subscription::class)
            ->set('overrideLimit', 300)
            ->call('saveDeviceLimit');

        $this->assertDatabaseHas('activity_log', [
            'log_name'   => 'billing',
            'causer_id'  => $admin->id,
        ]);
    }
}
