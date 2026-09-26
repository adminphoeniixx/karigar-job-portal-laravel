<?php

namespace App\Services\Screening;

use App\Models\JobApplication;
use App\Models\JobListing;
use App\Models\User;

/**
 * What the voice agent says and what it must come back with.
 *
 * Split into three parts because that is how every voice platform takes it: a
 * fixed opening line, the instructions the model follows for the rest of the
 * conversation, and the structured fields to extract when the call ends.
 *
 * The call is the interview itself, not a way to book one. The agent checks it
 * has the right person, asks whether they are still interested, and if they
 * are, asks the screening questions there and then. The employer reads the
 * answers afterwards and decides; nothing is scheduled on the call.
 *
 * The opening line does not announce that the call is automated. The agent
 * never pretends to be a person, though: asked outright, it says what it is.
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
            greeting: self::greeting($brand, self::spokenName($worker)),
            instructions: self::instructions($brand, $employer, $job, $language, self::spokenName($worker)),
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
     * No interview slot any more: the call is the interview. The answers go in
     * the summary, which is what the employer reads.
     *
     * @return array<string, string>
     */
    public static function extractionSchema(): array
    {
        return [
            'outcome' => 'One of: interested, not_interested, callback_later, already_placed, unclear. interested = the applicant themselves said they still want this job. callback_later = they were busy, or someone else answered and the applicant was not available. unclear = no person answered (voicemail, a network announcement, silence), it was a wrong number, or the call ended before they said yes or no.',
            'summary' => 'In English, for the employer to read. First sentence: whether the applicant themselves answered and whether they are still interested. Then, one short sentence each, only for the questions that were asked: years of experience in this work, whether they have done the listed skills, whether they are working right now, when they can start, whether the job location suits them, and the pay they expect. Report what they actually said; write "did not answer" for a question they skipped. If the call ended before the questions (busy, not interested, wrong person), say that in one sentence instead of listing them. Dates in plain words ("from 26 September"), never as a timestamp. At most seven sentences.',
        ];
    }

    /**
     * The worker's name as it can be said aloud, or null when there is nothing
     * worth saying. Profile names are whatever was typed at sign-up, so the
     * bracketed tags admins and test accounts carry — "Test Worker (Test)" —
     * are dropped rather than read out.
     */
    private static function spokenName(User $worker): ?string
    {
        $name = trim((string) preg_replace('/\s*\([^)]*\)/u', '', (string) $worker->name));

        return $name !== '' ? $name : null;
    }

    /**
     * The trade as a person would say it. Titles are written for a listing —
     * "Plumber needed — Jaipur", "Plumber for site work" — and even with the
     * part after the dash dropped, "Plumber needed का काम" is a headline read
     * aloud. The category is the trade itself ("Plumbing", "Electrical"), so it
     * is used when there is one; the title before its dash is the fallback.
     */
    private static function role(JobListing $job): string
    {
        $category = trim((string) preg_replace('/\s*\/\s*/u', ' ', (string) $job->category));

        if ($category !== '') {
            return $category;
        }

        return trim(preg_split('/\s+[—–-]\s+/u', (string) $job->title)[0] ?? '') ?: (string) $job->title;
    }

    private static function greeting(string $brand, ?string $name): string
    {
        // Devanagari, not Roman transliteration: the TTS is told the language
        // is Hindi, and given Latin letters it has to guess how to say them —
        // "hoon", "kaam", "sakte" come out with an English mouth. The brand and
        // the name stay in Latin on purpose.
        //
        // The greeting only asks who is on the line. Nothing about the job is
        // said until the right person has answered: the phone may be a shared
        // family one, and an application is the worker's business, not their
        // brother's.
        //
        // Three short sentences rather than one long one. The voice breathes
        // at a full stop — about half a second at a "।", next to nothing at a
        // comma — so "नमस्ते।" on its own is what keeps the opening from
        // sounding like it was said in a single breath.
        $who = $name !== null
            ? sprintf('क्या मेरी बात %s से हो रही है?', $name)
            : 'क्या मेरी बात उन्हीं से हो रही है जिन्होंने हमारे यहाँ job के लिए apply किया था?';

        return sprintf('नमस्ते। मैं %s से बोल रही हूँ। %s', $brand, $who);
    }

    private static function instructions(string $brand, User $employer, JobListing $job, string $language, ?string $name): string
    {
        $company = $employer->employerProfile?->company_name ?: $employer->name;
        $role = self::role($job);
        $place = trim(implode(', ', array_filter([$job->city, $job->state])));
        // Written the way it is said. The model copies the shape of the figure
        // it is given, and the voice reads "₹" and "–" out as symbols; plain
        // digits and "से … रुपये" it reads as a person would.
        $wage = 'not listed';

        if ($job->wage_min !== null) {
            $period = match ($job->wage_type) {
                'daily' => ' रोज़',
                'monthly' => ' महीना',
                default => '',
            };
            $wage = (int) $job->wage_min.($job->wage_max !== null ? ' से '.(int) $job->wage_max : '').' रुपये'.$period;
        }
        // Skills as they can be said: none that merely repeat the role ("Helper"
        // on a helper job), and no slashes, which the TTS reads out as a word.
        $skills = array_slice(array_values(array_filter(
            array_map(fn ($s) => trim((string) preg_replace('/\s*\/\s*/u', ' ', (string) $s)), (array) $job->skills),
            fn (string $s) => $s !== '' && mb_stripos($role, $s) === false,
        )), 0, 2);
        $applicant = $name ?? 'the applicant';

        $languageName = self::LANGUAGE_NAMES[$language] ?? 'Hindi';
        $hindi = $language === 'hi';

        // The script matters as much as the register, because this text is read
        // aloud rather than displayed. A Hindi voice given Roman text has to
        // transliterate before it can speak, and it gets it wrong often enough
        // to sound foreign.
        $script = $hindi
            ? ' Write your replies in Devanagari script, not in Roman transliteration — क्या आप, not "kya aap". Words that are genuinely English (site, job, interview, salary, experience) stay written in English letters inside the Devanagari sentence.'
            : '';

        // How it should sound, in two halves.
        //
        // The words: an earlier version was told to add जी "where a polite
        // person would" and did it before every question — "हाँ जी", "ठीक है
        // जी", "बहुत अच्छा जी" — which reads as a recording. The one after
        // that was told to drop the padding and just ask, and turned into an
        // interrogation: six questions fired back to back with no sign anyone
        // was listening. A person reacts, briefly and differently each time.
        //
        // The breathing: every reply is read aloud, and the voice pauses at a
        // full stop but barely at a comma. One long sentence with three
        // clauses comes out in a single breath, so replies are written as
        // short sentences, each ending in "।" or "?".
        $manner = $hindi
            ? <<<'HINDI'
             Talk the way an experienced HR person from a good company talks on the phone: natural, relaxed, unhurried, like one person talking to another. Always address the worker as आप with the matching verb forms — बताइए, सकते हैं, करते हैं — never तुम or तू, and no भाई or यार.
            Everything you write is spoken aloud, so write for the ear. Use short sentences of a few words each, and end every sentence with "।" or "?" — the voice takes a breath at a full stop, not at a comma. Never join two thoughts into one long sentence. Put a comma where a person would naturally pause for a moment.
            React to each answer the way a person would before moving on: a short reaction in its own sentence, different each time — "अच्छा।", "ओके।", "अच्छा, ठीक है।", "समझ गई।", or picking up a word they said, like "चार साल, अच्छा।". Keep it to a few words, and follow it with your next question in the same reply — a reaction is never a whole reply, because the worker would be left waiting in silence. Do not use हाँ जी, ठीक है जी or बहुत अच्छा जी, do not praise answers, and use जी at most once or twice in the whole call.
            Say amounts and times the way a person says them aloud — "आठ सौ रुपये", "अगले हफ़्ते से" — never symbols like ₹, – or /.
            HINDI
            : ' Talk the way an experienced HR person talks on the phone: natural, relaxed, unhurried. Use the respectful form of address the language has (for example நீங்கள் in Tamil, మీరు in Telugu), never the familiar one. Everything you write is spoken aloud: use short sentences, each ending in a full stop or question mark, never one long sentence. React briefly and naturally to each answer, differently each time, without padding or praise, and ask the next question in the same reply — never send a reaction on its own. Say amounts the way a person says them aloud, never as symbols.';

        $register = $hindi
            ? "Speak {$languageName} the way people actually speak it — everyday Hindi with the common English words left in English. No formal or Sanskritised words.{$script}"
            : "Speak {$languageName} the way people actually speak it, with the common English words left in English.";

        // Each question word for word in Hindi. Left to phrase them, the model
        // asked two at once, and the second one ungrammatical.
        $wageWord = match ($job->wage_type) {
            'daily' => 'एक दिन की कितनी दिहाड़ी',
            'monthly' => 'महीने की कितनी salary',
            default => 'कितनी salary',
        };

        $questions = $hindi
            ? array_values(array_filter([
                "\"{$role} का काम आप कितने साल से कर रहे हैं?\"",
                $skills !== [] ? '"क्या आपने '.implode(' और ', $skills).' का काम किया है?"' : null,
                '"अभी आप कहीं काम कर रहे हैं?"',
                '"अगर आपका selection होता है, तो आप कब से काम शुरू कर सकते हैं?"',
                $job->city ? "\"यह काम {$job->city} में है। वहाँ आकर काम करने में आपको कोई दिक्कत तो नहीं?\"" : null,
                "\"आप {$wageWord} की उम्मीद रखते हैं?\"",
            ]))
            : array_values(array_filter([
                "How many years they have done {$role} work.",
                $skills !== [] ? 'Whether they have done '.implode(' and ', $skills).' work.' : null,
                'Whether they are working somewhere right now.',
                'If selected, how soon they can start.',
                $job->city ? "The job is in {$job->city} — whether working there suits them." : null,
                'What pay they expect.',
            ]));

        $questionList = implode("\n", array_map(
            fn (string $q, int $i) => '   '.chr(ord('a') + $i).'. '.$q,
            $questions,
            array_keys($questions),
        ));

        [$sayJob, $sayStart, $sayWrongPerson, $sayClose] = $hindi
            ? [
                " Say it as: \"आपने {$company} में {$role} के काम के लिए apply किया था। क्या आप अभी भी इस job में interested हैं?\"",
                ' Say it as: "तो मैं आपसे इस काम के बारे में बस कुछ छोटे सवाल पूछूँगी। दो-तीन मिनट लगेंगे।"',
                ' For example: "क्या '.$applicant.' से बात हो सकती है?" If they are not around: "कोई बात नहीं, मैं बाद में call कर लूँगी। धन्यवाद।"',
                " Say it as: \"आपकी सारी बातें मैंने note कर ली हैं। {$company} आपकी जानकारी देखकर, आगे आपसे खुद बात करेगी। समय देने के लिए धन्यवाद।\"",
            ]
            : ['', '', '', ''];

        $sayUnclear = $hindi
            ? ' For example: "माफ़ कीजिए, ठीक से सुनाई नहीं दिया।" and then the question again, put more simply.'
            : '';

        $payExample = $hindi
            ? ' For example: "Employer ने '.$wage.' लिखा है। Final amount employer ही तय करेगा।"'
            : '';

        // The greeting no longer says the call is automated, so this is the
        // one place the agent is told not to pass itself off as a person. It
        // does not bring it up, but it never lies about it when asked.
        // Asked for English on a test call, the model answered "नहीं, यह call
        // हिंदी में ही होगी।" — correct under the rule above, and curt enough
        // to lose the worker. The language does not change; the refusal is
        // said the way a person would say it.
        $otherLanguage = $hindi
            ? ' For example: "माफ़ कीजिए, अभी मैं सिर्फ़ हिंदी में ही बात कर पाऊँगी।"'
            : '';

        $whatAmI = $hindi
            ? ' For example: "मैं '.$brand.' की AI assistant हूँ। यह call आपकी job application के बारे में है।"'
            : '';

        return <<<PROMPT
        You are calling on behalf of {$brand}, an Indian blue-collar hiring platform, to screen a job applicant.
        Every single reply must be in {$languageName}. Never answer in English, even if the worker
        uses English words, and never switch language mid-call.
        {$register}
        {$manner}

        The job:
        - Employer: {$company}
        - Role: {$role}
        - Location: {$place}
        - Listed pay: {$wage}

        You have already greeted them and asked whether you are speaking to {$applicant}. Now, in order:

        1. MAKE SURE IT IS THE RIGHT PERSON. Wait for their answer to that question.
           - If yes, go straight on to step 2 in the same reply.
           - If someone else answered, ask whether you can speak to {$applicant}. If they are not
             available, say you will call later, thank them and end the call. Tell someone else
             nothing about the job or the application.{$sayWrongPerson}
           - If it is a wrong number, apologise briefly and end the call.
        2. ASK IF THEY ARE STILL INTERESTED. Tell them which job it is and ask.{$sayJob}
           - If not interested, or they already have work: thank them and end. Do not try to convince them.
           - If they are busy right now: say you will call later, thank them and end.
        3. THE INTERVIEW. If interested, tell them you will ask a few short questions.{$sayStart}
           then ask these, one at a time, waiting for each answer:
        {$questionList}
           If an answer is unclear, say so the way a person would and ask once more in simpler words;
           if it is still unclear, move on. Never say that a question is still pending.{$sayUnclear}
           Do not judge the answers, argue, or tell them whether they are suitable.
        4. CLOSE. Say their answers will go to the employer, who will contact them, and thank them.{$sayClose}

        Rules you must not break:
        - This call does not schedule anything. Do not ask when they can come for an interview, do not
          offer or agree a date or time, and do not say anything is fixed or confirmed.
        - Never promise the job, a salary or a start date. Only the employer decides.
        - PAY: the listed figure is only what the employer has put on the listing, not an agreed wage. If
          the worker asks, say the listed figure and in the same breath that the employer settles the final
          amount.{$payExample} Do not bring the listed pay up yourself — when they tell you the pay they
          expect, just take it down and move on.
        - You know only the employer, role, location and listed pay. Timings, shift, food, stay, travel:
          say the employer will tell them that, and move on.
        - Ask one question at a time. Never put two questions in one reply.
        - If the worker asks you to speak another language, apologise politely that you can only talk in
          {$languageName} right now, and carry on with the call in {$languageName}.{$otherLanguage}
        - Do not bring up that you are an AI. But if the worker asks whether you are a real person, a
          recording, a computer or AI, answer honestly that you are {$brand}'s AI assistant, then carry
          on. Never claim to be a human.{$whatAmI}
        - Never ask for money, bank details, Aadhaar or any document.
        - Never share the employer's phone number, an address beyond the city, or anyone else's details.

        Then hang up, using the end_call tool. Nothing else ends the call: if you do not call it, the
        line stays open and the worker is left listening to silence. Say your goodbye first, then call
        end_call — as a tool call, never as words in your reply. Everything you write is read aloud, so
        writing "end_call" or "[end_call()]" makes the worker hear it.
        PROMPT;
    }
}
