<?php

namespace App\Jobs;

use App\Models\JobApplication;
use App\Services\Screening\ScreeningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Places one automated screening call to a shortlisted applicant.
 *
 * Dispatched by ScoreApplication after an auto-shortlist (when the admin has
 * switched screening calls on), by the employer's manual "Call & schedule"
 * action, and by ScreeningService itself for retries after a no-answer.
 *
 * Runs on the queue because dialling talks to a telephony provider, and holds
 * itself back to the permitted daytime calling window — a call that comes due
 * at midnight waits for morning instead of waking a worker up.
 */
class PlaceScreeningCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    /** How often a call may be put back to wait for calling hours. */
    private const MAX_HOLDS = 1;

    /**
     * @param  int  $holds  How many times this job has already been put back to
     *                      wait for calling hours. Bounded so that a queue
     *                      driver which ignores delays (`sync`) cannot spin.
     */
    public function __construct(public int $applicationId, public int $attempt = 1, public int $holds = 0) {}

    public function handle(ScreeningService $screening): void
    {
        $application = JobApplication::with('job.employer.employerProfile', 'worker.workerProfile')
            ->find($this->applicationId);

        if ($application === null) {
            return;
        }

        $callableAt = $screening->nextCallableTime();

        // Outside calling hours: put it down and pick it up when they open.
        // The delay lands exactly on the window opening, so needing a second
        // hold means the queue itself is late — at which point stopping is
        // safer than dialling, and it keeps this from ever looping.
        if ($callableAt->gt(now())) {
            if ($this->holds < self::MAX_HOLDS) {
                self::dispatch($this->applicationId, $this->attempt, $this->holds + 1)->delay($callableAt);
            }

            return;
        }

        $screening->start($application, $this->attempt);
    }
}
