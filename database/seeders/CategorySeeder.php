<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Super Karigar lists handmade crafts only — the old general-trades set
     * (plumbing, electrician, driver…) is deactivated rather than deleted, so
     * job_listings.category, which stores the name as plain text, keeps
     * pointing at something readable on jobs posted before the switch.
     *
     * The array order is the order the landing grid renders in (stored as
     * `sort_order`), and each name's slug must match a photo in
     * public/images/categories/ or the tile shows an empty frame.
     */
    public const CRAFTS = [
        'Bunai / Knitting',
        'Weaving',
        'Kadhai / Embroidery',
        'Painting & Coloring',
        'Pottery / Handmade Pots',
        'Wood Carving',
        'Basket / Cane Work',
        'Tailoring',
        'Decorative Handicrafts',
        'Clay Work',
        'Traditional / Artisan Crafts',
        'Crochet',
        'Handmade Jewellery',
        'Handmade Bags / Accessories',
    ];

    public function run(): void
    {
        foreach (self::CRAFTS as $position => $name) {
            Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true, 'sort_order' => $position],
            );
        }

        Category::query()
            ->whereNotIn('slug', array_map(Str::slug(...), self::CRAFTS))
            ->update(['is_active' => false]);
    }
}
