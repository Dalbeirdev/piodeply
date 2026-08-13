<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\Role as RoleEnum;
use App\Livewire\Team\Branding;
use App\Models\Client;
use App\Models\User;
use App\Services\ProjectService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Per-client branding: the owner's portal name, and the tray identity
 * delivered to their machines at heartbeat.
 */
class ClientBrandingTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->client = Client::factory()->create();
        $this->owner = User::factory()->create();
        $this->owner->forceFill(['client_id' => $this->client->id])->save();
        $this->owner->assignRole(RoleEnum::ClientOwner->value);
    }

    public function test_an_owner_saves_branding(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->set('portal_name', 'Acme IT Portal')
            ->set('tray_name', 'Acme IT Support')
            ->set('show_tray_icon', false)
            ->call('save')
            ->assertHasNoErrors();

        $client = $this->client->fresh();
        $this->assertSame('Acme IT Portal', $client->portal_name);
        $this->assertSame('Acme IT Support', $client->tray_name);
        $this->assertFalse((bool) $client->show_tray_icon);
    }

    public function test_only_owners_reach_the_branding_page(): void
    {
        $tech = User::factory()->create();
        $tech->forceFill(['client_id' => $this->client->id])->save();
        $tech->assignRole(RoleEnum::Technician->value);

        $this->actingAs($tech)->get(route('branding.index'))->assertForbidden();
    }

    public function test_the_heartbeat_carries_the_tray_branding(): void
    {
        $this->client->update(['tray_name' => 'Acme IT Support', 'show_tray_icon' => false]);
        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: $this->client->id,
            name: 'Branding Fleet',
        ));
        $headers = ['X-Api-Key' => $result['plain_api_key'], 'Accept' => 'application/json'];

        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', [
            'agent_uuid' => $uuid,
            'agent_version' => '1.4.25',
            'inventory' => ['hostname' => 'BRAND-PC-01', 'os_name' => 'Windows 11'],
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid], $headers)
            ->assertOk()
            ->assertJson([
                'tray_enabled' => false,
                'tray_name'    => 'Acme IT Support',
            ]);
    }
}
