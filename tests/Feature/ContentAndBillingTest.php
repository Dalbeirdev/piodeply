<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\BillingSettings;
use App\Livewire\Admin\ManageContent;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\SettingsService;
use App\Services\SiteContentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ContentAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Admin->value));
    }

    // ── Content editor ─────────────────────────────────────────────────

    public function test_edited_content_appears_on_the_public_site(): void
    {
        Livewire::actingAs($this->admin())
            ->test(ManageContent::class)
            ->set('values.' . ManageContent::alias('home.hero_title'), 'Own your Windows fleet')
            ->call('save')
            ->assertHasNoErrors();

        $this->get('/')->assertOk()->assertSee('Own your Windows fleet');
    }

    public function test_blank_content_falls_back_to_the_default(): void
    {
        $service = app(SiteContentService::class);
        $service->set('home.hero_title', '');

        $this->assertSame(
            SiteContentService::defaults()['home.hero_title'],
            $service->get('home.hero_title')
        );
    }

    public function test_reset_restores_defaults(): void
    {
        $service = app(SiteContentService::class);
        $service->set('home.hero_title', 'Temporary');

        Livewire::actingAs($this->admin())
            ->test(ManageContent::class)
            ->call('resetToDefaults');

        $this->assertSame(SiteContentService::defaults()['home.hero_title'], $service->get('home.hero_title'));
    }

    public function test_content_editor_requires_settings_permission(): void
    {
        $manager = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Manager->value));
        $this->actingAs($manager)->get('/admin/content')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/content')->assertOk();
    }

    // ── Billing config ─────────────────────────────────────────────────

    public function test_graduated_quote_is_cheaper_than_the_market_schedule(): void
    {
        $billing = app(BillingService::class);

        // 50 machines: 20×$0.80 + 30×$0.40 = $28.00
        $this->assertSame(2800, $billing->quoteCents(50));
        // 700 machines: 20×0.80 + 480×0.40 + 200×0.20 = $248.00 (vs $310 at 1/.5/.25)
        $this->assertSame(24800, $billing->quoteCents(700));
        // Never charges below one machine.
        $this->assertSame(80, $billing->quoteCents(1));
    }

    public function test_legacy_checkout_route_is_disabled_until_configured(): void
    {
        // The legacy graduated-checkout route still 404s until Stripe is keyed.
        $this->post('/billing/checkout', ['machines' => 100])->assertNotFound();

        // The pricing page routes every buy path into the signup wizard: it
        // never surfaces the old direct "Subscribe →" graduated button.
        $this->get('/pricing')->assertOk()
            ->assertSee('/signup?machines=')
            ->assertDontSee('Subscribe →');
    }

    public function test_configured_checkout_creates_a_stripe_session_and_redirects(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        app(SettingsService::class)->set('billing.enabled', '1');

        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123']),
        ]);

        $this->post('/billing/checkout', ['machines' => 700])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'checkout/sessions')
                && $request['mode'] === 'subscription'
                && $request['line_items'][0]['quantity'] === 1
                && $request['line_items'][0]['price_data']['unit_amount'] === 24800; // graduated total
        });
    }

    public function test_pricing_page_routes_buyers_to_the_wizard_even_with_legacy_billing_enabled(): void
    {
        // Enabling the legacy graduated checkout must not resurrect the old
        // "Subscribe →" button — every buy path on the page goes through the
        // signup wizard, which handles payment (or manual verification) itself.
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        app(SettingsService::class)->set('billing.enabled', '1');

        $this->get('/pricing')->assertOk()
            ->assertSee('/signup?machines=')
            ->assertDontSee('Subscribe →');
    }

    // ── Webhook signature ──────────────────────────────────────────────

    public function test_webhook_rejects_a_bad_signature(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);

        $this->postJson('/billing/webhook', ['type' => 'checkout.session.completed'], [
            'Stripe-Signature' => 't=' . time() . ',v1=deadbeef',
        ])->assertStatus(400);

        $this->assertSame(0, Payment::count());
    }

    public function test_webhook_records_a_paid_session_with_a_valid_signature(): void
    {
        $secret = 'whsec_test';
        config(['services.stripe.webhook_secret' => $secret]);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_999',
                'payment_status' => 'paid',
                'amount_total' => 3750,
                'currency' => 'usd',
                'customer_details' => ['email' => 'buyer@example.test'],
                'metadata' => ['machines' => 700],
                'mode' => 'subscription',
            ]],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        $this->call('POST', '/billing/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertDatabaseHas('payments', [
            'reference' => 'cs_test_999', 'status' => 'paid',
            'customer_email' => 'buyer@example.test', 'quantity' => 700,
        ]);
    }

    public function test_billing_page_requires_settings_permission(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));
        $this->actingAs($viewer)->get('/admin/billing')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/billing')->assertOk();
    }
}
