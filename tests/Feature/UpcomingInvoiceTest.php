<?php

namespace Tests\Feature;

use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Stripe retired /invoices/upcoming; it now answers 404. The old code turned
 * that into a plain null, so the customer's "Next payment" line vanished with
 * nothing logged. The replacement preview endpoint needs the subscription.
 */
class UpcomingInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.secret' => 'sk_test_x']);
    }

    public function test_it_asks_the_preview_endpoint_with_the_subscription(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'amount_due'           => 4800,
            'next_payment_attempt' => 1788692131,
        ])]);

        $result = app(BillingService::class)->upcomingInvoice('cus_1', 'sub_1');

        $this->assertSame('48.00', $result['amount']);
        $this->assertSame(date('j M Y', 1788692131), $result['date']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/invoices/create_preview')
                && $request['customer'] === 'cus_1'
                && $request['subscription'] === 'sub_1';
        });
    }

    public function test_it_falls_back_to_period_end_when_no_attempt_is_scheduled(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response([
            'amount_due' => 1600,
            'period_end' => 1788692131,
        ])]);

        $result = app(BillingService::class)->upcomingInvoice('cus_1', 'sub_1');

        $this->assertSame(date('j M Y', 1788692131), $result['date']);
    }

    public function test_without_a_subscription_there_is_nothing_to_preview(): void
    {
        Http::fake();

        $this->assertNull(app(BillingService::class)->upcomingInvoice('cus_1', null));

        Http::assertNothingSent();
    }

    public function test_a_stripe_failure_is_logged_rather_than_silently_swallowed(): void
    {
        Log::spy();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'boom']], 400)]);

        $this->assertNull(app(BillingService::class)->upcomingInvoice('cus_1', 'sub_1'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'upcoming-invoice'))
            ->once();
    }

    public function test_it_does_nothing_without_stripe_configured(): void
    {
        config(['services.stripe.secret' => null]);
        Http::fake();

        $this->assertNull(app(BillingService::class)->upcomingInvoice('cus_1', 'sub_1'));

        Http::assertNothingSent();
    }
}
