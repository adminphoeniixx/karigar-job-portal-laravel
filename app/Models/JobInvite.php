<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employer inviting a matched worker to apply to one of their jobs.
 *
 * @property int $id
 * @property int $job_listing_id
 * @property int $worker_id
 * @property int $employer_id
 * @property string|null $message
 */
class JobInvite extends Model
{
    protected $fillable = ['job_listing_id', 'worker_id', 'employer_id', 'message'];

    /**
     * @return BelongsTo<JobListing, $this>
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_listing_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
