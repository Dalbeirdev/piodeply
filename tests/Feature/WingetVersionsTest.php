<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Deployments\DeployToComputer;
use App\Models\Computer;
use App\Models\Package;
use App\Models\User;
use App\Services\WingetVersionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pinning a version winget no longer publishes produces a job that fails on
 * every machine it reaches, discovered one deployment at a time. The version
 * list belongs to the winget repository, not to any machine, so it is read
 * once here rather than asked of every agent.
 */
class WingetVersionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::flush();
    }

    private function service(): WingetVersionService
    {
        return app(WingetVersionService::class);
    }

    private function chrome(): Package
    {
        return Package::factory()->create([
            'name' => 'Google Chrome', 'installer_type' => 'winget', 'winget_id' => 'Google.Chrome',
        ]);
    }

    /** @param list<string> $versions */
    private function fakeRepo(array $versions, int $status = 200): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(
                array_map(fn (string $v) => ['name' => $v, 'type' => 'dir'], $versions),
                $status
            ),
        ]);
    }

    public function test_it_reads_the_published_versions_newest_first(): void
    {
        $this->fakeRepo(['141.0.7390.55', '150.0.7871.129', '138.0.7615.129']);

        $this->assertSame(
            ['150.0.7871.129', '141.0.7390.55', '138.0.7615.129'],
            $this->service()->versionsFor($this->chrome())
        );
    }

    public function test_the_manifest_path_is_built_from_the_package_id(): void
    {
        $this->fakeRepo(['1.0']);

        $this->service()->versionsFor(Package::factory()->create([
            'installer_type' => 'winget', 'winget_id' => 'Microsoft.DotNet.SDK.8',
        ]));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'manifests/m/Microsoft/DotNet/SDK/8'));
    }

    public function test_files_beside_the_version_folders_are_ignored(): void
    {
        Http::fake(['api.github.com/*' => Http::response([
            ['name' => '150.0.7871.129', 'type' => 'dir'],
            ['name' => '.validation', 'type' => 'file'],
            ['name' => 'README.md', 'type' => 'file'],
        ])]);

        $this->assertSame(['150.0.7871.129'], $this->service()->versionsFor($this->chrome()));
    }

    /* ─────────── not knowing is not the same as none ─────────── */

    public function test_a_package_missing_from_the_repo_is_unknown_not_empty(): void
    {
        $this->fakeRepo([], 404);

        $this->assertNull($this->service()->versionsFor($this->chrome()));
    }

    public function test_a_rate_limited_or_broken_source_is_unknown_not_empty(): void
    {
        $this->fakeRepo([], 403);

        $this->assertNull($this->service()->versionsFor($this->chrome()));
    }

    public function test_an_unreachable_source_does_not_take_the_page_down(): void
    {
        Http::fake(fn () => throw new \RuntimeException('network is down'));

        $this->assertNull($this->service()->versionsFor($this->chrome()));
    }

    public function test_a_non_winget_package_has_no_list_to_read(): void
    {
        $msi = Package::factory()->create(['installer_type' => 'msi', 'winget_id' => null]);

        $this->assertNull($this->service()->versionsFor($msi));
        Http::assertNothingSent();
    }

    /* ─────────── caching ─────────── */

    public function test_the_source_is_asked_once_per_package(): void
    {
        $this->fakeRepo(['150.0']);
        $chrome = $this->chrome();

        $this->service()->versionsFor($chrome);
        $this->service()->versionsFor($chrome);
        $this->service()->versionsFor($chrome);

        Http::assertSentCount(1);
    }

    /** A miss must not be retried on every keystroke either. */
    public function test_an_unknown_package_is_not_asked_about_repeatedly(): void
    {
        $this->fakeRepo([], 404);
        $chrome = $this->chrome();

        $this->service()->versionsFor($chrome);
        $this->service()->versionsFor($chrome);

        Http::assertSentCount(1);
    }

    /* ─────────── the form ─────────── */

    public function test_the_form_offers_the_published_versions(): void
    {
        $this->fakeRepo(['150.0.7871.129', '141.0.7390.55']);
        $package = $this->chrome();

        Livewire::actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value)))
            ->test(DeployToComputer::class, ['computer' => Computer::factory()->create()])
            ->set('package_id', $package->id)
            ->assertViewHas('offeredVersions', ['150.0.7871.129', '141.0.7390.55'])
            ->assertSee('141.0.7390.55');
    }

    /** Chrome's real situation: nothing older is published, so no rollback. */
    public function test_a_package_with_one_published_version_says_it_cannot_be_rolled_back(): void
    {
        $this->fakeRepo(['150.0.7871.129']);
        $package = $this->chrome();

        Livewire::actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value)))
            ->test(DeployToComputer::class, ['computer' => Computer::factory()->create()])
            ->set('package_id', $package->id)
            ->assertSee('cannot be rolled back');
    }

    public function test_an_unknown_list_falls_back_to_free_text_rather_than_an_empty_dropdown(): void
    {
        $this->fakeRepo([], 404);
        $package = $this->chrome();

        Livewire::actingAs(tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value)))
            ->test(DeployToComputer::class, ['computer' => Computer::factory()->create()])
            ->set('package_id', $package->id)
            ->assertViewHas('offeredVersions', null)
            ->assertDontSee('cannot be rolled back');
    }
}
