<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single device's FCM registration token, used to deliver push
 * notifications to that device.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string|null $platform
 * @property Carbon|null $last_used_at
 */
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
