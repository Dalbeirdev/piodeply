<?php

namespace Tests\Feature;

use App\Livewire\Admin\BillingSettings;
use App\Models\User;
use App\Services\BillingService;
use App\Services\SettingsService;
use App\Services\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The free trial is an operator setting, not a constant. Everything that
 * promises a trial length — the pricing page, the signup wizard, the
 * checkout Stripe receives — has to quote the same number, or a buyer is
 * told one thing and billed on another.
 */
class TrialLengthSettingTest extends TestCase
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

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_it_defaults_to_fourteen_days(): void
    {
        $this->assertSame(14, $this->settings()->trialDays());
    }

    public function test_an_admin_changes_the_trial_length(): void
    {
        Livewire::actingAs($this->admin())
            ->test(BillingSettings::class)
            ->set('trialDays', 30)
            ->set('currency', 'usd')
            ->set('clientGraceDays', 14)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(30, $this->settings()->trialDays());
    }

    public function test_a_negative_or_absurd_trial_is_refused(): void
    {
        foreach ([-1, 400] as $bad) {
            Livewire::actingAs($this->admin())
                ->test(BillingSettings::class)
                ->set('trialDays', $bad)
                ->set('currency', 'usd')
                ->set('clientGraceDays', 14)
                ->call('save')
                ->assertHasErrors('trialDays');
        }

        $this->assertSame(14, $this->settings()->trialDays(), 'the stored value is untouched');
    }

    public function test_a_stored_value_out_of_range_is_still_clamped(): void
    {
        // Settings can also be written by tinker or a seeder, which do not
        // go through the form's validation.
        $this->settings()->set('billing.trial_days', '-5');
        $this->assertSame(0, $this->settings()->trialDays());

        $this->settings()->set('billing.trial_days', '9999');
        $this->assertSame(365, $this->settings()->trialDays());
    }

    public function test_the_checkout_sent_to_stripe_uses_the_configured_length(): void
    {
        $this->settings()->set('billing.trial_days', '21');
        config(['services.stripe.key' => 'pk_test_x', 'services.stripe.secret' => 'sk_test_x']);
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1'])]);

        app(BillingService::class)->createCheckout(
            machines: 100, successUrl: 'https://x/s', cancelUrl: 'https://x/c',
        );

        Http::assertSent(fn ($request) => str_contains($request->url(), '/checkout/sessions')
            && ($request->data()['subscription_data']['trial_period_days'] ?? null) == 21);
    }

    public function test_both_payment_paths_agree_on_the_number(): void
    {
        $this->settings()->set('billing.trial_days', '7');

        $this->assertSame(7, app(BillingService::class)->trialDays());
        $this->assertSame(7, app(SubscriptionService::class)->trialDays());
        $this->assertSame(7, trial_days());
    }

    public function test_buyer_facing_copy_follows_the_setting(): void
    {
        $this->settings()->set('billing.trial_days', '30');

        $this->get('/pricing')->assertOk()->assertSee('30-day free trial')->assertDontSee('14-day free trial');
    }

    public function test_zero_days_reads_as_no_trial_rather_than_a_zero_day_one(): void
    {
        $this->settings()->set('billing.trial_days', '0');

        $this->assertSame(0, trial_days());
        $this->assertSame('no free trial', trial_phrase());
    }
}
