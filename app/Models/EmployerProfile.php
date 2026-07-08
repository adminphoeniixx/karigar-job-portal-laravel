<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $company_name
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $city
 * @property string|null $state
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $logo_path
 * @property string|null $about
 * @property int $contact_quota_bonus
 */
class EmployerProfile extends Model
{
    protected $fillable = [
        'company_name', 'phone', 'address', 'city', 'state',
        'latitude', 'longitude', 'logo_path', 'about', 'contact_quota_bonus',
    ];

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
            fn (): ?string => $this->logo_path ? asset('storage/'.$this->logo_path) : null,
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
