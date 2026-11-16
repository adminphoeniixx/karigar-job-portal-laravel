<?php

namespace App\Services\Screening;

use App\Models\JobApplication;

/**
 * The reminder call on the morning of a booked interview. One question —
 * are you coming? — and, if not, which day and time would suit instead. The
 * agent is generic (greeting, instructions and schema travel as metadata), so
 * this needs no agent change.
 */
class InterviewReminderScript
{
    public static function for(JobApplication $application): CallScript
    {
        $brand = (string) config('screening.brand', 'Super Karigar');
        $worker = $application->worker;
        $language = CallScript::languageFor($worker);
        $name = CallScript::spokenName($worker);

        return new CallScript(
            language: $language,
            greeting: self::greeting($brand, $name),
            instructions: self::instructions($application, $brand, $language, $name),
        );
    }

    /**
     * @return array<string, string>
     */
    public static function extractionSchema(): array
    {
        return [
            'outcome' => 'One of: will_come, reschedule, not_coming, callback_later, unclear. will_come = the applicant themselves confirmed they will be there (or on the call) at the booked time. reschedule = they cannot make this time but want the interview and asked for another day or time. not_coming = they are no longer interested or will not come. callback_later = they were busy, or someone else answered and the applicant was not available. unclear = no person answered, it was a wrong number, or the call ended before they said.',
            'proposed_interview_at' => 'Only when the outcome is reschedule: the day and time they asked for instead, as "YYYY-MM-DD HH:MM:SS" in IST, resolved from the date of the call. A day with no time: 10:00:00. Null otherwise, and null when they left it to the employer. Never invent a time.',
            'summary' => 'In English, for the employer, at most four sentences: whether the applicant themselves answered; whether they confirmed they are coming; if not, why and what time they asked for instead; anything they asked that the employer should know (the address, who to meet, what to bring). Dates in plain words, never as a timestamp.',
        ];
    }

    /**
     * A clock time the way it is said in Hindi: "शाम चार बजे", "सुबह साढ़े
     * दस बजे", "दोपहर सवा बारह बजे". "4:00 PM" read by a Hindi voice is
     * neither.
     */
    public static function spokenTimeHindi(\DateTimeInterface $at): string
    {
        $hour24 = (int) $at->format('G');
        $minute = (int) $at->format('i');
        $period = match (true) {
            $hour24 >= 5 && $hour24 < 12 => 'सुबह',
            $hour24 >= 12 && $hour24 < 16 => 'दोपहर',
            $hour24 >= 16 && $hour24 < 20 => 'शाम',
            default => 'रात',
        };
        $words = [1 => 'एक', 'दो', 'तीन', 'चार', 'पाँच', 'छह', 'सात', 'आठ', 'नौ', 'दस', 'ग्यारह', 'बारह'];
        $hour12 = $hour24 % 12 === 0 ? 12 : $hour24 % 12;
        $next = $hour12 === 12 ? 1 : $hour12 + 1;

        $clock = match (true) {
            $minute === 0 => $words[$hour12].' बजे',
            $minute === 15 => 'सवा '.$words[$hour12].' बजे',
            $minute === 30 => match ($hour12) {
                1 => 'डेढ़ बजे',
                2 => 'ढाई बजे',
                default => 'साढ़े '.$words[$hour12].' बजे',
            },
            $minute === 45 => 'पौने '.$words[$next].' बजे',
            default => $words[$hour12].' बजकर '.$minute.' मिनट पर',
        };

        return $period.' '.$clock;
    }

    private static function greeting(string $brand, ?string $name): string
    {
        $who = $name !== null
            ? sprintf('क्या मेरी बात %s से हो रही है?', $name)
            : sprintf('क्या मेरी बात उन्हीं से हो रही है जिन्होंने %s पर job के लिए apply किया था?', $brand);

        return sprintf('नमस्ते। मैं %s से बोल रही हूँ। यह कॉल रिकॉर्ड हो रही है। %s', $brand, $who);
    }

    private static function instructions(JobApplication $application, string $brand, string $language, ?string $name): string
    {
        $job = $application->job;
        $employer = $job->employer;
        $company = $employer?->employerProfile?->company_name ?: ($employer?->name ?? $brand);
        $role = CallScript::role($job);
        $applicant = $name ?? 'the applicant';
        $languageName = CallScript::LANGUAGE_NAMES[$language] ?? 'Hindi';
        $hindi = $language === 'hi';

        // interview_at is Indian wall-clock as typed: format it, never convert it.
        $at = $application->interview_at;
        $timeSpoken = $at->format('g:i A');
        $daySpoken = $at->format('Y-m-d') === now(config('app.display_timezone', 'Asia/Kolkata'))->format('Y-m-d') ? 'today' : $at->format('l, d F');
        $mode = $application->interview_mode ?? $job->bookedInterviewMode();

        // The street address, with the city added only when it is not in it already.
        $address = trim((string) $job->address);
        $city = trim((string) $job->city);
        $place = $address !== '' && $city !== '' && mb_stripos($address, $city) !== false
            ? $address
            : trim(implode(', ', array_filter([$address, $city])));
        $timeHindi = self::spokenTimeHindi($at);
        $contact = trim((string) $job->contact_name);

        $where = match ($mode) {
            'phone' => 'on a phone call: the employer will ring them at that time',
            'video' => 'on a video call: the employer will send or has sent the link',
            default => 'in person'.($place !== '' ? " at {$place}" : ' at the employer\'s place').($contact !== '' ? ", where they should ask for {$contact}" : ''),
        };

        $script = $hindi
            ? ' Write your replies in Devanagari script, not in Roman transliteration — क्या आप, not "kya aap". Words that are genuinely English (interview, call, address, time) stay in English letters inside the Devanagari sentence.'
            : '';

        $remind = $hindi
            ? ($mode === 'site'
                ? " Say it as: \"आज {$timeHindi} {$company} में {$role} के काम के लिए आपका interview है".($place !== '' ? ", {$place} पर" : '').'। क्या आप आ रहे हैं?"'
                : " Say it as: \"आज {$timeHindi} {$company} आपको {$role} के काम के interview के लिए ".($mode === 'video' ? 'video call' : 'phone').' पर call करेगी। क्या आप उस समय free रहेंगे?"')
            : '';

        $yes = $hindi
            ? ($mode === 'site'
                ? ' Say it as: "बहुत अच्छा। समय से थोड़ा पहले पहुँच जाइए।"'.($contact !== '' ? " \"वहाँ {$contact} से मिलिए।\"" : '').' Then if they ask, repeat the address once, slowly.'
                : ' Say it as: "बहुत अच्छा। उस समय phone पास रखिए, और network वाली जगह पर रहिए।"')
            : '';

        $no = $hindi
            ? ' Say it as: "कोई बात नहीं। आपको कौन सा दिन और कितने बजे ठीक रहेगा? मैं employer को बता दूँगी।" Take whatever they say and tell them the employer will confirm it. If they do not want the interview at all, say that is fine and thank them.'
            : '';

        $close = $hindi
            ? ' Say it as: "ठीक है। समय देने के लिए धन्यवाद।"'
            : '';

        return <<<PROMPT
        You are calling on behalf of {$brand}, an Indian blue-collar hiring platform, to remind a job
        applicant about their interview today. This is a short reminder, not a screening: under a minute
        when they say yes.
        Every single reply must be in {$languageName}. Never switch language mid-call.
        Speak the everyday {$languageName} people actually speak, with the common English words left in
        English.{$script} Always use the respectful form (आप, बताइए — never तुम or तू). Short sentences,
        each ending in "।" or "?". React briefly and differently each time ("अच्छा।", "ठीक है।"), never
        with हाँ जी or ठीक है जी, then carry on in the same reply.

        The interview:
        - Employer: {$company}
        - Role: {$role}
        - When: {$daySpoken} at {$timeSpoken} (Indian time)
        - How: {$where}

        You have already greeted them and asked whether you are speaking to {$applicant}. Now, in order:

        1. MAKE SURE IT IS THE RIGHT PERSON. Wait for their answer.
           - If yes, go straight on to step 2 in the same reply.
           - If someone else answered, ask whether you can speak to {$applicant}. If they are not available,
             say you will call later, thank them and end the call. Tell someone else nothing about the interview.
           - If the reply is unclear (music, noise, a single word), it is NOT a wrong number: say you could not
             hear and ask again, up to two more times, then say you will call later and end.
        2. REMIND THEM AND ASK IF THEY ARE COMING.{$remind}
        3. ANSWER.
           - If they are coming: say good, and the one practical line for how the interview is held.{$yes}
           - If they cannot make it, or want another time: ask which day and time would suit them, take it
             down, and say the employer will confirm it with them.{$no} Never offer times yourself and never
             say anything is fixed or confirmed.
           - If they are no longer interested: say that is fine, thank them and end. Do not try to convince them.
        4. CLOSE. Thank them, and call the end_call tool in that same reply.{$close}

        Rules you must not break:
        - Only the details above. Pay, documents, how long the interview takes, anything else: say the
          employer will tell them, and move on. Never promise the job.
        - Never ask for money, bank details, Aadhaar or any document. Never share the employer's phone number.
        - Ask one question at a time. If the worker asks whether you are a real person or AI, answer honestly
          that you are {$brand}'s AI assistant, then carry on.

        When the call may end: after step 4, or at the exits in steps 1 and 3. "हाँ" to "are you coming?"
        means step 3's good line comes next, then the goodbye. Then hang up, using the end_call tool: the
        goodbye and the end_call tool call go in the SAME reply, never in a later one — if you do not call
        it, the line stays open and they hear silence. A tool call, never the words "end_call" in your reply.
        PROMPT;
    }
}
