<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Job titles ("job profiles") per category: the job form offers them as a
 * dropdown, and a title picked or typed suggests its category and skills.
 * Admin-editable like the skills; these are the starting lists, written only
 * where a category has none yet.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const TITLES = [
        'bunai-knitting' => ['Knitter', 'Hand Knitter', 'Knitting Machine Operator', 'Sweater Maker'],
        'weaving' => ['Weaver', 'Handloom Weaver', 'Powerloom Operator', 'Carpet Weaver', 'Saree Weaver', 'Bunkar'],
        'kadhai-embroidery' => ['Embroiderer', 'Hand Embroiderer', 'Zardozi Karigar', 'Aari Karigar', 'Chikankari Karigar', 'Embroidery Machine Operator'],
        'painting-coloring' => ['Painting Artist', 'Fabric Painter', 'Block Printer', 'Madhubani Artist', 'Wall Artist'],
        'pottery-handmade-pots' => ['Potter', 'Kumhar', 'Pottery Artisan', 'Glaze Artist'],
        'wood-carving' => ['Wood Carver', 'Furniture Carver', 'Wood Polisher', 'Inlay Artisan'],
        'basket-cane-work' => ['Cane Weaver', 'Basket Maker', 'Bamboo Craftsman'],
        'tailoring' => ['Tailor', 'Darzi', 'Ladies Tailor', 'Gents Tailor', 'Blouse Tailor', 'Cutting Master', 'Alteration Tailor', 'Sewing Machine Operator'],
        'decorative-handicrafts' => ['Handicraft Artisan', 'Brass Craftsman', 'Metal Craftsman', 'Candle Maker'],
        'clay-work' => ['Idol Maker', 'Murti Karigar', 'Clay Modeller'],
        'traditional-artisan-crafts' => ['Artisan', 'Block Print Karigar', 'Bandhani Karigar', 'Leather Craftsman', 'Stone Carver'],
        'crochet' => ['Crochet Artisan', 'Crochet Maker'],
        'handmade-jewellery' => ['Jewellery Maker', 'Beadwork Artisan', 'Kundan Karigar', 'Silversmith'],
        'handmade-bags-accessories' => ['Bag Maker', 'Bag Stitcher', 'Leather Goods Maker', 'Jute Bag Maker'],
        'electrician' => ['Electrician', 'House Wiring Electrician', 'Industrial Electrician', 'Electrician Helper', 'Solar Technician'],
        'plumbing' => ['Plumber', 'Plumber Helper'],
        'carpenter' => ['Carpenter', 'Furniture Carpenter', 'Carpenter Mistri'],
        'mason' => ['Mason', 'Raj Mistri'],
        'welder' => ['Welder', 'Fabricator'],
        'painter' => ['House Painter', 'Wall Painter'],
        'driver' => ['Driver', 'Delivery Driver', 'Truck Driver'],
        'security-guard' => ['Security Guard', 'Watchman'],
        'cleaning' => ['Housekeeping Staff', 'Cleaner'],
        'cooking' => ['Cook', 'Helper Cook', 'Chef'],
        'gardening' => ['Gardener', 'Mali'],
        'mechanic' => ['Mechanic', 'Two-wheeler Mechanic'],
        'ac-appliance-repair' => ['AC Technician', 'Appliance Repair Technician'],
        'beautician' => ['Beautician', 'Hair Stylist'],
        'labour-helper' => ['Helper', 'Labour', 'Loader'],
    ];

    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->json('job_titles')->nullable()->after('skills');
        });

        foreach (self::TITLES as $slug => $titles) {
            DB::table('categories')->where('slug', $slug)->whereNull('job_titles')->update(['job_titles' => json_encode($titles)]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('job_titles');
        });
    }
};
