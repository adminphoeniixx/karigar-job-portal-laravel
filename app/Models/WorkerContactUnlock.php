<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A karigar's contact an employer account unlocked, recorded once per karigar
 * the first time it happens, from the Worker Database or from an applicant.
 *
 * @property int $id
 * @property int $employer_id
 * @property int $worker_id
 * @property string $source
 * @property string|null $pool
 * @property int|null $unlocked_by
 */
class WorkerContactUnlock extends Model
{
    public const SOURCE_DIRECTORY = 'directory';

    public const SOURCE_APPLICATION = 'application';

    /** Paid from the job plan's unlock allowance. */
    public const POOL_JOB = 'job';

    /** Paid from the database plan's unlock allowance. */
    public const POOL_DATABASE = 'database';

    /** Paid with a purchased credit. Credits are no longer sold; only old rows carry it. */
    public const POOL_CREDIT = 'credit';

    protected $fillable = ['employer_id', 'worker_id', 'source', 'pool', 'unlocked_by'];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }
}
