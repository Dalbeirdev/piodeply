<?php

namespace Tests\Feature;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use App\Models\BrowserPolicy;
use App\Models\Project;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Windows-security policies ride the browser-policy pipeline: same
 * scoping, same manifest rollback, same compliance reporting — the OS is
 * just one more registry surface. These pin the seam between the two
 * families so neither can ever leak into the other.
 */
class WindowsPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_a_windows_type_targets_exactly_the_os_surface(): void
    {
        $policy = BrowserPolicy::create([
            'project_id' => Project::factory()->create()->id,
            'name'       => 'Block USB storage',
            'type'       => BrowserPolicyType::DisableUsbStorage,
            'browsers'   => ['all'], // ignored on purpose — the OS is the surface
            'action'     => 'disable',
            'status'     => 'active',
        ]);

        $operations = $policy->operations();

        $this->assertSame(['windows'], array_keys($operations), 'one surface, regardless of the browsers field');
        $this->assertSame('registry', $operations['windows']['kind']);
        $this->assertSame('SYSTEM\CurrentControlSet\Services\USBSTOR', $operations['windows']['path']);
        $this->assertSame('Start', $operations['windows']['name']);
        $this->assertSame(4, $operations['windows']['value']);
    }

    public function test_enabling_writes_the_permissive_value(): void
    {
        $op = BrowserPolicyType::DisableRemoteDesktop->operationFor(Browser::Windows, 'enable');

        $this->assertSame(0, $op['value'], 'fDenyTSConnections=0 re-allows RDP');
    }

    public function test_browser_types_never_touch_the_os_surface(): void
    {
        // A browser policy under "all browsers" must not include the OS...
        $policy = BrowserPolicy::create([
            'project_id' => Project::factory()->create()->id,
            'name'       => 'No incognito',
            'type'       => BrowserPolicyType::DisableIncognito,
            'browsers'   => ['all'],
            'action'     => 'disable',
            'status'     => 'active',
        ]);
        $this->assertArrayNotHasKey('windows', $policy->operations());

        // ...and even asked directly, it answers "unsupported".
        $this->assertSame('unsupported', BrowserPolicyType::DisableIncognito->operationFor(Browser::Windows, 'disable')['kind']);

        // The reverse leak is equally impossible.
        $this->assertSame('unsupported', BrowserPolicyType::DisableUsbStorage->operationFor(Browser::Chrome, 'disable')['kind']);
    }

    public function test_every_windows_type_produces_a_concrete_registry_operation(): void
    {
        foreach (BrowserPolicyType::cases() as $type) {
            if (! $type->isWindowsPolicy()) {
                continue;
            }

            $op = $type->operationFor(Browser::Windows, 'disable');
            $this->assertSame('registry', $op['kind'], "{$type->value} must map to a registry write");
            $this->assertNotEmpty($op['path']);
            $this->assertNotEmpty($op['name']);
            $this->assertIsInt($op['value']);
            $this->assertSame([Browser::Windows], $type->supportedBrowsers());
        }
    }

    public function test_windows_categories_appear_in_the_grouped_picker(): void
    {
        $grouped = BrowserPolicyType::byCategory();

        $this->assertArrayHasKey('Windows Security', $grouped);
        $this->assertArrayHasKey('Windows Updates', $grouped);
        $this->assertCount(7, $grouped['Windows Security']);
    }
}
