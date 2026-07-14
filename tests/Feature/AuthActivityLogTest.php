<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuthActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login_is_logged(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $activity = Activity::where('log_name', 'auth')->where('description', 'login')->first();

        $this->assertNotNull($activity);
        $this->assertTrue($activity->causer->is($user));
        $this->assertArrayHasKey('ip', $activity->properties->toArray());
    }

    public function test_failed_login_is_logged_with_email(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'wrong-password',
        ]);

        $activity = Activity::where('log_name', 'auth')->where('description', 'login_failed')->first();

        $this->assertNotNull($activity);
        $this->assertSame($user->email, $activity->properties['email']);
    }

    public function test_logout_is_logged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout');

        $this->assertNotNull(
            Activity::where('log_name', 'auth')->where('description', 'logout')->first()
        );
    }
}
