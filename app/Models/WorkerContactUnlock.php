<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A karigar's contact an employer account unlocked from the Worker Database.
 *
 * @property int $id
 * @property int $employer_id
 * @property int $worker_id
 * @property int|null $unlocked_by
 */
class WorkerContactUnlock extends Model
{
    protected $fillable = ['employer_id', 'worker_id', 'unlocked_by'];

    public function employer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employer_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
