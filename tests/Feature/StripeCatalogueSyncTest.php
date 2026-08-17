<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

/**
 * billing:sync-stripe must be able to rebuild a catalogue whose Stripe ids
 * have gone stale — the state left behind when the account behind
 * STRIPE_SECRET is swapped. Before this was fixed the command trusted any
 * non-empty id, reported "(exists)" for a catalogue Stripe had never heard
 * of, and left every checkout failing on "No such price".
 */
class StripeCatalogueSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cashier.secret' => 'sk_test_fake', 'cashier.key' => 'pk_test_fake']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    private function stubStripe(callable $handler): void
    {
        ApiRequestor::setHttpClient(new class($handler) implements ClientInterface
        {
            public function __construct(private $handler) {}

            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return ($this->handler)($method, $absUrl, $params);
            }
        });
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'slug'                    => 'twenty',
            'name'                    => '20 Machines',
            'device_limit'            => 20,
            'monthly_price_cents'     => 1600,
            'yearly_price_cents'      => 16000,
            'currency'                => 'usd',
            'stripe_product_id'       => 'prod_dead',
            'stripe_monthly_price_id' => 'price_dead_m',
            'stripe_yearly_price_id'  => 'price_dead_y',
        ], $overrides));
    }

    private static function missing(string $id): array
    {
        return [json_encode(['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => "No such object: '{$id}'"]]), 404, []];
    }

    private static function ok(array $body): array
    {
        return [json_encode($body), 200, []];
    }

    public function test_it_rebuilds_a_catalogue_the_stripe_account_no_longer_has(): void
    {
        $plan = $this->plan();

        $this->stubStripe(function (string $method, string $url, array $params) {
            // Everything the old account held is gone from this one.
            if ($method === 'get' && str_contains($url, '/v1/products/prod_dead')) {
                return self::missing('prod_dead');
            }
            if ($method === 'post' && str_ends_with($url, '/v1/products')) {
                return self::ok(['id' => 'prod_new', 'object' => 'product']);
            }
            if ($method === 'post' && str_ends_with($url, '/v1/prices')) {
                $interval = $params['recurring']['interval'];

                return self::ok(['id' => 'price_new_'.$interval, 'object' => 'price', 'active' => true]);
            }

            throw new \LogicException("unexpected Stripe call: {$method} {$url}");
        });

        $this->artisan('billing:sync-stripe')->assertSuccessful();

        $plan->refresh();
        $this->assertSame('prod_new', $plan->stripe_product_id);
        $this->assertSame('price_new_month', $plan->stripe_monthly_price_id);
        $this->assertSame('price_new_year', $plan->stripe_yearly_price_id);
    }

    public function test_it_replaces_a_price_that_was_archived(): void
    {
        $plan = $this->plan();

        $this->stubStripe(function (string $method, string $url, array $params) {
            if ($method === 'get' && str_contains($url, '/v1/products/prod_dead')) {
                return self::ok(['id' => 'prod_dead', 'object' => 'product']);
            }
            // The product survives; the monthly price was archived.
            if ($method === 'get' && str_contains($url, '/v1/prices/price_dead_m')) {
                return self::ok(['id' => 'price_dead_m', 'object' => 'price', 'active' => false]);
            }
            if ($method === 'get' && str_contains($url, '/v1/prices/price_dead_y')) {
                return self::ok(['id' => 'price_dead_y', 'object' => 'price', 'active' => true]);
            }
            if ($method === 'post' && str_ends_with($url, '/v1/prices')) {
                return self::ok(['id' => 'price_fresh_m', 'object' => 'price', 'active' => true]);
            }

            throw new \LogicException("unexpected Stripe call: {$method} {$url}");
        });

        $this->artisan('billing:sync-stripe')->assertSuccessful();

        $plan->refresh();
        $this->assertSame('prod_dead', $plan->stripe_product_id, 'a healthy product is left alone');
        $this->assertSame('price_fresh_m', $plan->stripe_monthly_price_id);
        $this->assertSame('price_dead_y', $plan->stripe_yearly_price_id, 'a healthy price is left alone');
    }

    public function test_an_outage_never_orphans_a_live_catalogue(): void
    {
        $plan = $this->plan();

        // A 500 is not evidence that anything is missing. Treating it as
        // absence would duplicate every product and price on the next run.
        $this->stubStripe(fn () => [json_encode(['error' => ['type' => 'api_error', 'message' => 'upstream boom']]), 500, []]);

        try {
            $this->artisan('billing:sync-stripe')->run();
        } catch (\Throwable) {
            // The command is allowed to blow up; what matters is the data.
        }

        $plan->refresh();
        $this->assertSame('prod_dead', $plan->stripe_product_id);
        $this->assertSame('price_dead_m', $plan->stripe_monthly_price_id);
        $this->assertSame('price_dead_y', $plan->stripe_yearly_price_id);
    }

    public function test_a_dry_run_reports_the_rebuild_without_writing(): void
    {
        $plan = $this->plan();

        $this->stubStripe(function (string $method, string $url) {
            if ($method === 'get' && str_contains($url, '/v1/products/prod_dead')) {
                return self::missing('prod_dead');
            }

            throw new \LogicException("dry run must not write: {$method} {$url}");
        });

        $this->artisan('billing:sync-stripe --dry-run')
            ->expectsOutputToContain('would create product')
            ->assertSuccessful();

        $plan->refresh();
        $this->assertSame('prod_dead', $plan->stripe_product_id, 'a dry run leaves the database untouched');
    }
}
