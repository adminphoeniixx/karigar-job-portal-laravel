<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Plumbing', 'Electrician', 'Carpenter', 'Painter', 'Welder', 'Mason',
            'Mechanic', 'Cleaning', 'Gardening', 'Cooking', 'Driver', 'Security Guard',
            'AC & Appliance Repair', 'Tailoring', 'Beautician', 'Labour / Helper',
        ];

        foreach ($categories as $name) {
            Category::updateOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'is_active' => true]);
        }
    }
}
