<?php

namespace App\Models;

use App\Services\AiCredits;
use App\Services\Billing\Gst;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A recharge the admin sells: a price before GST for a number of AI call
 * credits, sometimes with bonus credits on top.
 *
 * @property int $id
 * @property string $name
 * @property string $price
 * @property int $credits
 * @property int $bonus_credits
 * @property bool $is_active
 * @property bool $is_popular
 * @property int $sort_order
 */
class AiCreditPack extends Model
{
    protected $fillable = ['name', 'price', 'credits', 'bonus_credits', 'is_active', 'is_popular', 'sort_order'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'credits' => 'integer',
            'bonus_credits' => 'integer',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForSale(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('price');
    }

    public function totalCredits(): int
    {
        return $this->credits + $this->bonus_credits;
    }

    /**
     * The pack as both apps show it.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        $price = (float) $this->price;
        $gross = Gst::gross($price);

        return [
            'id' => $this->id,
            'name' => $this->name,
            // Before GST; `price_with_gst` is what the employer pays.
            'price' => $price,
            'gst_amount' => round($gross - $price, 2),
            'price_with_gst' => $gross,
            'credits' => $this->credits,
            'bonus_credits' => $this->bonus_credits,
            'total_credits' => $this->totalCredits(),
            // Roughly how many call minutes the pack buys at today's rate.
            'minutes' => intdiv($this->totalCredits(), max(1, AiCredits::perMinute())),
            'popular' => $this->is_popular,
        ];
    }
}
