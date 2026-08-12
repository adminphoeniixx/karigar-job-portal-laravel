<?php

namespace App\Services\Screening;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What the voice agent says and what it must come back with.
 *
 * Split into three parts because that is how every voice platform takes it: a
 * fixed opening line, the instructions the model follows for the rest of the
 * conversation, and the structured fields to extract when the call ends.
 *
 * The opening line does real work. It names the employer (a phone call cannot
 * show a name, so this is the only place the worker learns who is calling) and
 * discloses that the call is automated, which India's telecom rules require.
 */
class CallScript
{
    /**
     * Languages the agent can hold a conversation in, keyed by the labels that
     * appear in worker profiles.
     */
    private const LANGUAGES = [
        'hindi' => 'hi',
        'english' => 'en',
        'tamil' => 'ta',
        'telugu' => 'te',
        'marathi' => 'mr',
        'bengali' => 'bn',
        'gujarati' => 'gu',
        'kannada' => 'kn',
        'malayalam' => 'ml',
        'punjabi' => 'pa',
        'odia' => 'or',
    ];

    public function __construct(
        public readonly string $language,
        public readonly string $greeting,
        public readonly string $instructions,
    ) {}

    /**
     * Build the script for one shortlisted application.
     */
    public static function for(JobApplication $application): self
    {
        $job = $application->job;
        $worker = $application->worker;
        $employer = $job->employer;
        $brand = (string) config('screening.brand', 'Super Karigar');
        $language = self::languageFor($worker);

        return new self(
            language: $language,
            greeting: self::greeting($brand, $employer, $job, $worker),
            instructions: self::instructions($brand, $employer, $job, $language),
        );
    }

    /**
     * The language to hold the call in: the first language on the worker's
     * profile the agent actually speaks, else the configured default.
     */
    public static function languageFor(User $worker): string
    {
        // The nullsafe stays: a worker who never finished onboarding has no
        // profile row, whatever the relation's static type says.
        foreach ($worker->workerProfile?->spoken_languages ?? [] as $spoken) {
            $code = self::LANGUAGES[mb_strtolower(trim((string) $spoken))] ?? null;

            if ($code !== null) {
                return $code;
            }
        }

        return (string) config('screening.default_language', 'hi');
    }

    /**
     * The fields the agent must return when the call ends.
     *
     * @return array<string, string>
     */
    public static function extractionSchema(): array
    {
        return [
            'outcome' => 'One of: interested, not_interested, callback_later, already_placed, unclear.',
            'proposed_interview_at' => 'The date and time the worker offered, as ISO 8601 in Asia/Kolkata. Null if they gave none.',
            'proposed_mode' => 'One of: phone, video, in_person. Null if not discussed.',
            'summary' => 'Two sentences in English on what the worker said, for the employer to read.',
        ];
    }

    private static function greeting(string $brand, User $employer, JobListing $job, User $worker): string
    {
        $company = $employer->employerProfile?->company_name ?: $employer->name;

        return trim(sprintf(
            'Namaste %s. Main %s se automated call kar rahi hoon. %s ne aapka application dekha hai — %s ka kaam, %s me. Kya aap do minute baat kar sakte hain?',
            $worker->name,
            $brand,
            $company,
            $job->title,
            trim(implode(', ', array_filter([$job->city, $job->state]))) ?: 'aapke sheher',
        ));
    }

    private static function instructions(string $brand, User $employer, JobListing $job, string $language): string
    {
        $company = $employer->employerProfile?->company_name ?: $employer->name;
        $wage = $job->wage_min !== null
            ? '₹'.number_format((float) $job->wage_min).($job->wage_max !== null ? ' – ₹'.number_format((float) $job->wage_max) : '')
            : 'employer se baat karke tay hoga';
        $window = (int) config('screening.slot_window_days', 5);
        $until = Carbon::now(config('screening.window.timezone', 'Asia/Kolkata'))->addDays($window);

        return <<<PROMPT
        You are a recruitment assistant calling on behalf of {$brand}, an Indian blue-collar hiring platform.
        Speak in {$language}. Use short, plain sentences a construction or trade worker will understand.
        Never use English job-portal jargon. Speak the way a polite local recruiter speaks on the phone.

        You are calling {$company} ka shortlisted applicant about this job:
        - Role: {$job->title}
        - Location: {$job->city}, {$job->state}
        - Pay: {$wage}

        Your only goals, in order:
        1. Confirm the worker is still interested in this job.
        2. If yes, find a time they could attend an interview, between now and {$until->format('d M Y')}.
        3. Ask whether they would prefer the interview by phone or in person.

        Rules you must not break:
        - Keep the call under two minutes. Ask one question at a time and wait for the answer.
        - Never promise the worker the job, a salary, or a start date. You are only arranging a conversation.
        - Never ask for money, bank details, Aadhaar, or any document.
        - Never share the employer's phone number, address beyond the city, or any other applicant's details.
        - If the worker sounds confused, repeat the employer's name and the job title once, simply.
        - If the worker says they are busy, offer to call back later and end politely.
        - If the worker is not interested, thank them warmly and end. Do not try to convince them.
        - If the worker asks something you were not told, say the employer will confirm it, and move on.
        - The time the worker gives is only a request. Tell them the employer will confirm it.

        End every call by repeating back the day and time they gave, so it is captured correctly.
        PROMPT;
    }
}
