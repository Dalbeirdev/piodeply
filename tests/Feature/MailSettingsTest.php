<?php

namespace Tests\Feature;

use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\MailSettings;
use App\Models\User;
use App\Services\MailSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Mail was configured only in .env, so changing provider meant an SSH
 * session, a config:cache and a redeploy — and getting it wrong was silent.
 */
class MailSettingsTest extends TestCase
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

    private function mail(): MailSettingsService
    {
        return app(MailSettingsService::class);
    }

    private function page()
    {
        return Livewire::actingAs($this->admin())->test(MailSettings::class);
    }

    private function valid(array $overrides = []): array
    {
        return [
            'host' => 'smtp.postmarkapp.com', 'port' => '587', 'username' => 'token-user',
            'password' => 's3cret', 'scheme' => 'tls',
            'from_address' => 'hello@piodeploy.com', 'from_name' => 'PioDeploy',
            ...$overrides,
        ];
    }

    private function fill($component, array $values)
    {
        foreach ($values as $k => $v) {
            $component->set($k, $v);
        }

        return $component;
    }

    public function test_settings_saved_here_take_over_from_the_env(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.yourprovider.com']);

        $this->fill($this->page(), $this->valid())->call('save')->assertHasNoErrors();

        $this->mail()->apply();

        $this->assertSame('smtp.postmarkapp.com', config('mail.mailers.smtp.host'));
        $this->assertSame(587, config('mail.mailers.smtp.port'));
        $this->assertSame('hello@piodeploy.com', config('mail.from.address'));
    }

    /** Nothing saved here must not clobber an install that already works. */
    public function test_an_unconfigured_portal_leaves_the_env_alone(): void
    {
        config(['mail.mailers.smtp.host' => 'smtp.already-working.test']);

        $this->mail()->apply();

        $this->assertSame('smtp.already-working.test', config('mail.mailers.smtp.host'));
    }

    /* ─────────── the password ─────────── */

    public function test_the_password_is_encrypted_at_rest(): void
    {
        $this->fill($this->page(), $this->valid())->call('save');

        $stored = app(\App\Services\SettingsService::class)->get('mail.password');

        $this->assertNotSame('s3cret', $stored, 'the password must not be stored in the clear');
        $this->assertSame('s3cret', Crypt::decryptString($stored));
        $this->assertSame('s3cret', $this->mail()->password());
    }

    /**
     * Livewire serialises public properties into the page. The stored password
     * must never be loaded into one.
     */
    public function test_the_stored_password_is_never_sent_to_the_browser(): void
    {
        $this->fill($this->page(), $this->valid())->call('save');

        $this->page()
            ->assertSet('password', '')
            ->assertDontSee('s3cret');
    }

    public function test_a_blank_password_field_keeps_the_stored_one(): void
    {
        $this->fill($this->page(), $this->valid())->call('save');

        // Save again, changing only the host and leaving the field empty.
        $this->fill($this->page(), $this->valid(['password' => '', 'host' => 'smtp.brevo.com']))->call('save');

        $this->assertSame('s3cret', $this->mail()->password());
        $this->assertSame('smtp.brevo.com', $this->mail()->get('host'));
    }

    public function test_a_stored_password_can_be_removed_deliberately(): void
    {
        $this->fill($this->page(), $this->valid())->call('save');

        $this->page()->call('clearPassword');

        $this->assertNull($this->mail()->password());
    }

    /** A rotated APP_KEY must not authenticate with gibberish. */
    public function test_an_undecryptable_password_reads_as_absent(): void
    {
        app(\App\Services\SettingsService::class)->set('mail.password', 'not-actually-encrypted');

        $this->assertNull($this->mail()->password());
    }

    /* ─────────── the test send ─────────── */

    public function test_sending_a_test_reports_success(): void
    {
        Mail::fake();

        $this->fill($this->page(), $this->valid())->call('save')
            ->set('testTo', 'admin@piodeploy.com')
            ->call('sendTest')
            ->assertSet('testSent', true)
            ->assertSet('testError', null);
    }

    /** The provider's complaint verbatim — paraphrasing it helps nobody. */
    public function test_a_failing_send_shows_what_the_provider_said(): void
    {
        $this->fill($this->page(), $this->valid())->call('save');

        Mail::shouldReceive('raw')->andThrow(new \RuntimeException('Connection refused by smtp.postmarkapp.com:587'));

        $this->page()
            ->set('testTo', 'admin@piodeploy.com')
            ->call('sendTest')
            ->assertSet('testSent', false)
            ->assertSee('Connection refused by smtp.postmarkapp.com:587');
    }

    /* ─────────── validation and access ─────────── */

    public function test_a_host_and_a_valid_sender_are_required(): void
    {
        $this->fill($this->page(), $this->valid(['host' => '', 'from_address' => 'not-an-email']))
            ->call('save')
            ->assertHasErrors(['host', 'from_address']);
    }

    public function test_only_settings_managers_can_reach_it(): void
    {
        $viewer = tap(User::factory()->create(), fn (User $u) => $u->assignRole(RoleEnum::Viewer->value));

        $this->actingAs($viewer)->get(route('admin.mail'))->assertForbidden();
    }

    public function test_it_appears_in_the_admin_section_of_the_nav(): void
    {
        $items = collect(app(\App\Services\NavigationService::class)->groups($this->admin()))
            ->firstWhere('label', \App\Services\NavigationService::ADMIN)['items'];

        $this->assertContains('Email', array_column($items, 'label'));
    }
}
