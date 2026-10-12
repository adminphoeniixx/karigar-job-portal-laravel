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
 * Runs on the queue because dialling talks to a telephony provider. Outside
 * the permitted daytime calling window it books the call for the morning
 * instead ({@see ScreeningService::schedule()}), so nobody is woken up.
 */
class PlaceScreeningCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    /**
     * @param  int  $holds  Unused since calls outside hours are booked
     *                      instead; kept so queued jobs still unserialize.
     */
    public function __construct(public int $applicationId, public int $attempt = 1, public int $holds = 0) {}

    public function handle(ScreeningService $screening): void
    {
        $application = JobApplication::with('job.employer.employerProfile', 'worker.workerProfile')
            ->find($this->applicationId);

        if ($application === null) {
            return;
        }

        // Outside calling hours: book it for when they open. A booked call
        // is dialled by `screening:place-due`; the queue's own delay is not
        // trusted, since the sync queue production runs ignores it.
        if ($screening->nextCallableTime()->gt(now())) {
            $screening->schedule($application, null, $this->attempt > 1 ? 'retry' : 'auto', attempt: $this->attempt);

            return;
        }

        $screening->start($application, $this->attempt);
    }
}
