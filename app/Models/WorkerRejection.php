<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A karigar an employer turned down in the Worker Database, without
 * unlocking their number. They leave the employer's searches and matches,
 * and sit under "Rejected" until taken back.
 *
 * @property int $id
 * @property int $employer_id
 * @property int $worker_id
 * @property int|null $rejected_by
 */
class WorkerRejection extends Model
{
    protected $fillable = ['employer_id', 'worker_id', 'rejected_by'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
