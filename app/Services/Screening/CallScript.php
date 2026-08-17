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

    /**
     * The same languages by name. A model told to "speak in hi" answers in
     * English — the code means nothing to it, while the name does.
     */
    private const LANGUAGE_NAMES = [
        'hi' => 'Hindi',
        'en' => 'English',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'mr' => 'Marathi',
        'bn' => 'Bengali',
        'gu' => 'Gujarati',
        'kn' => 'Kannada',
        'ml' => 'Malayalam',
        'pa' => 'Punjabi',
        'or' => 'Odia',
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
            'proposed_mode' => 'One of: site (they come to the workplace), phone, video. Null if not discussed.',
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

        $languageName = self::LANGUAGE_NAMES[$language] ?? 'Hindi';

        // Workers do not speak textbook Hindi and they do not want to be read
        // to in it either. The greeting is already Roman-script Hinglish; the
        // rest of the call has to match it or the agent sounds like a news
        // anchor and the worker stops answering.
        $register = $language === 'hi'
            ? "Speak {$languageName} the way it is actually spoken on a worksite — everyday Hinglish, with the common English words (site, interview, time, salary) left in English. Do not use formal or Sanskritised {$languageName}."
            : "Speak {$languageName} the way it is actually spoken, with the common English words (site, interview, time, salary) left in English.";

        // Two rules the model broke in rehearsal when they were stated in the
        // abstract: it answered "dihadi ₹800 se ₹1,000 tak hogi" (a promise)
        // and "main aapko confirm karungi" (only the employer confirms). Both
        // now come with the sentence to actually say, because a worked example
        // lands where a prohibition does not.
        $payExample = $language === 'hi'
            ? ' For example: "Employer ne '.$wage.' likha hai, final amount employer hi tay karega."'
            : '';
        $confirmExample = $language === 'hi'
            ? ' For example: "Main employer ko bata deti hoon, wo aapko confirm karenge."'
            : '';

        return <<<PROMPT
        You are a recruitment assistant calling on behalf of {$brand}, an Indian blue-collar hiring platform.
        Every single reply must be in {$languageName}. Never answer in English, even if the worker
        uses English words, and never switch language mid-call.
        {$register}
        Use short, plain sentences a construction or trade worker will understand.
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
        - PAY: the figure above is only what the employer has listed, not an agreed wage. If the worker
          asks what the pay is, say the listed figure and in the same breath say the employer settles the
          final amount. Never say the pay "will be" or "is" a number — that is a promise you cannot make.{$payExample}
        - CONFIRMING: you have no authority to confirm anything. Only the employer confirms the interview.
          Never say that you will confirm, that you will call back to confirm, or that anything is fixed.
          When the worker offers a day or time, say once that it goes to the employer to confirm.{$confirmExample}
          Say it in your own words and only once per reply — repeating the same sentence twice in one
          answer, or in every answer, sounds like a recording and the worker hangs up.
        - Never ask for money, bank details, Aadhaar, or any document.
        - Never share the employer's phone number, address beyond the city, or any other applicant's details.
        - If the worker sounds confused, repeat the employer's name and the job title once, simply.
        - If the worker says they are busy, offer to call back later and end politely.
        - If the worker is not interested, thank them warmly and end. Do not try to convince them.
        - If the worker asks something you were not told, say the employer will confirm it, and move on.

        End every call by repeating back the day and time they gave, and saying the employer will confirm
        it — never that you will. The repeat-back is so the slot is captured correctly, not a confirmation.
        PROMPT;
    }
}
