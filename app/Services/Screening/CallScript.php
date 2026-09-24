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

    /**
     * Month names as they are said aloud, so a date in the script reaches the
     * TTS as words rather than as "28 Sep".
     */
    private const HINDI_MONTHS = [
        'जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून',
        'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर',
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
            greeting: self::greeting($brand, $employer, $job),
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
            'outcome' => 'One of: interested, not_interested, callback_later, already_placed, unclear. Use unclear when no person answered — a voicemail greeting, a network announcement or silence is not the worker saying no.',
            'proposed_interview_at' => 'The date and time the worker offered, as ISO 8601 in Asia/Kolkata. Null if they gave none.',
            'proposed_mode' => 'One of: site (they come to the workplace), phone, video. Null if not discussed.',
            'summary' => 'Two sentences in English on what the worker said, for the employer to read.',
        ];
    }

    private static function greeting(string $brand, User $employer, JobListing $job): string
    {
        $company = $employer->employerProfile?->company_name ?: $employer->name;

        // Devanagari, not the Roman transliteration this used to be. The TTS is
        // told the language is Hindi, and given Latin letters it has to guess
        // how to say them — "hoon", "kaam", "sakte" come out with an English
        // mouth, which is what "pronunciation sahi nahi hai" meant. In
        // Devanagari it simply reads them. The brand, the company name and the
        // English words a worker actually uses stay in Latin on purpose: the
        // register is still worksite Hinglish, only the script changes.
        //
        // No worker name. Profile names are whatever was typed at sign-up —
        // "Test Worker (Test)", a nickname, a name in the wrong script — and
        // read aloud they make the call sound broken. "AI automated call" is
        // the disclosure, said plainly.
        return trim(sprintf(
            'नमस्ते जी। मैं %s से बात कर रही हूँ, यह एक AI automated call है। %s ने %s के काम के लिए आपका application देखा है, जो %s में है। क्या अभी आपसे दो minute बात हो सकती है?',
            $brand,
            $company,
            $job->title,
            trim(implode(', ', array_filter([$job->city, $job->state]))) ?: 'आपके शहर',
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
        // The script matters as much as the register, because this text is read
        // aloud rather than displayed. A Hindi voice given Roman text has to
        // transliterate before it can speak, and it gets it wrong often enough
        // to sound foreign. Writing the Hindi in Devanagari removes the guess;
        // leaving the English words in Latin keeps them sounding English,
        // which is how a worker says "site" and "interview" anyway.
        $script = $language === 'hi'
            ? ' Write your replies in Devanagari script, not in Roman transliteration — क्या आप, not "kya aap". Words that are genuinely English (site, interview, time, salary, confirm, project) stay written in English letters inside the Devanagari sentence.'
            : '';

        // Plain words, but never a familiar address. "Worksite Hinglish" on its
        // own let the model drift into तुम / बताओ / करो, which is how a
        // supervisor talks down to a labourer — the opposite of a recruiter
        // calling on an employer's behalf. Everyday vocabulary, respectful
        // grammar: आप with its matching verb forms, and जी where a person would
        // say it.
        $respect = $language === 'hi'
            ? ' Always address the worker as आप, with the respectful verb forms that go with it — बताइए, कीजिए, आइए, सकते हैं, not बताओ, करो, आओ, सकते हो. Never use तुम or तू, not even once, not even if the worker uses it with you. Add जी where a polite person would (हाँ जी, ठीक है जी, धन्यवाद जी) and do not call the worker by name — address them as आप or जी. Ask each question once per reply, not twice in different words. Stay calm and courteous throughout — no slang, no jokes, no over-familiar tone like भाई or यार. For example: "आप किस दिन interview के लिए आ सकते हैं?", not "तुम कब आ सकते हो?"'
            : ' Always use the respectful form of address the language has (for example आप in Hindi-family languages, நீங்கள் in Tamil, మీరు in Telugu), never the familiar one, and stay courteous and professional throughout.';

        $register = $language === 'hi'
            ? "Speak {$languageName} the way it is actually spoken on a worksite — everyday Hinglish, with the common English words (site, interview, time, salary) left in English. Do not use formal or Sanskritised {$languageName} words, but keep the grammar respectful.{$respect}{$script}"
            : "Speak {$languageName} the way it is actually spoken, with the common English words (site, interview, time, salary) left in English.{$respect}";

        // Two rules the model broke in rehearsal when they were stated in the
        // abstract: it answered "dihadi ₹800 se ₹1,000 tak hogi" (a promise)
        // and "main aapko confirm karungi" (only the employer confirms). Both
        // now come with the sentence to actually say, because a worked example
        // lands where a prohibition does not.
        // In Devanagari too — the model copies the shape of these, so a Roman
        // example would quietly undo the script rule above.
        $payExample = $language === 'hi'
            ? ' For example: "Employer ने '.$wage.' लिखा है, final amount employer ही तय करेगा।"'
            : '';
        $confirmExample = $language === 'hi'
            ? ' For example: "मैं employer को बता देती हूँ, वो आपको confirm करेंगे।"'
            : '';

        // What "professional" sounds like on a Hindi phone line, spelled out,
        // because the model's idea of it was either a chatty friend or a
        // government announcement. A short acknowledgement before each next
        // question, a proper thank-you at the end, and nothing the TTS reads
        // badly: it says "₹" and "–" literally, so money and times go out the
        // way a person would say them.
        $manner = $language === 'hi'
            ? ' Acknowledge each answer in a few words before the next question — "जी, समझ गई।", "बहुत अच्छा जी।", "ठीक है जी।" — and vary them rather than repeating one. Say amounts and times the way a person says them aloud: "आठ सौ से एक हज़ार रुपये", "कल सुबह दस बजे", never symbols like ₹, –, / or digits with colons. Close with a proper thank-you, for example: "आपने समय दिया, इसके लिए धन्यवाद जी। Employer जल्द ही आपसे संपर्क करेंगे। आपका दिन शुभ हो।"'
            : ' Acknowledge each answer briefly before the next question, say amounts and times the way a person says them aloud rather than as symbols, and close with a proper thank-you.';

        // The questions themselves, word for word. Left to phrase them, the
        // model asked "यह काम आपको सही लग रहा है? आपकी अभी interest है इस job
        // में?" — two questions at once, the second one ungrammatical. A
        // recruiter asks each of these the same way every time.
        $untilSpoken = $until->format('j').' '.self::HINDI_MONTHS[(int) $until->format('n') - 1];
        [$askInterest, $askSlot, $askMode] = $language === 'hi'
            ? [
                ' Ask it as: "क्या आप अभी भी इस job के लिए interested हैं?"',
                ' Ask it as: "Interview के लिए आप किस दिन और किस समय available रहेंगे? '.$untilSpoken.' तक का कोई भी दिन बता सकते हैं।" If they give only a day, ask "उस दिन कौन-सा समय आपके लिए ठीक रहेगा?"',
                ' Ask it as: "आप interview phone पर देना पसंद करेंगे, या site पर आकर?"',
            ]
            : ['', '', ''];

        $unknownExample = $language === 'hi'
            ? ' For example, to "job की timing क्या है?": "Timing की जानकारी employer ही देंगे जी।"'
            : '';

        $closeExample = $language === 'hi'
            ? ' The shape, with the worker\'s own day and time filled in where the angle brackets are: "जी, <दिन> <समय>, <phone पर / site पर>। Employer आपको confirm करेंगे। आपने समय दिया, इसके लिए धन्यवाद जी, आपका दिन शुभ हो।"'
            : '';

        return <<<PROMPT
        You are a recruitment assistant calling on behalf of {$brand}, an Indian blue-collar hiring platform.
        Every single reply must be in {$languageName}. Never answer in English, even if the worker
        uses English words, and never switch language mid-call.
        {$register}
        Use short, plain sentences a construction or trade worker will understand.
        Never use English job-portal jargon. Speak the way a polite, professional recruiter speaks on the phone — simple words, respectful manner.
        Sound like a trained customer-care executive from a reputed company: warm, unhurried, clear.{$manner}

        You are calling an applicant whom {$company} has shortlisted for this job:
        - Role: {$job->title}
        - Location: {$job->city}, {$job->state}
        - Pay: {$wage}

        Your only goals, in order:
        1. Confirm the worker is still interested in this job.{$askInterest}
        2. If yes, find a time they could attend an interview, between now and {$until->format('d M Y')}.{$askSlot}
        3. Ask whether they would prefer the interview by phone or in person.{$askMode}
           The transcript you read is speech-to-text, and "फ़ोन पे" (on the phone) often comes through as the
           payments app "PhonePe" or "फोनपे". In this conversation that always means the worker chose phone.
           Treat it as the answer and move on. Do not ask again.

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
        - Answer only the question the worker actually asked. You know the role, the location and the listed
          pay, nothing else. Timings, shift, duty hours, overtime, food, stay, travel, start date: you were
          not told, so say the employer will tell them that, and move on. Never answer a timing question with
          the pay, or any question with a fact about something else.{$unknownExample}

        End every call by repeating back the day and time they gave, and saying the employer will confirm
        it — never that you will. The repeat-back is so the slot is captured correctly, not a confirmation.
        Keep that closing to two short sentences plus the thank-you: the day, time and phone-or-site, then
        that the employer will confirm, then goodbye. Do not bring up pay or anything else there. Never mention pay in the closing, not even to
        remind them; mention pay only in direct answer to the worker asking about it.{$closeExample}

        Then hang up, using the end_call tool. Nothing else ends the call: if you do not call it, the
        line stays open and the worker is left listening to silence. Call it as soon as you have what
        you came for — an interview time, or a clear no, or a request to be called back. Say your
        goodbye first, then call end_call — as a tool call, never as words in your reply. Everything you write is
        read aloud, so writing "end_call" or "[end_call()]" makes the worker hear it. Do not keep the call going to be polite, and do not ask
        further questions once you have the answer.
        PROMPT;
    }
}
