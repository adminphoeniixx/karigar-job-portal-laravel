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

    /**
     * Starting skills per craft, suggested on the job form once the category
     * is picked. Set only where a category has none, so the admin's edits stay.
     *
     * @var array<string, list<string>>
     */
    public const SKILLS = [
        'Bunai / Knitting' => ['Hand knitting', 'Machine knitting', 'Sweater making', 'Woollen caps & mufflers', 'Pattern knitting', 'Finishing & stitching'],
        'Weaving' => ['Handloom weaving', 'Powerloom operation', 'Warping', 'Dyeing', 'Carpet weaving', 'Saree weaving', 'Jacquard weaving'],
        'Kadhai / Embroidery' => ['Zardozi', 'Chikankari', 'Aari work', 'Hand embroidery', 'Machine embroidery', 'Phulkari', 'Mirror work', 'Sequin work'],
        'Painting & Coloring' => ['Madhubani', 'Warli', 'Pattachitra', 'Fabric painting', 'Block printing', 'Wall art', 'Colour mixing'],
        'Pottery / Handmade Pots' => ['Wheel throwing', 'Terracotta', 'Glazing', 'Kiln firing', 'Blue pottery', 'Pot finishing'],
        'Wood Carving' => ['Hand carving', 'Furniture carving', 'Inlay work', 'Lacquer work', 'Wood polishing', 'Wooden toy making'],
        'Basket / Cane Work' => ['Cane weaving', 'Bamboo craft', 'Basket making', 'Chair caning', 'Jute craft'],
        'Tailoring' => ['Cutting', 'Stitching', 'Blouse stitching', 'Kurti / suit stitching', 'Alteration', 'Pattern making', 'Machine operation'],
        'Decorative Handicrafts' => ['Brass work', 'Metal craft', 'Home decor', 'Candle making', 'Glass painting', 'Paper craft'],
        'Clay Work' => ['Idol making', 'Clay modelling', 'Terracotta', 'Mould making', 'Clay toys'],
        'Traditional / Artisan Crafts' => ['Block printing', 'Bandhani / tie-dye', 'Kantha stitch', 'Leather craft', 'Stone carving', 'Dokra metal casting'],
        'Crochet' => ['Crochet toys', 'Crochet bags', 'Doilies & lace', 'Crochet garments', 'Pattern reading'],
        'Handmade Jewellery' => ['Beadwork', 'Thread jewellery', 'Kundan work', 'Silver work', 'Terracotta jewellery', 'Stone setting'],
        'Handmade Bags / Accessories' => ['Jute bags', 'Leather goods', 'Embroidered bags', 'Potli bags', 'Bag stitching', 'Finishing'],
    ];

    public function run(): void
    {
        foreach (self::CRAFTS as $position => $name) {
            $category = Category::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true, 'sort_order' => $position],
            );

            if (empty($category->skills)) {
                $category->update(['skills' => self::SKILLS[$name] ?? []]);
            }
        }

        Category::query()
            ->whereNotIn('slug', array_map(Str::slug(...), self::CRAFTS))
            ->update(['is_active' => false]);
    }
}
