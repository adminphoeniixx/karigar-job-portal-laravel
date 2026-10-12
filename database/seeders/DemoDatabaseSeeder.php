<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 2,500 craft karigars for testing the Worker Database (Find karigars,
 * unlocks, database plans) on the shared live DB. Unlike the other demo
 * rows they are meant to show in search, to every employer.
 *
 * - Phones run 9000010001-9000012500; names end in "(Test)".
 * - Emails are the <phone>@phone.karigar placeholder an OTP sign-up gets, so
 *   job alerts never mail them and nothing bounces.
 * - No map pin: the server's hourly `karigars:locate --sync` puts each one at
 *   its city centre and indexes it in Typesense. Run that on the server to
 *   see them at once (Typesense cannot be reached from a dev machine).
 *
 * Nothing is random and existing phones are skipped, so it can run again.
 *
 *   php artisan db:seed --class=DemoDatabaseSeeder
 *
 * Remove them on the server (search first, then the rows):
 *   User::whereBetween('phone', ['9000010001', '9000012500'])->each(fn ($u) => $u->workerProfile?->unsearchable());
 *   User::whereBetween('phone', ['9000010001', '9000012500'])->delete();
 */
class DemoDatabaseSeeder extends Seeder
{
    public const FIRST_PHONE = 9000010001;

    public const TOTAL = 2500;

    /**
     * [first names, surnames, gender] per group, so names read like real ones.
     */
    private const NAMES = [
        [['Sunita', 'Rekha', 'Pooja', 'Geeta', 'Asha', 'Nirmala', 'Kamla', 'Savitri', 'Lalita', 'Sarita', 'Jyoti', 'Usha', 'Bhavna', 'Deepa', 'Hema', 'Radha', 'Meena', 'Kavita', 'Anita', 'Seema', 'Manju', 'Pushpa', 'Shanti', 'Vimla'],
            ['Devi', 'Kumari', 'Prajapati', 'Sharma', 'Verma', 'Yadav', 'Patel', 'Das', 'Nair', 'Reddy', 'Naik', 'Behera', 'Joshi', 'Mistry', 'Soni', 'Kushwaha', 'Maurya', 'Pal'], 'female'],
        [['Rajesh', 'Mahesh', 'Dinesh', 'Harish', 'Vinod', 'Anil', 'Prakash', 'Ravi', 'Shankar', 'Bhola', 'Kishan', 'Naresh', 'Arjun', 'Manoj', 'Sanjay', 'Dilip', 'Ganesh', 'Ramesh', 'Suresh', 'Mohan', 'Gopal', 'Raju', 'Pappu', 'Santosh', 'Vijay', 'Ashok'],
            ['Prajapati', 'Sharma', 'Verma', 'Yadav', 'Patel', 'Chauhan', 'Rathore', 'Solanki', 'Das', 'Pillai', 'Reddy', 'Naik', 'Mahato', 'Behera', 'Bhat', 'Joshi', 'Mistry', 'Kumar', 'Soni', 'Vishwakarma', 'Kushwaha', 'Maurya', 'Pal', 'Saini'], 'male'],
        [['Shabana', 'Rukhsana', 'Nasreen', 'Parveen', 'Zainab', 'Ayesha', 'Nazia', 'Tabassum', 'Salma', 'Farhana', 'Shahnaz', 'Rubina'],
            ['Begum', 'Khatoon', 'Ansari', 'Qureshi', 'Siddiqui', 'Shaikh', 'Bano'], 'female'],
        [['Imran', 'Salim', 'Iqbal', 'Irfan', 'Javed', 'Rafiq', 'Anwar', 'Yusuf', 'Abdul', 'Shakeel', 'Nadeem', 'Wasim'],
            ['Ansari', 'Qureshi', 'Siddiqui', 'Shaikh', 'Khan', 'Mir', 'Rashid', 'Hussain'], 'male'],
    ];

    /** Which name group each karigar draws from, in turn. */
    private const GROUP_CYCLE = [0, 1, 0, 1, 2, 3, 1, 0];

    /**
     * [city, state, regional language], spelled as in
     * resources/js/data/indianLocations.ts so the state and city filters find
     * them. Kanpur and Lucknow come twice: test employers sit there.
     */
    private const PLACES = [
        ['Kanpur', 'Uttar Pradesh', null], ['Lucknow', 'Uttar Pradesh', null], ['Varanasi', 'Uttar Pradesh', null],
        ['Agra', 'Uttar Pradesh', null], ['Prayagraj', 'Uttar Pradesh', null], ['Moradabad', 'Uttar Pradesh', null],
        ['Kanpur', 'Uttar Pradesh', null], ['Lucknow', 'Uttar Pradesh', null],
        ['Delhi', 'Delhi', null], ['Jaipur', 'Rajasthan', null], ['Jodhpur', 'Rajasthan', null], ['Udaipur', 'Rajasthan', null],
        ['Bikaner', 'Rajasthan', null], ['Ajmer', 'Rajasthan', null], ['Srinagar', 'Jammu and Kashmir', 'Kashmiri'],
        ['Jammu', 'Jammu and Kashmir', 'Dogri'], ['Guwahati', 'Assam', 'Assamese'], ['Jorhat', 'Assam', 'Assamese'],
        ['Hyderabad', 'Telangana', 'Telugu'], ['Warangal', 'Telangana', 'Telugu'], ['Bhubaneswar', 'Odisha', 'Odia'],
        ['Cuttack', 'Odisha', 'Odia'], ['Puri', 'Odisha', 'Odia'], ['Amritsar', 'Punjab', 'Punjabi'], ['Ludhiana', 'Punjab', 'Punjabi'],
        ['Kolkata', 'West Bengal', 'Bengali'], ['Howrah', 'West Bengal', 'Bengali'], ['Siliguri', 'West Bengal', 'Bengali'],
        ['Kochi', 'Kerala', 'Malayalam'], ['Thrissur', 'Kerala', 'Malayalam'], ['Ahmedabad', 'Gujarat', 'Gujarati'],
        ['Surat', 'Gujarat', 'Gujarati'], ['Rajkot', 'Gujarat', 'Gujarati'], ['Chennai', 'Tamil Nadu', 'Tamil'],
        ['Madurai', 'Tamil Nadu', 'Tamil'], ['Coimbatore', 'Tamil Nadu', 'Tamil'], ['Bengaluru', 'Karnataka', 'Kannada'],
        ['Mysuru', 'Karnataka', 'Kannada'], ['Indore', 'Madhya Pradesh', null], ['Bhopal', 'Madhya Pradesh', null],
        ['Gwalior', 'Madhya Pradesh', null], ['Patna', 'Bihar', null], ['Bhagalpur', 'Bihar', null], ['Mumbai', 'Maharashtra', 'Marathi'],
        ['Pune', 'Maharashtra', 'Marathi'], ['Kolhapur', 'Maharashtra', 'Marathi'], ['Ranchi', 'Jharkhand', null], ['Raipur', 'Chhattisgarh', null],
    ];

    private const EDUCATION = ['Below 10th', '10th Pass', '12th Pass', 'ITI / Diploma', 'Below 10th', '10th Pass', 'Graduate'];

    public function run(): void
    {
        $last = self::FIRST_PHONE + self::TOTAL - 1;
        $taken = User::whereBetween('phone', [(string) self::FIRST_PHONE, (string) $last])->pluck('phone')->flip();

        $password = Hash::make('password');
        $added = 0;

        foreach (array_chunk(self::karigars(), 250) as $chunk) {
            $chunk = array_values(array_filter($chunk, fn (array $k) => ! $taken->has($k['phone'])));

            if ($chunk === []) {
                continue;
            }

            DB::transaction(function () use ($chunk, $password) {
                DB::table('users')->insert(array_map(fn (array $k) => [
                    'name' => "{$k['name']} (Test)",
                    'email' => "{$k['phone']}@phone.karigar",
                    'phone' => $k['phone'],
                    'password' => $password,
                    'role' => UserRole::Worker->value,
                    'locale' => 'hi',
                    'created_at' => $k['joined'],
                    'updated_at' => $k['joined'],
                ], $chunk));

                $ids = DB::table('users')->whereIn('phone', array_column($chunk, 'phone'))->pluck('id', 'phone');

                // Query-builder inserts: no model events, so no alerts and no
                // search sync per row; karigars:locate --sync indexes them.
                DB::table('worker_profiles')->insert(array_map(fn (array $k) => [
                    'user_id' => $ids[$k['phone']],
                    'phone' => $k['phone'],
                    'skills' => json_encode($k['skills']),
                    'experience_years' => $k['years'],
                    'bio' => $k['bio'],
                    'expected_wage' => $k['wage'],
                    'wage_type' => 'monthly',
                    'city' => $k['city'],
                    'state' => $k['state'],
                    'gender' => $k['gender'],
                    'education' => $k['education'],
                    'spoken_languages' => json_encode($k['languages']),
                    'travel_radius_km' => $k['radius'],
                    'available' => $k['available'],
                    'screening_calls_opted_out' => false,
                    'created_at' => $k['joined'],
                    'updated_at' => $k['joined'],
                ], $chunk));
            });

            $added += count($chunk);
        }

        $this->command?->info("Added {$added} demo karigars (".($taken->count()).' were already there). Run `php artisan karigars:locate --sync` on the server to index them.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function karigars(): array
    {
        $crafts = CategorySeeder::CRAFTS;
        $used = array_fill(0, count(self::NAMES), 0);
        $karigars = [];

        for ($i = 0; $i < self::TOTAL; $i++) {
            $group = self::GROUP_CYCLE[$i % count(self::GROUP_CYCLE)];
            [$firsts, $lasts, $gender] = self::NAMES[$group];
            $n = $used[$group]++;
            $name = $firsts[$n % count($firsts)].' '.$lasts[($n + intdiv($n, count($firsts))) % count($lasts)];

            [$city, $state, $regional] = self::PLACES[($i * 7) % count(self::PLACES)];
            $skills = [$crafts[$i % count($crafts)]];

            if ($i % 3 === 0 && ($second = $crafts[($i * 5 + 3) % count($crafts)]) !== $skills[0]) {
                $skills[] = $second;
            }

            $years = ($i * 7) % 26;
            $languages = array_values(array_filter(['Hindi', $regional, $i % 6 === 0 ? 'English' : null]));

            $karigars[] = [
                'phone' => (string) (self::FIRST_PHONE + $i),
                'name' => $name,
                'gender' => $gender,
                'skills' => $skills,
                'city' => $city,
                'state' => $state,
                'years' => $years,
                // Monthly only, from ₹8,000 in steps of 500, more with experience.
                'wage' => 8000 + min(($years * 500) + (($i * 37) % 13) * 500, 24000),
                'education' => self::EDUCATION[($i * 3) % count(self::EDUCATION)],
                'languages' => $languages,
                'radius' => [10, 25, 50, 100][$i % 4],
                'available' => $i % 7 !== 6,
                'bio' => $years > 0
                    ? "{$skills[0]} karigar from {$city}, {$years} years of work."
                    : "{$skills[0]} karigar from {$city}, starting out.",
                // Joined over the last six months, so "newest" sorts mean something.
                'joined' => now()->subDays(($i * 11) % 180)->setTime(8 + $i % 12, ($i * 13) % 60),
            ];
        }

        return $karigars;
    }
}
