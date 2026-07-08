<?php

namespace Database\Seeders;

use App\Enums\JobStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoJobsSeeder extends Seeder
{
    /**
     * Seed a spread of realistic active jobs across categories, states and
     * cities so Typesense search & filtering can be exercised end to end.
     */
    public function run(): void
    {
        $employers = $this->employers();

        // category => [title templates, skills, [wageMin, wageMax], wageType]
        $catalog = [
            'Plumbing' => [['Plumber needed', 'Experienced plumber for site', 'Bathroom fitting plumber'], ['Plumbing', 'Pipe Fitting'], [600, 1000], 'daily'],
            'Electrician' => [['Electrician for house wiring', 'Industrial electrician', 'Electrician — repairs'], ['Electrical Wiring', 'Electrician'], [700, 1200], 'daily'],
            'Carpenter' => [['Carpenter for furniture', 'Wood work carpenter', 'Modular kitchen carpenter'], ['Carpentry', 'Woodwork'], [700, 1100], 'daily'],
            'Painter' => [['House painting job', 'Wall putty & painter', 'Commercial painter needed'], ['Painting', 'Wall Putty'], [550, 900], 'daily'],
            'Welder' => [['Welder for fabrication', 'Gas welder required', 'Structural welder'], ['Welding', 'Fabrication'], [800, 1300], 'daily'],
            'Mason' => [['Mason for construction', 'Brick work mason', 'Plastering mason'], ['Masonry', 'Plastering'], [650, 1050], 'daily'],
            'Mechanic' => [['Two-wheeler mechanic', 'Car mechanic needed', 'Garage mechanic'], ['Mechanic', 'Two-Wheeler Repair'], [12000, 22000], 'monthly'],
            'Cleaning' => [['House cleaning staff', 'Office cleaning job', 'Deep cleaning helper'], ['Cleaning', 'Housekeeping'], [10000, 16000], 'monthly'],
            'AC & Appliance Repair' => [['AC repair technician', 'Appliance repair engineer', 'Fridge & AC technician'], ['AC Repair', 'Appliance Repair'], [15000, 25000], 'monthly'],
            'Driver' => [['Driver required', 'Personal car driver', 'Commercial vehicle driver'], ['Driving', 'Heavy Vehicle Driving'], [14000, 24000], 'monthly'],
            'Gardening' => [['Gardener needed', 'Landscaping helper', 'Society gardener'], ['Gardening', 'Landscaping'], [9000, 15000], 'monthly'],
            'Cooking' => [['Cook for household', 'Restaurant cook needed', 'Tiffin service cook'], ['Cooking', 'Housekeeping'], [12000, 20000], 'monthly'],
            'Security Guard' => [['Security guard', 'Night security guard', 'Society security guard'], ['Security'], [11000, 18000], 'monthly'],
            'Tailoring' => [['Tailor needed', 'Boutique tailor', 'Garment stitching tailor'], ['Tailoring', 'Stitching'], [12000, 20000], 'monthly'],
            'Beautician' => [['Beautician for salon', 'Bridal makeup artist', 'Salon hair stylist'], ['Beautician', 'Hair Styling'], [13000, 22000], 'monthly'],
            'Labour / Helper' => [['Construction helper', 'Loading unloading labour', 'General helper'], ['Helper', 'Loading / Unloading'], [450, 750], 'daily'],
        ];

        // [city, state, lat, lng]
        $places = [
            ['Jaipur', 'Rajasthan', 26.9124, 75.7873], ['Jodhpur', 'Rajasthan', 26.2389, 73.0243],
            ['Mumbai', 'Maharashtra', 19.0760, 72.8777], ['Pune', 'Maharashtra', 18.5204, 73.8567],
            ['Nagpur', 'Maharashtra', 21.1458, 79.0882], ['Delhi', 'Delhi', 28.6139, 77.2090],
            ['Bengaluru', 'Karnataka', 12.9716, 77.5946], ['Mysuru', 'Karnataka', 12.2958, 76.6394],
            ['Hyderabad', 'Telangana', 17.3850, 78.4867], ['Chennai', 'Tamil Nadu', 13.0827, 80.2707],
            ['Coimbatore', 'Tamil Nadu', 11.0168, 76.9558], ['Ahmedabad', 'Gujarat', 23.0225, 72.5714],
            ['Surat', 'Gujarat', 21.1702, 72.8311], ['Lucknow', 'Uttar Pradesh', 26.8467, 80.9462],
            ['Kanpur', 'Uttar Pradesh', 26.4499, 80.3319], ['Kolkata', 'West Bengal', 22.5726, 88.3639],
            ['Patna', 'Bihar', 25.5941, 85.1376], ['Indore', 'Madhya Pradesh', 22.7196, 75.8577],
            ['Bhopal', 'Madhya Pradesh', 23.2599, 77.4126], ['Chandigarh', 'Chandigarh', 30.7333, 76.7794],
            ['Kochi', 'Kerala', 9.9312, 76.2673],
        ];

        $categories = array_keys($catalog);
        $created = 0;

        // ~4 jobs per category, each in a different place → good spread.
        foreach ($categories as $ci => $category) {
            [$titles, $skills, $wage, $wageType] = $catalog[$category];

            for ($i = 0; $i < 4; $i++) {
                $place = $places[($ci * 4 + $i) % count($places)];
                $employer = $employers[($created) % count($employers)];
                $min = $wage[0] + ($i * 25);
                $max = $wage[1] + ($i * 25);

                $employer->jobListings()->create([
                    'title' => $titles[$i % count($titles)].' — '.$place[0],
                    'description' => "We are hiring for {$category} work in {$place[0]}, {$place[1]}. ".
                        'Reliable, punctual candidates preferred. Immediate joining. Contact after applying.',
                    'category' => $category,
                    'skills' => $skills,
                    'wage_min' => $min,
                    'wage_max' => $max,
                    'wage_type' => $wageType,
                    'city' => $place[0],
                    'state' => $place[1],
                    'latitude' => $place[2],
                    'longitude' => $place[3],
                    'vacancies' => 1 + ($i % 5),
                    'status' => JobStatus::Active->value,
                ]);
                $created++;
            }
        }

        $this->command?->info("Seeded {$created} demo jobs across ".count($categories).' categories and '.count($places).' cities.');
    }

    /**
     * A handful of employer companies to post the jobs.
     *
     * @return array<int, User>
     */
    private function employers(): array
    {
        $companies = [
            ['email' => 'employer@karigar.test', 'name' => 'BuildRight Constructions'],
            ['email' => 'sharma.builders@karigar.test', 'name' => 'Sharma Builders'],
            ['email' => 'metro.services@karigar.test', 'name' => 'Metro Home Services'],
            ['email' => 'skyline.infra@karigar.test', 'name' => 'Skyline Infra'],
            ['email' => 'quickfix@karigar.test', 'name' => 'QuickFix Solutions'],
        ];

        return collect($companies)->map(function ($c) {
            $user = User::firstOrCreate(
                ['email' => $c['email']],
                ['name' => $c['name'], 'password' => 'password', 'role' => UserRole::Employer->value, 'email_verified_at' => now()],
            );
            $user->employerProfile()->firstOrCreate([], ['company_name' => $c['name']]);

            return $user;
        })->all();
    }
}
