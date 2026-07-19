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
        $validKinds = ['registry', 'registry_sz', 'registry_list', 'firefox_json', 'unsupported'];

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

                    if ($op['kind'] === 'registry_sz') {
                        $this->assertArrayHasKey('path', $op);
                        $this->assertArrayHasKey('name', $op);
                        $this->assertIsString($op['value']);
                    }

                    if ($op['kind'] === 'registry_list') {
                        $this->assertArrayHasKey('path', $op);
                        $this->assertIsArray($op['values']);
                    }
                }
            }
        }
    }

    public function test_value_typed_policies_embed_their_settings(): void
    {
        // Forced homepage: the URL travels as a REG_SZ.
        $op = BrowserPolicyType::ForceHomepage->operationFor(Browser::Chrome, 'disable', ['url' => 'https://intranet.acme.com']);
        $this->assertSame(
            ['kind' => 'registry_sz', 'path' => 'SOFTWARE\\Policies\\Google\\Chrome', 'name' => 'HomepageLocation', 'value' => 'https://intranet.acme.com'],
            $op,
        );

        // Forcelist: id becomes "<id>;<web store update url>", per browser root.
        $op = BrowserPolicyType::ForceInstallExtensions->operationFor(Browser::Edge, 'disable', ['ids' => ['cjpalhdlnbpafiamejdnhcphjbkeiagm']]);
        $this->assertSame('registry_list', $op['kind']);
        $this->assertSame('SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist', $op['path']);
        $this->assertSame(['cjpalhdlnbpafiamejdnhcphjbkeiagm;https://clients2.google.com/service/update2/crx'], $op['values']);

        // Block-all installs needs no settings at all.
        $op = BrowserPolicyType::BlockExtensionInstalls->operationFor(Browser::Brave, 'disable');
        $this->assertSame(['*'], $op['values']);

        // New-tab URL is honestly unsupported on Brave.
        $this->assertSame('unsupported', BrowserPolicyType::ForceNewTabUrl->operationFor(Browser::Brave, 'disable')['kind']);
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

    public function test_the_form_saves_a_value_typed_policy_and_the_agent_document_carries_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
        $project = \App\Models\Project::factory()->create();
        $computer = \App\Models\Computer::factory()->create(['project_id' => $project->id]);

        Livewire::actingAs($admin)
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Intranet homepage')
            ->set('scope_id', $project->id)
            ->set('type', 'force_homepage')
            ->set('value_url', 'https://intranet.acme.com')
            ->call('save')
            ->assertHasNoErrors();

        $policy = \App\Models\BrowserPolicy::firstOrFail();
        $this->assertSame(['url' => 'https://intranet.acme.com'], $policy->settings);

        $document = app(\App\Services\BrowserPolicyService::class)->documentFor($computer);
        $chromeOp = $document['policies'][0]['operations']['chrome'];
        $this->assertSame('registry_sz', $chromeOp['kind']);
        $this->assertSame('https://intranet.acme.com', $chromeOp['value']);
    }

    public function test_the_form_rejects_a_malformed_extension_id(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
        $project = \App\Models\Project::factory()->create();

        Livewire::actingAs($admin)
            ->test(BrowserPolicyForm::class)
            ->set('name', 'Force uBlock')
            ->set('scope_id', $project->id)
            ->set('type', 'force_install_extensions')
            ->set('value_ids', "cjpalhdlnbpafiamejdnhcphjbkeiagm\nnot-a-real-id")
            ->call('save')
            ->assertHasErrors('value_ids');

        $this->assertDatabaseCount('browser_policies', 0);
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
