<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff account working under an employer's (the owner's) company.
 *
 * @property int $id
 * @property int $employer_id
 * @property int $user_id
 * @property string $role manager|recruiter
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TeamMember extends Model
{
    public const ROLES = ['manager', 'recruiter'];

    protected $fillable = ['employer_id', 'user_id', 'role'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
