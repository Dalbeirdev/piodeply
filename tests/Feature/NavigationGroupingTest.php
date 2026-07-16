<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Services\NavigationService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationGroupingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function userWithRole(RoleEnum $role): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole($role->value));
    }

    private function nav(): NavigationService
    {
        return app(NavigationService::class);
    }

    /** @return array<string, list<string>> */
    private function labelsByGroup(User $user): array
    {
        return collect($this->nav()->groups($user))
            ->mapWithKeys(fn (array $g) => [$g['label'] ?? '' => array_column($g['items'], 'label')])
            ->all();
    }

    public function test_an_admin_sees_every_section_in_order(): void
    {
        $groups = $this->nav()->groups($this->userWithRole(RoleEnum::Admin));

        $this->assertSame(
            [null, NavigationService::FLEET, NavigationService::SOFTWARE, NavigationService::INSIGHTS, NavigationService::ADMIN],
            array_column($groups, 'label')
        );
    }

    public function test_items_land_in_the_section_they_belong_to(): void
    {
        $byGroup = $this->labelsByGroup($this->userWithRole(RoleEnum::Admin));

        $this->assertSame(['Dashboard'], $byGroup['']);
        $this->assertSame(['Clients', 'Projects', 'Computers'], $byGroup[NavigationService::FLEET]);
        $this->assertSame(
            ['Packages', 'Deployments', 'Policies', 'Browser Policies'],
            $byGroup[NavigationService::SOFTWARE]
        );
        $this->assertSame(['Reports', 'Activity'], $byGroup[NavigationService::INSIGHTS]);
    }

    /** An empty section heading would be worse than no grouping at all. */
    public function test_a_section_the_user_cannot_see_disappears_entirely(): void
    {
        $labels = array_column($this->nav()->groups($this->userWithRole(RoleEnum::Viewer)), 'label');

        // A viewer manages nothing, so Administration should not be a heading
        // hanging over an empty list.
        $this->assertNotContains(NavigationService::ADMIN, $labels);
    }

    public function test_grouping_does_not_smuggle_past_permissions(): void
    {
        $viewer = $this->userWithRole(RoleEnum::Viewer);

        $grouped = collect($this->nav()->groups($viewer))->flatMap(fn (array $g) => array_column($g['items'], 'label'))->all();
        $flat = array_column($this->nav()->items($viewer), 'label');

        // Same set either way — groups() is a view of items(), not a bypass.
        $this->assertSame($flat, $grouped);
        $this->assertNotContains('Roles', $grouped);
    }

    public function test_the_sidebar_renders_the_section_headings(): void
    {
        $this->actingAs($this->userWithRole(RoleEnum::Admin))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Fleet')
            ->assertSee('Software')
            ->assertSee('Administration');
    }
}
