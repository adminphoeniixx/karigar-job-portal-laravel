<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkerContactUnlock;
use App\Models\WorkerProfile;
use Illuminate\Database\Seeder;

/**
 * Demo rows for the test employer's "Database contacts" tab: 100 craft
 * karigars it unlocked from the Worker Database, about 40 this billing cycle
 * and the rest over the months before, every third one by its recruiter.
 * Nothing is random, so running it again updates the same rows.
 *
 * The karigars follow the other test accounts: names end in "(Test)", emails
 * are @karigar.test, phones run 9000000201-9000000300 in the test range rather
 * than the random numbers of the bulk dummy workers. They are kept out of
 * Typesense, so real employers never find them in search.
 *
 *   php artisan db:seed --class=DemoContactsSeeder
 */
class DemoContactsSeeder extends Seeder
{
    private const EMPLOYER_PHONE = '9000000001';

    private const FIRST_PHONE = 9000000201;

    private const TOTAL = 100;

    /**
     * Written out by hand: name, skills, city, state, years, wage, wage type,
     * days since unlocked.
     */
    private const FEATURED = [
        ['Meena Devi', ['Weaving', 'Bunai / Knitting'], 'Jaipur', 'Rajasthan', 8, 750, 'daily', 1],
        ['Salma Khatoon', ['Kadhai / Embroidery'], 'Lucknow', 'Uttar Pradesh', 12, 900, 'daily', 2],
        ['Ramesh Prajapati', ['Pottery / Handmade Pots', 'Clay Work'], 'Varanasi', 'Uttar Pradesh', 10, 850, 'daily', 4],
        ['Abdul Rashid', ['Wood Carving'], 'Srinagar', 'Jammu and Kashmir', 15, 1200, 'daily', 6],
        ['Rupa Das', ['Basket / Cane Work'], 'Guwahati', 'Assam', 6, 600, 'daily', 9],
        ['Farhana Begum', ['Tailoring', 'Kadhai / Embroidery'], 'Hyderabad', 'Telangana', 5, 18000, 'monthly', 13],
        ['Gopal Das', ['Painting & Coloring', 'Traditional / Artisan Crafts'], 'Bhubaneswar', 'Odisha', 10, 1000, 'daily', 20],
        ['Kavita Sharma', ['Crochet', 'Bunai / Knitting'], 'Ludhiana', 'Punjab', 4, 550, 'daily', 35],
        ['Suresh Soni', ['Handmade Jewellery'], 'Jaipur', 'Rajasthan', 9, 1100, 'daily', 42],
        ['Anita Kumari', ['Handmade Bags / Accessories'], 'Kolkata', 'West Bengal', 3, 15000, 'monthly', 50],
        ['Mohan Lal', ['Decorative Handicrafts', 'Wood Carving'], 'Moradabad', 'Uttar Pradesh', 20, 1300, 'daily', 58],
        ['Priya Nair', ['Weaving'], 'Kochi', 'Kerala', 7, 800, 'daily', 70],
    ];

    /**
     * First names and surnames that go together, so generated names read
     * like real ones: [first names, surnames] per group.
     */
    private const NAMES = [
        [['Sunita', 'Rekha', 'Pooja', 'Geeta', 'Asha', 'Nirmala', 'Kamla', 'Savitri', 'Lalita', 'Sarita', 'Jyoti', 'Usha', 'Bhavna', 'Deepa', 'Hema', 'Radha'],
            ['Devi', 'Kumari', 'Prajapati', 'Sharma', 'Verma', 'Yadav', 'Patel', 'Das', 'Nair', 'Reddy', 'Naik', 'Behera', 'Joshi', 'Mistry']],
        [['Rajesh', 'Mahesh', 'Dinesh', 'Harish', 'Vinod', 'Anil', 'Prakash', 'Ravi', 'Shankar', 'Bhola', 'Kishan', 'Naresh', 'Arjun', 'Manoj', 'Sanjay', 'Dilip', 'Ganesh'],
            ['Prajapati', 'Sharma', 'Verma', 'Yadav', 'Patel', 'Chauhan', 'Rathore', 'Solanki', 'Das', 'Pillai', 'Reddy', 'Naik', 'Mahato', 'Behera', 'Bhat', 'Joshi', 'Mistry', 'Kumar']],
        [['Shabana', 'Rukhsana', 'Nasreen', 'Parveen', 'Zainab', 'Ayesha', 'Nazia', 'Tabassum'],
            ['Begum', 'Khatoon', 'Ansari', 'Qureshi', 'Siddiqui', 'Shaikh']],
        [['Imran', 'Salim', 'Iqbal', 'Irfan', 'Javed', 'Rafiq', 'Anwar', 'Yusuf'],
            ['Ansari', 'Qureshi', 'Siddiqui', 'Shaikh', 'Khan', 'Mir']],
    ];

    /**
     * Which name group each generated karigar draws from, in turn.
     */
    private const GROUP_CYCLE = [0, 1, 0, 1, 2, 3];

    /**
     * Craft towns, spelled as in resources/js/data/indianLocations.ts so the
     * state and city filters find them.
     */
    private const PLACES = [
        ['Jodhpur', 'Rajasthan'], ['Udaipur', 'Rajasthan'], ['Bikaner', 'Rajasthan'], ['Ajmer', 'Rajasthan'],
        ['Agra', 'Uttar Pradesh'], ['Kanpur', 'Uttar Pradesh'], ['Prayagraj', 'Uttar Pradesh'],
        ['Jammu', 'Jammu and Kashmir'], ['Jorhat', 'Assam'], ['Warangal', 'Telangana'],
        ['Cuttack', 'Odisha'], ['Puri', 'Odisha'], ['Amritsar', 'Punjab'], ['Patiala', 'Punjab'],
        ['Howrah', 'West Bengal'], ['Siliguri', 'West Bengal'], ['Thiruvananthapuram', 'Kerala'], ['Thrissur', 'Kerala'],
        ['Ahmedabad', 'Gujarat'], ['Surat', 'Gujarat'], ['Rajkot', 'Gujarat'], ['Bhavnagar', 'Gujarat'],
        ['Chennai', 'Tamil Nadu'], ['Madurai', 'Tamil Nadu'], ['Coimbatore', 'Tamil Nadu'],
        ['Bengaluru', 'Karnataka'], ['Mysuru', 'Karnataka'], ['Indore', 'Madhya Pradesh'], ['Bhopal', 'Madhya Pradesh'],
        ['Gwalior', 'Madhya Pradesh'], ['Patna', 'Bihar'], ['Bhagalpur', 'Bihar'], ['Pune', 'Maharashtra'],
        ['Kolhapur', 'Maharashtra'], ['Shimla', 'Himachal Pradesh'], ['Ranchi', 'Jharkhand'], ['Raipur', 'Chhattisgarh'],
    ];

    public function run(): void
    {
        $employer = User::where('phone', self::EMPLOYER_PHONE)->first();

        if ($employer === null) {
            $this->command?->warn('No test employer with phone '.self::EMPLOYER_PHONE.'; nothing seeded.');

            return;
        }

        // The recruiter, when the account has one, unlocks every third karigar.
        $recruiterId = $employer->teamMembers()->value('user_id');

        WorkerProfile::withoutSyncingToSearch(function () use ($employer, $recruiterId) {
            foreach (self::karigars() as $i => $k) {
                $phone = (string) (self::FIRST_PHONE + $i);

                $worker = User::updateOrCreate(
                    ['email' => "demo.karigar{$phone}@karigar.test"],
                    ['name' => "{$k['name']} (Test)", 'phone' => $phone, 'password' => 'password', 'role' => UserRole::Worker->value],
                );

                $worker->workerProfile()->updateOrCreate([], [
                    'phone' => $phone,
                    'skills' => $k['skills'],
                    'city' => $k['city'],
                    'state' => $k['state'],
                    'experience_years' => $k['years'],
                    'expected_wage' => $k['wage'],
                    'wage_type' => $k['wage_type'],
                    'available' => $k['available'],
                    'bio' => "{$k['skills'][0]} karigar from {$k['city']}, {$k['years']} years of work.",
                ]);

                $unlock = WorkerContactUnlock::firstOrNew(['employer_id' => $employer->id, 'worker_id' => $worker->id]);
                $unlock->source = WorkerContactUnlock::SOURCE_DIRECTORY;
                $unlock->pool = WorkerContactUnlock::POOL_JOB;
                $unlock->unlocked_by = $recruiterId && $i % 3 === 2 ? $recruiterId : $employer->id;
                $unlock->created_at = $unlock->updated_at = now()->subDays($k['days_ago'])->setTime(9 + $i % 10, (13 * $i) % 60);
                $unlock->save();
            }
        });

        $this->command?->info('Seeded '.self::TOTAL.' database contacts for '.$employer->name.'.');
    }

    /**
     * The hand-written karigars, then generated ones up to {@see TOTAL}.
     *
     * @return list<array{name: string, skills: list<string>, city: string, state: string, years: int, wage: int, wage_type: string, days_ago: int, available: bool}>
     */
    public static function karigars(): array
    {
        $karigars = array_map(fn (array $k) => [
            'name' => $k[0], 'skills' => $k[1], 'city' => $k[2], 'state' => $k[3], 'years' => $k[4],
            'wage' => $k[5], 'wage_type' => $k[6], 'days_ago' => $k[7], 'available' => true,
        ], self::FEATURED);

        $taken = array_column($karigars, 'name');
        $used = array_fill(0, count(self::NAMES), 0);
        $crafts = CategorySeeder::CRAFTS;

        for ($i = 0; count($karigars) < self::TOTAL; $i++) {
            $group = self::GROUP_CYCLE[$i % count(self::GROUP_CYCLE)];
            [$firsts, $lasts] = self::NAMES[$group];
            $n = $used[$group]++;
            $name = $firsts[$n % count($firsts)].' '.$lasts[($n + intdiv($n, count($firsts))) % count($lasts)];

            if (in_array($name, $taken, true)) {
                continue;
            }

            $taken[] = $name;
            [$city, $state] = self::PLACES[($i * 5) % count(self::PLACES)];
            $skills = [$crafts[$i % count($crafts)]];

            if ($i % 3 === 0 && ($second = $crafts[($i * 5 + 3) % count($crafts)]) !== $skills[0]) {
                $skills[] = $second;
            }

            $monthly = $i % 4 === 3;
            $generated = count($karigars) - count(self::FEATURED);

            $karigars[] = [
                'name' => $name,
                'skills' => $skills,
                'city' => $city,
                'state' => $state,
                'years' => 1 + ($i * 7) % 25,
                'wage' => $monthly ? 9000 + (($i * 13) % 17) * 1000 : 400 + (($i * 37) % 23) * 50,
                'wage_type' => $monthly ? 'monthly' : 'daily',
                // The first 33 fall in the last four weeks, the rest one to four months back.
                'days_ago' => $generated < 33 ? 1 + $generated % 26 : 30 + (($generated - 33) * 7) % 91,
                'available' => $i % 5 !== 4,
            ];
        }

        return $karigars;
    }
}
