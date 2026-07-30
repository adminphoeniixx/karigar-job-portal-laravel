<?php

namespace App\Models;

use App\Services\BunnyCdn;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $company_name
 * @property string|null $gstin
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $logo_path
 * @property string|null $about
 * @property int $contact_quota_bonus
 * @property Carbon|null $free_post_used_at
 * @property string|null $hiring_as
 * @property string|null $industry
 * @property string|null $company_size
 * @property array<int, string>|null $hiring_categories
 * @property int $credit_balance
 */
class EmployerProfile extends Model
{
    protected $fillable = [
        'company_name', 'gstin', 'phone', 'address', 'city', 'state',
        'latitude', 'longitude', 'logo_path', 'about', 'contact_quota_bonus',
        'free_post_used_at', 'hiring_as', 'industry', 'company_size',
        'hiring_categories', 'credit_balance',
    ];

    protected function casts(): array
    {
        return [
            'free_post_used_at' => 'datetime',
            'hiring_categories' => 'array',
            'credit_balance' => 'integer',
        ];
    }

    /** @var list<string> */
    protected $appends = ['logo_url'];

    /**
     * Public URL for the uploaded company logo, or null when none is set.
     *
     * @return Attribute<string|null, never>
     */
    protected function logoUrl(): Attribute
    {
        return Attribute::get(
            fn (): ?string => BunnyCdn::url($this->logo_path),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
