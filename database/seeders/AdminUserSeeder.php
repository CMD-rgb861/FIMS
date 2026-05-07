<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a default Admin account for local testing.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['id_no' => 'ADMIN-0001'],
            [
                'lastname' => 'Administrator',
                'firstname' => 'System',
                'middlename' => null,
                'extname' => null,
                'role' => User::ROLE_ADMIN,
                'password' => Hash::make('Admin@123'),
            ]
        );
    }
}
