<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UnitHeadUserSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a default Unit Head account for local testing.
     */
    public function run(): void
    {
        $collegeId = DB::table('colleges')->orderBy('id')->value('id');

        if ($collegeId === null) {
            $collegeId = DB::table('colleges')->insertGetId([
                'name' => 'College of Information Technology',
                'shorten' => 'CIT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $unitId = DB::table('units')->orderBy('id')->value('id');

        if ($unitId === null) {
            $unitId = DB::table('units')->insertGetId([
                'department_id' => $collegeId,
                'name' => 'Information Technology Unit',
                'shorten' => 'IT',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::query()->updateOrCreate(
            ['id_no' => 'UH-0001'],
            [
                'lastname' => 'Head',
                'firstname' => 'Unit',
                'middlename' => null,
                'extname' => null,
                'role' => User::ROLE_UNIT_HEAD,
                'password' => Hash::make('UnitHead@123'),
            ]
        );

        DB::table('unit_heads')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'unit_id' => $unitId,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}