<?php

namespace Tests\Feature;

use App\DTOs\ProjectData;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Computer;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private \App\Models\Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Client::factory()->create();
        $result = app(ProjectService::class)->create(new ProjectData(
            clientId: $client->id,
            name: 'Agent Test Fleet',
        ));
        $this->project = $result['project'];
        $this->apiKey = $result['plain_api_key'];
    }

    private function agentHeaders(?string $key = null): array
    {
        return ['X-Api-Key' => $key ?? $this->apiKey, 'Accept' => 'application/json'];
    }

    private function samplePayload(?string $uuid = null): array
    {
        return [
            'agent_uuid'    => $uuid ?? (string) Str::uuid(),
            'agent_version' => '1.0.0',
            'inventory'     => [
                'hostname'         => 'AGENT-PC-01',
                'serial_number'    => 'SN123456',
                'manufacturer'     => 'Dell Inc.',
                'model'            => 'OptiPlex 7010',
                'os_name'          => 'Microsoft Windows 11 Pro',
                'os_version'       => '10.0.26100',
                'windows_build'    => '26100.2033',
                'cpu'              => 'Intel Core i5-13500',
                'ram_bytes'        => 17179869184,
                'disk_total_bytes' => 549755813888,
                'disk_free_bytes'  => 219902325555,
                'private_ip'       => '192.168.1.50',
                'mac_address'      => 'AA:BB:CC:DD:EE:FF',
                'secure_boot'      => true,
                'tpm_enabled'      => true,
                'tpm_version'      => '2.0',
            ],
        ];
    }

    public function test_register_requires_valid_api_key(): void
    {
        $this->postJson('/api/v1/agent/register', $this->samplePayload())
            ->assertUnauthorized();

        $this->postJson('/api/v1/agent/register', $this->samplePayload(), $this->agentHeaders('pio_' . str_repeat('x', 40)))
            ->assertUnauthorized();
    }

    public function test_archived_project_key_is_rejected(): void
    {
        $this->project->update(['status' => ProjectStatus::Archived]);

        $this->postJson('/api/v1/agent/register', $this->samplePayload(), $this->agentHeaders())
            ->assertForbidden();
    }

    public function test_register_creates_computer_with_wire_public_ip(): void
    {
        $response = $this->postJson('/api/v1/agent/register', $this->samplePayload(), $this->agentHeaders());

        $response->assertCreated()
            ->assertJsonPath('hostname', 'AGENT-PC-01')
            ->assertJsonPath('project', 'Agent Test Fleet')
            ->assertJsonPath('heartbeat_seconds', 60)
            ->assertJsonStructure(['computer_id', 'server_time']);

        $computer = Computer::first();
        $this->assertSame($this->project->id, $computer->project_id);
        $this->assertSame('AGENT-PC-01', $computer->hostname);
        $this->assertTrue($computer->secure_boot);
        $this->assertNotNull($computer->public_ip, 'public IP is captured from the wire');
        $this->assertTrue($computer->isOnline());
    }

    public function test_register_is_idempotent_for_same_uuid(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();
        $payload = $this->samplePayload($uuid);
        $payload['inventory']['hostname'] = 'AGENT-PC-01-RENAMED';
        $this->postJson('/api/v1/agent/register', $payload, $this->agentHeaders())->assertCreated();

        $this->assertSame(1, Computer::count());
        $this->assertSame('AGENT-PC-01-RENAMED', Computer::first()->hostname);
    }

    public function test_register_validates_payload(): void
    {
        $this->postJson('/api/v1/agent/register', ['agent_uuid' => 'not-a-uuid'], $this->agentHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['agent_uuid', 'inventory']);
    }

    public function test_heartbeat_updates_last_seen(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();
        Computer::query()->update(['last_seen_at' => now()->subHours(2)]);

        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid, 'agent_version' => '1.0.1'], $this->agentHeaders())
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('pending_jobs', 0);

        $computer = Computer::first();
        $this->assertTrue($computer->isOnline());
        $this->assertSame('1.0.1', $computer->agent_version);
    }

    public function test_heartbeat_for_unknown_agent_requires_reregistration(): void
    {
        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => (string) Str::uuid()], $this->agentHeaders())
            ->assertNotFound();
    }

    public function test_heartbeat_rejects_agent_registered_to_a_different_project(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();

        $otherResult = app(ProjectService::class)->create(new ProjectData(
            clientId: Client::factory()->create()->id,
            name: 'Other Fleet',
        ));

        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid], $this->agentHeaders($otherResult['plain_api_key']))
            ->assertNotFound();
    }

    public function test_inventory_updates_fields(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();

        $payload = $this->samplePayload($uuid);
        $payload['inventory']['disk_free_bytes'] = 100;
        $payload['inventory']['agent_uuid'] = 'spoof-attempt';
        unset($payload['agent_version']);

        $this->postJson('/api/v1/agent/inventory', $payload, $this->agentHeaders())->assertOk();

        $computer = Computer::first();
        $this->assertSame(100, $computer->disk_free_bytes);
        $this->assertSame($uuid, $computer->agent_uuid);
    }

    public function test_software_inventory_is_stored_with_replace_semantics(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();

        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $uuid,
            'software'   => [
                ['name' => 'Old App', 'version' => '1.0', 'publisher' => 'Acme', 'source' => 'registry'],
                ['name' => 'Git.Git', 'version' => '2.46.0', 'source' => 'winget'],
            ],
        ], $this->agentHeaders())->assertOk()->assertJsonPath('stored', 2);

        // Second report replaces, never accumulates.
        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $uuid,
            'software'   => [
                ['name' => 'New App', 'version' => '2.0', 'source' => 'msi'],
            ],
        ], $this->agentHeaders())->assertOk()->assertJsonPath('stored', 1);

        $computer = Computer::first();
        $this->assertSame(1, $computer->software()->count());
        $this->assertSame('New App', $computer->software()->first()->name);
    }

    public function test_software_inventory_validates_source_and_caps_size(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();

        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $uuid,
            'software'   => [['name' => 'X', 'source' => 'carrier-pigeon']],
        ], $this->agentHeaders())->assertUnprocessable();

        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => $uuid,
            'software'   => array_fill(0, 3001, ['name' => 'X', 'source' => 'registry']),
        ], $this->agentHeaders())->assertUnprocessable();
    }

    public function test_software_endpoint_requires_known_agent(): void
    {
        $this->postJson('/api/v1/agent/software', [
            'agent_uuid' => (string) Str::uuid(),
            'software'   => [['name' => 'X', 'source' => 'registry']],
        ], $this->agentHeaders())->assertNotFound();
    }

    public function test_rotated_key_locks_out_old_key(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/v1/agent/register', $this->samplePayload($uuid), $this->agentHeaders())->assertCreated();

        $newKey = app(ProjectService::class)->rotateApiKey($this->project);

        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid], $this->agentHeaders())
            ->assertUnauthorized();
        $this->postJson('/api/v1/agent/heartbeat', ['agent_uuid' => $uuid], $this->agentHeaders($newKey))
            ->assertOk();
    }
}
