<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/dashboard', '/claims', '/claims/create', '/reports', '/settings/users'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_active_user_can_log_in(): void
    {
        $user = $this->makeUser('staff', null, ['email' => 'a@test.local']);

        $this->post('/login', ['email' => 'a@test.local', 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->makeUser('staff', null, ['email' => 'a@test.local']);

        $this->post('/login', ['email' => 'a@test.local', 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $this->makeUser('staff', null, ['email' => 'off@test.local', 'is_active' => false]);

        $this->post('/login', ['email' => 'off@test.local', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_deactivated_while_logged_in_is_kicked_out(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_deactivated_user_history_is_kept(): void
    {
        $user = $this->makeUser();
        $claim = $this->makeClaim($user);
        $user->update(['is_active' => false]);

        $this->assertDatabaseHas('claims', ['id' => $claim->id, 'user_id' => $user->id]);
    }
}
