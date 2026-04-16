<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ITFacultyUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a sample IT Faculty user for local testing.
     */
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['id_no' => 'ITF-0001'],
            [
                'lastname' => 'Faculty',
                'firstname' => 'IT',
                'middlename' => null,
                'extname' => null,
                'password' => Hash::make('Faculty@123'),
            ]
        );
    }
}
