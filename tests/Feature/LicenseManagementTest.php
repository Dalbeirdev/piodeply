<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Licenses\LicensesIndex;
use App\Models\Client;
use App\Models\Computer;
use App\Models\Project;
use App\Models\SoftwareLicense;
use App\Models\User;
use App\Services\LicenseService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class LicenseManagementTest extends TestCase
{
    use RefreshDatabase;

    private Client $clientA;

    private Client $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->clientA = Client::factory()->create(['company_name' => 'Alpha MSP']);
        $this->clientB = Client::factory()->create(['company_name' => 'Beta MSP']);
    }

    private function ownerOf(Client $client): User
    {
        return tap(User::factory()->create(['client_id' => $client->id]),
            fn (User $u) => $u->assignRole(RoleEnum::ClientOwner->value));
    }

    private function license(array $extra = [], string $key = 'ABCD-EFGH-1234'): SoftwareLicense
    {
        return app(LicenseService::class)->create($extra + [
            'client_id' => $this->clientA->id,
            'name'      => 'Office 2021 Pro',
        ], $key, null);
    }

    public function test_keys_are_encrypted_at_rest_with_only_a_fingerprint_visible(): void
    {
        $license = $this->license();

        $raw = \Illuminate\Support\Facades\DB::table('software_licenses')->value('license_key_encrypted');
        $this->assertStringNotContainsString('ABCD-EFGH-1234', (string) $raw, 'plaintext must never hit the database');
        $this->assertSame('1234', $license->key_last4);
        $this->assertSame('ABCD-EFGH-1234', Crypt::decryptString($raw));
    }

    public function test_only_the_owning_tenant_can_reveal_the_key(): void
    {
        $license = $this->license();

        $this->assertSame('ABCD-EFGH-1234', $license->revealKeyFor($this->ownerOf($this->clientA)));

        // Staff: metadata yes, key value never.
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
        try {
            $license->revealKeyFor($admin);
            $this->fail('staff must not read a client\'s license key');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Another tenant: same refusal.
        try {
            $license->revealKeyFor($this->ownerOf($this->clientB));
            $this->fail('another tenant must not read the key');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
        }
    }

    public function test_seats_are_enforced_and_assignment_is_client_bound(): void
    {
        $license = $this->license(['seats' => 2]);
        $service = app(LicenseService::class);
        $projectA = Project::factory()->create(['client_id' => $this->clientA->id]);

        $one = Computer::factory()->create(['project_id' => $projectA->id]);
        $two = Computer::factory()->create(['project_id' => $projectA->id]);
        $three = Computer::factory()->create(['project_id' => $projectA->id]);

        $service->assign($license, $one, null);
        $service->assign($license, $one, null); // idempotent — still 1 seat
        $service->assign($license, $two, null);

        try {
            $service->assign($license, $three, null);
            $this->fail('the third machine must be refused on a 2-seat license');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('No free seats', $e->getMessage());
        }
        $this->assertSame(2, $license->seatsUsed());

        // Role-blind isolation: another client's machine is refused outright.
        $foreign = Computer::factory()->create([
            'project_id' => Project::factory()->create(['client_id' => $this->clientB->id])->id,
        ]);
        try {
            $service->assign($license, $foreign, null);
            $this->fail('cross-client assignment must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Alpha MSP', $e->getMessage());
        }
    }

    public function test_tenants_see_only_their_own_licenses(): void
    {
        $this->license();

        Livewire::actingAs($this->ownerOf($this->clientB))
            ->test(LicensesIndex::class)
            ->assertOk()
            ->assertDontSee('Office 2021 Pro');

        Livewire::actingAs($this->ownerOf($this->clientA))
            ->test(LicensesIndex::class)
            ->assertOk()
            ->assertSee('Office 2021 Pro');
    }

    public function test_a_tenant_creates_a_license_bound_to_their_own_client(): void
    {
        Livewire::actingAs($this->ownerOf($this->clientB))
            ->test(LicensesIndex::class)
            ->set('name', 'Adobe CC Teams')
            ->set('licenseKey', 'ZZZZ-1111')
            ->set('seats', 5)
            ->call('save');

        $license = SoftwareLicense::where('name', 'Adobe CC Teams')->first();
        $this->assertNotNull($license);
        $this->assertSame($this->clientB->id, $license->client_id, 'tenancy is not a form field a tenant controls');
    }

    public function test_expiry_flags_read_correctly(): void
    {
        $expired = $this->license(['expires_at' => now()->subDay()->toDateString(), 'name' => 'Old']);
        $soon = $this->license(['expires_at' => now()->addDays(10)->toDateString(), 'name' => 'Soon']);
        $far = $this->license(['expires_at' => now()->addYear()->toDateString(), 'name' => 'Far']);

        $this->assertTrue($expired->isExpired());
        $this->assertTrue($soon->expiresSoon());
        $this->assertFalse($soon->isExpired());
        $this->assertFalse($far->expiresSoon());
    }
}
