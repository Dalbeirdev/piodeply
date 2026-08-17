<?php

namespace Tests\Feature;

use App\Livewire\Admin\SignupsIndex;
use App\Livewire\Marketing\SignupWizard;
use App\Models\Client;
use App\Models\Signup;
use App\Models\User;
use App\Services\SignupApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Approving a signup creates a row in BOTH users and clients, and both have
 * unique emails. Only users was ever checked, so an applicant could pay for
 * an account that could never be approved — and the approval itself died on
 * a raw constraint violation, 500-ing the whole Signups page.
 */
class DuplicateSignupEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));
    }

    public function test_approval_refuses_clearly_instead_of_crashing(): void
    {
        Client::factory()->create(['company_name' => 'Akanksha', 'email' => 'taken@techpio.com']);

        $signup = Signup::factory()->create([
            'company_name' => 'AMG Studio',
            'email'        => 'taken@techpio.com',
            'status'       => Signup::STATUS_PAID,
            'paid_at'      => now(),
        ]);

        // A DomainException is what the Signups page knows how to display.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already uses taken@techpio.com');

        app(SignupApprovalService::class)->approve($signup, $this->admin());
    }

    public function test_the_signups_page_shows_the_reason_rather_than_a_server_error(): void
    {
        Client::factory()->create(['company_name' => 'Akanksha', 'email' => 'taken@techpio.com']);

        $signup = Signup::factory()->create([
            'company_name' => 'AMG Studio',
            'email'        => 'taken@techpio.com',
            'status'       => Signup::STATUS_PAID,
            'paid_at'      => now(),
        ]);

        // The page handles it instead of throwing: this call used to escape
        // as a database exception and render a 500.
        Livewire::actingAs($this->admin())
            ->test(SignupsIndex::class)
            ->call('approve', $signup->id)
            ->assertOk();

        // And nothing was half-created on the way out.
        $this->assertSame(Signup::STATUS_PAID, $signup->fresh()->status);
        $this->assertSame(0, User::where('email', 'taken@techpio.com')->count());
        $this->assertSame(1, Client::where('email', 'taken@techpio.com')->count());
    }

    /**
     * Deleting a client now releases its address (see
     * DeletedClientReleasesEmailTest), but rows deleted BEFORE that existed
     * still hold theirs. The guard keeps covering them, so an old record
     * explains itself instead of 500ing.
     */
    public function test_a_legacy_deleted_client_holding_the_address_is_explained(): void
    {
        $client = Client::factory()->create(['company_name' => 'Akanksha', 'email' => 'taken@techpio.com']);
        $client->delete();

        // Put the row back the way pre-fix deletions left it: trashed, with
        // the address still on it. Written straight to the table so the
        // model hook cannot re-park it.
        \Illuminate\Support\Facades\DB::table('clients')
            ->where('id', $client->id)
            ->update(['email' => 'taken@techpio.com']);

        $signup = Signup::factory()->create([
            'company_name' => 'AMG Studio',
            'email'        => 'taken@techpio.com',
            'status'       => Signup::STATUS_PAID,
            'paid_at'      => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('deleted client');

        app(SignupApprovalService::class)->approve($signup, $this->admin());
    }

    public function test_signup_refuses_an_email_that_already_belongs_to_a_client(): void
    {
        Client::factory()->create(['email' => 'taken@techpio.com']);

        Livewire::test(SignupWizard::class)
            ->set('machines', 20)
            ->call('next')
            ->set('contact_name', 'Akanksha Maurya')
            ->set('email', 'taken@techpio.com')
            ->set('password', 'abcdefgh12')
            ->set('password_confirmation', 'abcdefgh12')
            ->call('next')
            ->assertHasErrors('email');
    }

    public function test_a_fresh_email_still_gets_through(): void
    {
        Livewire::test(SignupWizard::class)
            ->set('machines', 20)
            ->call('next')
            ->set('contact_name', 'New Person')
            ->set('email', 'brand.new@techpio.com')
            ->set('password', 'abcdefgh12')
            ->set('password_confirmation', 'abcdefgh12')
            ->call('next')
            ->assertHasNoErrors();
    }
}
