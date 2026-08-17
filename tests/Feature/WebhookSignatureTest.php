<?php

namespace Tests\Feature;

use App\Services\BillingService;
use App\Services\StripeSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * This app exposes two Stripe endpoints — the legacy checkout one and the
 * subscription one — and Stripe issues a different signing secret for each.
 * Verifying against only one silently rejected every event from the other,
 * which is how weeks of subscriptions and invoices went unrecorded.
 */
class WebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function sign(string $payload, string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    public function test_either_endpoints_secret_verifies(): void
    {
        config([
            'services.stripe.webhook_secret'      => 'whsec_checkout',
            'services.stripe.webhook_secret_subs' => 'whsec_subscriptions',
        ]);

        $billing = app(BillingService::class);
        $payload = '{"id":"evt_1","type":"invoice.paid"}';

        $this->assertTrue($billing->verifyWebhook($payload, $this->sign($payload, 'whsec_checkout')));
        $this->assertTrue($billing->verifyWebhook($payload, $this->sign($payload, 'whsec_subscriptions')));
    }

    public function test_a_wrong_secret_is_still_rejected(): void
    {
        config([
            'services.stripe.webhook_secret'      => 'whsec_checkout',
            'services.stripe.webhook_secret_subs' => 'whsec_subscriptions',
        ]);

        $payload = '{"id":"evt_1","type":"invoice.paid"}';

        $this->assertFalse(
            app(BillingService::class)->verifyWebhook($payload, $this->sign($payload, 'whsec_not_ours'))
        );
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_checkout', 'services.stripe.webhook_secret_subs' => null]);

        $signature = $this->sign('{"id":"evt_1","amount":100}', 'whsec_checkout');

        $this->assertFalse(
            app(BillingService::class)->verifyWebhook('{"id":"evt_1","amount":999999}', $signature)
        );
    }

    public function test_a_replayed_old_signature_is_rejected(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_checkout', 'services.stripe.webhook_secret_subs' => null]);

        $payload = '{"id":"evt_1"}';
        $stale = $this->sign($payload, 'whsec_checkout', time() - 3600);

        $this->assertFalse(app(BillingService::class)->verifyWebhook($payload, $stale));
    }

    public function test_with_no_secrets_configured_nothing_verifies(): void
    {
        config(['services.stripe.webhook_secret' => null, 'services.stripe.webhook_secret_subs' => null]);

        $payload = '{"id":"evt_1"}';

        $this->assertFalse(
            app(BillingService::class)->verifyWebhook($payload, $this->sign($payload, 'whsec_anything'))
        );
    }

    public function test_the_subscription_secret_round_trips_encrypted(): void
    {
        $stripe = app(StripeSettingsService::class);
        $stripe->saveSubscriptionWebhookSecret('whsec_stored_subs');

        $this->assertSame('whsec_stored_subs', $stripe->subscriptionWebhookSecret());

        // Stored ciphertext must not contain the plaintext.
        $raw = (string) app(\App\Services\SettingsService::class)->get('billing.stripe_whsec_subs');
        $this->assertStringNotContainsString('whsec_stored_subs', $raw);
    }
}
