<?php

namespace Tests\Feature;

use App\Livewire\Billing\Portal;
use App\Models\Account;
use App\Models\User;
use App\Services\BillingPortalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_portal_service_returns_empty_results_when_stripe_is_not_ready(): void
    {
        // Keys set, but the account has no Stripe customer id yet.
        config(['cashier.key' => 'pk_test_x', 'cashier.secret' => 'sk_test_x']);
        $account = Account::factory()->create(['stripe_id' => null]);
        $portal = app(BillingPortalService::class);

        $this->assertFalse($portal->stripeReady($account));
        $this->assertTrue($portal->invoices($account)->isEmpty());
        $this->assertTrue($portal->paymentMethods($account)->isEmpty());
        $this->assertNull($portal->upcomingInvoice($account));
        $this->assertNull($portal->defaultPaymentMethod($account));
    }

    public function test_portal_page_is_gated_and_shows_a_notice_when_unconfigured(): void
    {
        // Viewer cannot reach it.
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/billing/invoices')->assertForbidden();

        // Admin can; Stripe unconfigured in tests -> configuration notice.
        Livewire::actingAs($this->admin())
            ->test(Portal::class)
            ->assertOk()
            ->assertSee('configured yet');
    }

    public function test_invoice_download_is_gated(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/billing/invoices/in_123/download')->assertForbidden();
    }

    public function test_invoice_download_404s_when_there_is_no_such_invoice(): void
    {
        // Admin passes the gate; with no Stripe customer the invoice can't be
        // found, so it is a 404 — never a different customer's document.
        $this->actingAs($this->admin())
            ->get('/billing/invoices/in_does_not_exist/download')
            ->assertNotFound();
    }
}
