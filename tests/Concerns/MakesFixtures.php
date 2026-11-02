<?php

namespace Tests\Concerns;

use App\Models\Retailer;
use App\Models\User;
use App\Models\VerificationService;
use Database\Seeders\ServiceSeeder;
use Laravel\Sanctum\Sanctum;

// Small helpers so every test can create an admin, a retailer (with wallet) and the services in one line
trait MakesFixtures
{
    private int $seq = 0;

    protected function seedServices(): void
    {
        $this->seed(ServiceSeeder::class);
    }

    protected function makeAdmin(): User
    {
        $n = ++$this->seq;

        return User::create([
            'name' => "Admin {$n}", 'email' => "admin{$n}@test.local", 'mobile' => '90000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => 'Secret123', 'role' => 'super_admin', 'status' => 'active',
        ]);
    }

    protected function makeRetailer(float $balance = 0, string $status = 'active'): Retailer
    {
        $n = ++$this->seq;

        $user = User::create([
            'name' => "Retailer {$n}", 'email' => "retailer{$n}@test.local", 'mobile' => '80000000' . str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'password' => 'Secret123', 'role' => 'retailer', 'status' => $status,
        ]);

        $retailer = Retailer::create([
            'user_id' => $user->id, 'business_name' => "Shop {$n}", 'address' => 'Test address, Ahmedabad',
            'pan' => 'ABCDE1234F', 'status' => $status,
        ]);

        $retailer->wallet()->create(['available_balance' => $balance]);

        return $retailer->fresh(['user', 'wallet']);
    }

    protected function actingAsUser(User $user): static
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    protected function service(string $code): VerificationService
    {
        return VerificationService::where('code', $code)->firstOrFail();
    }

    protected function wallet(Retailer $retailer): array
    {
        $w = $retailer->wallet()->first();

        return ['available' => (float) $w->available_balance, 'reserved' => (float) $w->reserved_balance];
    }
}