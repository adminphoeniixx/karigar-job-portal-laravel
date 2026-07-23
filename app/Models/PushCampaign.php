<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual push broadcast sent from the admin panel. Kept as a history log
 * so admins can see what was sent, to which audience, and the delivery result.
 *
 * @property int $id
 * @property int|null $created_by
 * @property string $title
 * @property string $body
 * @property string $audience
 * @property array<string, mixed>|null $target
 * @property string|null $url
 * @property int $recipients_count
 * @property int $sent_count
 * @property int $failed_count
 */
class PushCampaign extends Model
{
    protected $fillable = [
        'created_by', 'title', 'body', 'audience', 'target', 'url',
        'recipients_count', 'sent_count', 'failed_count',
    ];

    protected function casts(): array
    {
        return [
            'target' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
