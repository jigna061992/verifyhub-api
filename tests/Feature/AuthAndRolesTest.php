<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesFixtures;
use Tests\TestCase;

class AuthAndRolesTest extends TestCase
{
    use RefreshDatabase, MakesFixtures;

    public function test_login_works_with_correct_password_and_role(): void
    {
        $r = $this->makeRetailer();

        $this->postJson('/api/login', ['login' => $r->user->email, 'password' => 'Secret123', 'role' => 'retailer'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $r = $this->makeRetailer();

        $this->postJson('/api/login', ['login' => $r->user->email, 'password' => 'WrongPass1', 'role' => 'retailer'])
            ->assertStatus(422);
    }

    public function test_retailer_cannot_sign_in_as_admin(): void
    {
        $r = $this->makeRetailer();

        $this->postJson('/api/login', ['login' => $r->user->email, 'password' => 'Secret123', 'role' => 'super_admin'])
            ->assertStatus(403);
    }

    public function test_deactivated_user_cannot_sign_in(): void
    {
        $r = $this->makeRetailer(0, 'inactive');

        $this->postJson('/api/login', ['login' => $r->user->email, 'password' => 'Secret123', 'role' => 'retailer'])
            ->assertStatus(403);
    }

    public function test_protected_routes_need_a_token(): void
    {
        $this->getJson('/api/retailer/wallet')->assertStatus(401);
        $this->getJson('/api/admin/retailers')->assertStatus(401);
    }

    public function test_retailer_cannot_open_admin_routes(): void
    {
        $r = $this->makeRetailer();

        $this->actingAsUser($r->user)->getJson('/api/admin/retailers')->assertStatus(403);
        $this->getJson('/api/admin/reconciliations')->assertStatus(403);
        $this->getJson('/api/admin/reports/transactions')->assertStatus(403);
    }

    public function test_admin_cannot_open_retailer_routes(): void
    {
        $this->actingAsUser($this->makeAdmin())->getJson('/api/retailer/wallet')->assertStatus(403);
    }

    public function test_password_hash_is_never_returned(): void
    {
        $r = $this->makeRetailer();

        $res = $this->actingAsUser($r->user)->getJson('/api/user')->assertOk();

        $this->assertStringNotContainsString('password', json_encode($res->json()));
    }
}
