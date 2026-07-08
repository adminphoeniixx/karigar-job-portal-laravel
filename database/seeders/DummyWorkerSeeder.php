<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkerProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DummyWorkerSeeder extends Seeder
{
    /** All dummy accounts share this email domain so they are easy to bulk-delete. */
    public const DOMAIN = 'dummy.karigar.test';

    public function run(): void
    {
        $target = (int) env('DUMMY_WORKERS', 1200);
        $password = Hash::make('password');
        $now = now();

        $first = ['Aarav', 'Vivaan', 'Aditya', 'Vihaan', 'Arjun', 'Sai', 'Reyansh', 'Krishna', 'Ishaan', 'Rohan',
            'Ramesh', 'Suresh', 'Mahesh', 'Rakesh', 'Dinesh', 'Mukesh', 'Ganesh', 'Naresh', 'Rajesh', 'Umesh',
            'Amit', 'Sumit', 'Rohit', 'Mohit', 'Ankit', 'Vijay', 'Sanjay', 'Ajay', 'Deepak', 'Manoj',
            'Pooja', 'Priya', 'Neha', 'Anjali', 'Kavita', 'Sunita', 'Rekha', 'Meena', 'Geeta', 'Sita'];

        $last = ['Sharma', 'Verma', 'Gupta', 'Kumar', 'Singh', 'Yadav', 'Patel', 'Reddy', 'Nair', 'Iyer',
            'Das', 'Bose', 'Chauhan', 'Mishra', 'Pandey', 'Jain', 'Shah', 'Mehta', 'Rao', 'Naidu',
            'Prasad', 'Thakur', 'Chowdhury', 'Malik', 'Khan', 'Ansari', 'Sheikh', 'Pillai', 'Menon', 'Joshi'];

        $skillsPool = ['Electrician', 'Plumber', 'Carpenter', 'Painter', 'Mason', 'Welder', 'Driver', 'Cook',
            'Gardener', 'Cleaner', 'AC Technician', 'Mechanic', 'Tailor', 'Barber', 'Security Guard',
            'Housekeeping', 'Cook Helper', 'Fabricator', 'Fitter', 'Tile Setter', 'Roofer', 'Labour'];

        $locations = [
            ['Mumbai', 'Maharashtra'], ['Pune', 'Maharashtra'], ['Nagpur', 'Maharashtra'],
            ['Delhi', 'Delhi'], ['Jaipur', 'Rajasthan'], ['Jodhpur', 'Rajasthan'],
            ['Ahmedabad', 'Gujarat'], ['Surat', 'Gujarat'], ['Bengaluru', 'Karnataka'],
            ['Hyderabad', 'Telangana'], ['Chennai', 'Tamil Nadu'], ['Coimbatore', 'Tamil Nadu'],
            ['Kolkata', 'West Bengal'], ['Lucknow', 'Uttar Pradesh'], ['Kanpur', 'Uttar Pradesh'],
            ['Indore', 'Madhya Pradesh'], ['Bhopal', 'Madhya Pradesh'], ['Patna', 'Bihar'],
            ['Chandigarh', 'Punjab'], ['Ludhiana', 'Punjab'], ['Kochi', 'Kerala'],
        ];

        $wageTypes = ['daily', 'monthly', 'hourly'];

        // Resume support: skip indices whose dummy user already exists.
        $existing = User::where('email', 'like', '%@'.self::DOMAIN)->pluck('email')
            ->map(fn ($e) => (int) filter_var($e, FILTER_SANITIZE_NUMBER_INT))
            ->flip();

        $this->command?->info("Target {$target} dummy workers (already have {$existing->count()}). Seeding the rest…");

        for ($start = 1; $start <= $target; $start += 100) {
            $end = min($start + 99, $target);

            $userRows = [];
            for ($n = $start; $n <= $end; $n++) {
                if ($existing->has($n)) {
                    continue;
                }
                $userRows[$n] = [
                    'name' => $first[array_rand($first)].' '.$last[array_rand($last)],
                    'email' => "dummy.worker{$n}@".self::DOMAIN,
                    'password' => $password,
                    'role' => 'worker',
                    'email_verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (empty($userRows)) {
                continue;
            }

            DB::transaction(function () use ($userRows, $skillsPool, $locations, $wageTypes, $now) {
                DB::table('users')->insert(array_values($userRows));

                // Fetch the ids we just inserted, keyed by email.
                $ids = DB::table('users')
                    ->whereIn('email', array_column($userRows, 'email'))
                    ->pluck('id', 'email');

                $profileRows = [];
                foreach ($userRows as $u) {
                    $skills = collect($skillsPool)->shuffle()->take(random_int(1, 4))->values()->all();
                    [$city, $state] = $locations[array_rand($locations)];
                    $wageType = $wageTypes[array_rand($wageTypes)];

                    $profileRows[] = [
                        'user_id' => $ids[$u['email']],
                        'phone' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                        'skills' => json_encode($skills),
                        'experience_years' => random_int(0, 25),
                        'bio' => "Experienced {$skills[0]} available for work in {$city}.",
                        'expected_wage' => match ($wageType) {
                            'hourly' => random_int(80, 400),
                            'daily' => random_int(400, 1500),
                            default => random_int(12000, 45000),
                        },
                        'wage_type' => $wageType,
                        'city' => $city,
                        'state' => $state,
                        'available' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('worker_profiles')->insert($profileRows);
            });

            $this->command?->info("  …{$end}/{$target}");
        }

        $count = User::where('email', 'like', '%@'.self::DOMAIN)->count();
        $this->command?->info("Done. Total dummy workers: {$count}. Reindexing Typesense next.");

        // Keep the Typesense index in sync so they show in the directory.
        WorkerProfile::whereHas('user', fn ($q) => $q->where('email', 'like', '%@'.self::DOMAIN))
            ->searchable();
    }
}
