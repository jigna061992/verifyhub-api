<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@verifyhub.test'],
            [
                'name'     => 'Super Admin',
                'mobile'   => '9999999999',
                'password' => 'admin@123',   // hashed automatically by the model cast
                'role'     => 'super_admin',
                'status'   => 'active',
            ]
        );
    }
}