<?php

namespace Tests\Feature;

use App\Livewire\Admin\BillingSettings;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StripeSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillingSettingsTest extends TestCase
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

    public function test_secrets_are_encrypted_at_rest_and_apply_to_config(): void
    {
        $stripe = app(StripeSettingsService::class);
        $stripe->save('pk_test_abc', 'usd', 'sk_test_xyz', 'whsec_123');

        // Round-trips decrypted through the service.
        $this->assertSame('pk_test_abc', $stripe->publishableKey());
        $this->assertSame('sk_test_xyz', $stripe->secret());
        $this->assertSame('whsec_123', $stripe->webhookSecret());
        $this->assertTrue($stripe->configured());

        // Stored ciphertext must not contain the plaintext secret.
        $stored = (string) app(SettingsService::class)->get('billing.stripe_sk');
        $this->assertStringNotContainsString('sk_test_xyz', $stored);

        // apply() pushes the keys into the Stripe/Cashier config.
        $stripe->apply();
        $this->assertSame('pk_test_abc', config('cashier.key'));
        $this->assertSame('sk_test_xyz', config('cashier.secret'));
        $this->assertSame('whsec_123', config('services.stripe.webhook_secret'));
    }

    public function test_a_blank_secret_keeps_the_stored_value(): void
    {
        $stripe = app(StripeSettingsService::class);
        $stripe->save('pk_test_abc', 'usd', 'sk_test_xyz', 'whsec_123');

        // Re-save changing only the publishable key + currency.
        $stripe->save('pk_test_new', 'eur', null, null);

        $this->assertSame('pk_test_new', $stripe->publishableKey());
        $this->assertSame('sk_test_xyz', $stripe->secret());     // unchanged
        $this->assertSame('whsec_123', $stripe->webhookSecret()); // unchanged
        $this->assertSame('eur', $stripe->currency());
    }

    public function test_admin_can_save_keys_and_the_secret_is_cleared_from_state(): void
    {
        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->set('publishableKey', 'pk_test_abc')
            ->set('secretKey', 'sk_test_xyz')
            ->set('webhookSecret', 'whsec_1')
            ->set('currency', 'usd')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('secretKey', '')       // never lingers in the DOM
            ->assertSet('webhookSecret', '');

        $this->assertTrue(app(StripeSettingsService::class)->configured());
    }

    public function test_key_formats_are_validated(): void
    {
        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->set('publishableKey', 'not_a_pk')
            ->set('secretKey', 'nope')
            ->set('webhookSecret', 'bad')
            ->set('currency', 'usd')
            ->call('save')
            ->assertHasErrors(['publishableKey', 'secretKey', 'webhookSecret']);
    }

    public function test_billing_settings_page_requires_permission(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Viewer->value));
        $this->actingAs($viewer)->get('/admin/billing')->assertForbidden();

        $this->actingAs($this->admin())->get('/admin/billing')->assertOk();
    }
}
