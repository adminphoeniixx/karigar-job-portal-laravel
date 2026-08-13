<?php

namespace App\Console\Commands;

use App\Enums\ScreeningCallStatus;
use App\Models\JobApplication;
use App\Models\ScreeningCall;
use App\Services\Screening\CallScript;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * A screening call with no phone line in it.
 *
 * Telephony is the one part of this feature we cannot exercise without a
 * carrier, and it is also the part that is least ours — the script, the
 * language, the extraction and the employer's confirmation screen are where
 * the risk actually lives. This writes the *real* script for a real applicant
 * to a file the agent can run in console mode, so all of that can be tested
 * by talking into a laptop microphone.
 *
 * It creates a genuine screening_calls row, so the agent's result lands on the
 * applicant exactly as a real call's would, and the employer sees the proposed
 * slot on their screen.
 */
class RehearseScreeningCall extends Command
{
    protected $signature = 'screening:rehearse
        {application : The job application to rehearse a call for}
        {--path= : Where to write the script (default services/screening-agent/rehearsal.json)}';

    protected $description = 'Write a real screening-call script the agent can run without a phone line';

    public function handle(): int
    {
        $application = JobApplication::with('job.employer.employerProfile', 'worker.workerProfile')
            ->find((int) $this->argument('application'));

        if ($application === null) {
            $this->error('No application with that id.');

            return self::FAILURE;
        }

        if ($application->worker === null || $application->job === null) {
            $this->error('That application has no worker or no job on it.');

            return self::FAILURE;
        }

        $script = CallScript::for($application);

        // The same room naming a real call uses, so the webhook accepts the
        // result instead of treating it as someone else's call.
        $room = 'screening-rehearsal-'.Str::lower(Str::random(12));

        $call = ScreeningCall::create([
            'job_application_id' => $application->id,
            'worker_id' => $application->worker_id,
            'provider' => 'rehearsal',
            'provider_call_id' => $room,
            'status' => ScreeningCallStatus::Dialing,
            'language' => $script->language,
            'attempt' => 1,
            'started_at' => now(),
        ]);

        // No 'dial' key: that absence is what tells the agent to talk to
        // whoever is already in the room instead of ringing a phone.
        $metadata = [
            'screening_call_id' => $call->id,
            'room' => $room,
            'language' => $script->language,
            'greeting' => $script->greeting,
            'instructions' => $script->instructions,
            'extraction_schema' => CallScript::extractionSchema(),
            'voice' => (string) config('screening.voice'),
        ];

        $path = (string) ($this->option('path') ?: base_path('services/screening-agent/rehearsal.json'));

        file_put_contents($path, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->info('Rehearsal ready.');
        $this->newLine();
        $this->line('  Applicant : '.$application->worker->name.' → '.$application->job->title);
        $this->line('  Language  : '.$script->language);
        $this->line('  Call row  : #'.$call->id.' ('.$room.')');
        $this->line('  Script    : '.$path);
        $this->newLine();
        $this->line('The agent will say:');
        $this->line('  "'.$script->greeting.'"');
        $this->newLine();
        $this->comment('Now talk to it — no phone needed:');
        $this->line('  cd services/screening-agent');
        $this->line('  SCREENING_TEST_SCRIPT='.$path.' python agent.py console');
        $this->newLine();
        $this->line('Hang up (Ctrl+C the console) and the result posts to the webhook,');
        $this->line('so the employer sees the outcome on the applicants page.');

        return self::SUCCESS;
    }
}
