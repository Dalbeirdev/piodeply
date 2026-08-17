<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Signup;
use App\Models\User;
use App\Services\SignupApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Client emails are unique and the index counts soft-deleted rows, so a
 * deleted client used to own its address permanently: the person could never
 * sign up again and the operator had no way to release it. Deleting now
 * parks the address instead of holding it.
 */
class DeletedClientReleasesEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_deleting_frees_the_address(): void
    {
        $client = Client::factory()->create(['email' => 'akanksha@techpio.com']);
        $client->delete();

        $this->assertFalse(
            Client::withTrashed()->where('email', 'akanksha@techpio.com')->exists(),
            'the address must no longer be held by anyone'
        );
        $this->assertTrue($client->fresh()->emailIsParked());
    }

    public function test_the_original_address_is_not_lost(): void
    {
        $client = Client::factory()->create(['email' => 'akanksha@techpio.com']);
        $client->delete();

        $this->assertSame('akanksha@techpio.com', $client->fresh()->parkedEmail());
    }

    public function test_restoring_takes_the_address_back(): void
    {
        $client = Client::factory()->create(['email' => 'akanksha@techpio.com']);
        $client->delete();
        $client->restore();

        $this->assertSame('akanksha@techpio.com', $client->fresh()->email);
        $this->assertFalse($client->fresh()->emailIsParked());
    }

    public function test_restoring_does_not_steal_an_address_someone_else_took(): void
    {
        $old = Client::factory()->create(['email' => 'akanksha@techpio.com']);
        $old->delete();

        // Somebody signed up with it in the meantime — which is the whole
        // point of releasing it.
        Client::factory()->create(['email' => 'akanksha@techpio.com']);

        $old->restore();

        $this->assertTrue($old->fresh()->emailIsParked(), 'the restore keeps the parked address rather than colliding');
        $this->assertSame(1, Client::where('email', 'akanksha@techpio.com')->count());
    }

    /** The end-to-end case that produced the 500. */
    public function test_a_signup_can_be_approved_after_the_clashing_client_is_deleted(): void
    {
        $old = Client::factory()->create(['company_name' => 'Akanksha', 'email' => 'akanksha@techpio.com']);
        $old->delete();

        $signup = Signup::factory()->create([
            'company_name' => 'AMG Studio',
            'email'        => 'akanksha@techpio.com',
            'status'       => Signup::STATUS_PAID,
            'paid_at'      => now(),
        ]);

        $admin = tap(User::factory()->create(), fn (User $u) => $u->assignRole(\App\Enums\Role::Admin->value));

        $owner = app(SignupApprovalService::class)->approve($signup, $admin);

        $this->assertSame('akanksha@techpio.com', $owner->email);
        $this->assertSame(Signup::STATUS_APPROVED, $signup->fresh()->status);
        $this->assertSame('AMG Studio', Client::find($signup->fresh()->client_id)->company_name);
    }

    public function test_a_force_delete_needs_no_parking(): void
    {
        $client = Client::factory()->create(['email' => 'gone@techpio.com']);
        $client->forceDelete();

        $this->assertSame(0, Client::withTrashed()->where('email', 'like', '%gone@techpio.com')->count());
    }
}
