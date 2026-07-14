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

    public function test_checkout_is_disabled_until_configured(): void
    {
        // No keys / not enabled → route 404s and pricing shows the lead CTA.
        $this->post('/billing/checkout', ['plan' => 'growth', 'endpoints' => 10])->assertNotFound();
        $this->get('/pricing')->assertOk()->assertSee('Get started')->assertDontSee('Subscribe');
    }

    public function test_configured_checkout_creates_a_stripe_session_and_redirects(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        app(SettingsService::class)->set('billing.enabled', '1');

        Http::fake([
            'api.stripe.com/*' => Http::response(['id' => 'cs_test_123', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_123']),
        ]);

        $this->post('/billing/checkout', ['plan' => 'growth', 'endpoints' => 25])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_123');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'checkout/sessions')
                && $request['mode'] === 'subscription'
                && $request['line_items'][0]['quantity'] === 25
                && $request['line_items'][0]['price_data']['unit_amount'] === 150;
        });
    }

    public function test_pricing_page_shows_subscribe_when_configured(): void
    {
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        app(SettingsService::class)->set('billing.enabled', '1');

        $this->get('/pricing')->assertOk()->assertSee('Subscribe');
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
                'metadata' => ['plan' => 'growth', 'quantity' => 25],
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
            'customer_email' => 'buyer@example.test', 'quantity' => 25,
        ]);
    }

    public function test_billing_page_requires_settings_permission(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));
        $this->actingAs($viewer)->get('/admin/billing')->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/billing')->assertOk();
    }
}
