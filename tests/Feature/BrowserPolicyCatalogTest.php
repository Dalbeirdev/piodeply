<?php

namespace Tests\Feature;

use App\Enums\Browser;
use App\Enums\BrowserPolicyType;
use App\Livewire\BrowserPolicies\BrowserPolicyForm;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The expanded browser-policy catalogue: every policy must be categorised,
 * described, and produce a valid operation for every browser under both
 * actions (no case may throw or return an unknown kind).
 */
class BrowserPolicyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_policy_is_categorised_and_described(): void
    {
        foreach (BrowserPolicyType::cases() as $type) {
            $this->assertContains($type->category(), BrowserPolicyType::CATEGORY_ORDER, "{$type->value} has an unlisted category");
            $this->assertNotEmpty($type->label());
            $this->assertNotEmpty($type->description());
        }
    }

    public function test_by_category_groups_and_orders_without_empty_buckets(): void
    {
        $grouped = BrowserPolicyType::byCategory();

        // Ordered by CATEGORY_ORDER and never an empty category.
        $this->assertSame(
            array_values(array_intersect(BrowserPolicyType::CATEGORY_ORDER, array_keys($grouped))),
            array_keys($grouped),
        );
        foreach ($grouped as $types) {
            $this->assertNotEmpty($types);
        }

        // Every case is placed exactly once.
        $this->assertCount(count(BrowserPolicyType::cases()), collect($grouped)->flatten());
    }

    public function test_every_operation_is_valid_for_every_browser_and_action(): void
    {
        $validKinds = ['registry', 'firefox_json', 'unsupported'];

        foreach (BrowserPolicyType::cases() as $type) {
            foreach (Browser::cases() as $browser) {
                foreach (['disable', 'enable'] as $action) {
                    $op = $type->operationFor($browser, $action);
                    $this->assertContains($op['kind'], $validKinds, "{$type->value}/{$browser->value} bad kind");

                    if ($op['kind'] === 'registry') {
                        $this->assertArrayHasKey('path', $op);
                        $this->assertArrayHasKey('name', $op);
                        $this->assertIsInt($op['value']);
                    }
                }
            }
        }
    }

    public function test_supported_browsers_reflects_the_operation_map(): void
    {
        // QUIC is a Chromium-only DWORD; Firefox and Opera can't do it.
        $quic = BrowserPolicyType::DisableQuic->supportedBrowsers();
        $this->assertEqualsCanonicalizing(
            [Browser::Chrome, Browser::Edge, Browser::Brave],
            $quic,
        );

        // Password saving reaches Firefox too.
        $this->assertContains(Browser::Firefox, BrowserPolicyType::DisablePasswordSaving->supportedBrowsers());
    }

    public function test_new_policies_map_to_the_expected_registry_values(): void
    {
        $this->assertSame(
            ['kind' => 'registry', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome', 'name' => 'VideoCaptureAllowed', 'value' => 0],
            BrowserPolicyType::DisableCamera->operationFor(Browser::Chrome, 'disable'),
        );
        $this->assertSame(
            ['kind' => 'registry', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Edge', 'name' => 'DefaultWebUsbGuardSetting', 'value' => 2],
            BrowserPolicyType::DisableWebUsb->operationFor(Browser::Edge, 'disable'),
        );
    }

    public function test_batch3_policies_map_to_the_expected_registry_values(): void
    {
        // Downloads: 3 blocks everything, 0 restores.
        $this->assertSame(
            ['kind' => 'registry', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome', 'name' => 'DownloadRestrictions', 'value' => 3],
            BrowserPolicyType::DisableDownloads->operationFor(Browser::Chrome, 'disable'),
        );
        $this->assertSame(0, BrowserPolicyType::DisableDownloads->operationFor(Browser::Brave, 'enable')['value']);

        // AI assistants: a different vendor policy per browser.
        $this->assertSame('GeminiSettings', BrowserPolicyType::DisableAiAssistants->operationFor(Browser::Chrome, 'disable')['name']);
        $this->assertSame(
            ['kind' => 'registry', 'path' => 'SOFTWARE\\Policies\\Microsoft\\Edge', 'name' => 'HubsSidebarEnabled', 'value' => 0],
            BrowserPolicyType::DisableAiAssistants->operationFor(Browser::Edge, 'disable'),
        );
        $this->assertSame('BraveAIChatEnabled', BrowserPolicyType::DisableAiAssistants->operationFor(Browser::Brave, 'disable')['name']);

        // Edge-exclusive features are unsupported everywhere else.
        $this->assertSame([Browser::Edge], BrowserPolicyType::DisableMicrosoftRewards->supportedBrowsers());
        $this->assertSame([Browser::Edge], BrowserPolicyType::DisableBrowserGames->supportedBrowsers());
        $this->assertSame('unsupported', BrowserPolicyType::DisableNewTabFeed->operationFor(Browser::Chrome, 'disable')['kind']);

        // Session-only cookies use DefaultCookiesSetting 4.
        $this->assertSame(4, BrowserPolicyType::ClearCookiesOnExit->operationFor(Browser::Edge, 'disable')['value']);
    }

    public function test_the_form_lists_policies_grouped_by_category(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));

        Livewire::actingAs($admin)
            ->test(BrowserPolicyForm::class)
            ->assertSee('Password Management')
            ->assertSee('Camera & Microphone')
            ->set('type', 'disable_web_usb')
            ->assertSee('Blocks websites from connecting to USB devices');
    }
}
