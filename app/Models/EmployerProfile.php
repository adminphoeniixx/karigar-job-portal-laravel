<?php

namespace App\Models;

use App\Services\BunnyCdn;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property \Illuminate\Support\Carbon|null $free_post_used_at
 */
class EmployerProfile extends Model
{
    protected $fillable = [
        'company_name', 'gstin', 'phone', 'address', 'city', 'state',
        'latitude', 'longitude', 'logo_path', 'about', 'contact_quota_bonus',
        'free_post_used_at',
    ];

    protected function casts(): array
    {
        return [
            'free_post_used_at' => 'datetime',
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
