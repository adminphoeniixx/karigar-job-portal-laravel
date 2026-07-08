<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $job_listing_id
 * @property int $worker_id
 * @property string|null $cover_note
 * @property string|null $expected_wage
 * @property ApplicationStatus $status
 * @property bool $contact_unlocked
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class JobApplication extends Model
{
    protected $fillable = [
        'job_listing_id', 'worker_id', 'cover_note', 'expected_wage', 'status', 'contact_unlocked',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'contact_unlocked' => 'boolean',
            'expected_wage' => 'decimal:2',
        ];
    }

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

    /**
     * @return HasOne<Escrow, $this>
     */
    public function escrow(): HasOne
    {
        return $this->hasOne(Escrow::class);
    }
}
