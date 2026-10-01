<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the job form now asks for: an experience range, the shift's hours,
     * and who picks up when a karigar calls; plus the job a repost was copied
     * from. Each craft category gets the skills its jobs are posted with, so
     * the form can suggest them once the category is chosen.
     */
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->unsignedSmallInteger('experience_max')->nullable()->after('experience_min');
            // "HH:MM", 24-hour.
            $table->string('shift_start', 5)->nullable()->after('shift');
            $table->string('shift_end', 5)->nullable()->after('shift_start');
            $table->string('contact_name', 100)->nullable()->after('contact_phone');
            $table->string('contact_designation', 100)->nullable()->after('contact_name');
            $table->foreignId('reposted_from_id')->nullable()->constrained('job_listings')->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->json('skills')->nullable()->after('name');
        });

        foreach (self::SKILLS as $slug => $skills) {
            DB::table('categories')->where('slug', $slug)->whereNull('skills')->update(['skills' => json_encode($skills)]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('skills');
        });

        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reposted_from_id');
            $table->dropColumn(['experience_max', 'shift_start', 'shift_end', 'contact_name', 'contact_designation']);
        });
    }

    /**
     * Starting skills per craft (by slug); the admin edits them after this.
     * Mirrors CategorySeeder::SKILLS.
     */
    private const SKILLS = [
        'bunai-knitting' => ['Hand knitting', 'Machine knitting', 'Sweater making', 'Woollen caps & mufflers', 'Pattern knitting', 'Finishing & stitching'],
        'weaving' => ['Handloom weaving', 'Powerloom operation', 'Warping', 'Dyeing', 'Carpet weaving', 'Saree weaving', 'Jacquard weaving'],
        'kadhai-embroidery' => ['Zardozi', 'Chikankari', 'Aari work', 'Hand embroidery', 'Machine embroidery', 'Phulkari', 'Mirror work', 'Sequin work'],
        'painting-coloring' => ['Madhubani', 'Warli', 'Pattachitra', 'Fabric painting', 'Block printing', 'Wall art', 'Colour mixing'],
        'pottery-handmade-pots' => ['Wheel throwing', 'Terracotta', 'Glazing', 'Kiln firing', 'Blue pottery', 'Pot finishing'],
        'wood-carving' => ['Hand carving', 'Furniture carving', 'Inlay work', 'Lacquer work', 'Wood polishing', 'Wooden toy making'],
        'basket-cane-work' => ['Cane weaving', 'Bamboo craft', 'Basket making', 'Chair caning', 'Jute craft'],
        'tailoring' => ['Cutting', 'Stitching', 'Blouse stitching', 'Kurti / suit stitching', 'Alteration', 'Pattern making', 'Machine operation'],
        'decorative-handicrafts' => ['Brass work', 'Metal craft', 'Home decor', 'Candle making', 'Glass painting', 'Paper craft'],
        'clay-work' => ['Idol making', 'Clay modelling', 'Terracotta', 'Mould making', 'Clay toys'],
        'traditional-artisan-crafts' => ['Block printing', 'Bandhani / tie-dye', 'Kantha stitch', 'Leather craft', 'Stone carving', 'Dokra metal casting'],
        'crochet' => ['Crochet toys', 'Crochet bags', 'Doilies & lace', 'Crochet garments', 'Pattern reading'],
        'handmade-jewellery' => ['Beadwork', 'Thread jewellery', 'Kundan work', 'Silver work', 'Terracotta jewellery', 'Stone setting'],
        'handmade-bags-accessories' => ['Jute bags', 'Leather goods', 'Embroidered bags', 'Potli bags', 'Bag stitching', 'Finishing'],
    ];
};
