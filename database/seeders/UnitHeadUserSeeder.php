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
     * Seed Unit Head accounts and colleges for local testing.
     */
    private array $colleges = [
        ['name' => 'College of Arts and Sciences', 'shorten' => 'CAS'],
        ['name' => 'College of Education', 'shorten' => 'CED'],
        ['name' => 'College of Management and Entrepreneurship', 'shorten' => 'CME'],
    ];

    public function run(): void
    {
        $unitHeadCounter = 1;

        foreach ($this->colleges as $collegeData) {
            // Create or retrieve college
            $collegeId = DB::table('colleges')
                ->where('shorten', $collegeData['shorten'])
                ->value('id');

            if ($collegeId === null) {
                $collegeId = DB::table('colleges')->insertGetId([
                    'name' => $collegeData['name'],
                    'shorten' => $collegeData['shorten'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create a unit for this college
            $unitShorten = $collegeData['shorten'];
            $unitId = DB::table('units')
                ->where('department_id', $collegeId)
                ->where('shorten', $unitShorten)
                ->value('id');

            if ($unitId === null) {
                $unitId = DB::table('units')->insertGetId([
                    'department_id' => $collegeId,
                    'name' => $collegeData['name'] . ' Unit',
                    'shorten' => $unitShorten,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Create Unit Head for this college
            $idNo = 'UH-' . str_pad($unitHeadCounter, 4, '0', STR_PAD_LEFT);
            $user = User::query()->updateOrCreate(
                ['id_no' => $idNo],
                [
                    'lastname' => 'Head',
                    'firstname' => $collegeData['shorten'],
                    'middlename' => null,
                    'extname' => null,
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

            $unitHeadCounter++;
        }
    }
}