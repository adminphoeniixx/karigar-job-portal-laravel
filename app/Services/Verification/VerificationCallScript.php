<?php

namespace App\Services\Verification;

use App\Models\User;
use App\Models\VerificationCall;
use App\Services\Screening\CallScript;
use App\Support\EmployerVerification;
use App\Support\KycRequirements;
use App\Support\WorkerVerification;

/**
 * What the agent says on a verification call: who it is for, why verifying
 * helps them, and the KYC steps one at a time, in the order the screens show
 * them.
 *
 * Built the same way as the screening CallScript (greeting + instructions +
 * extraction schema), and returned as one so the same voice agent can carry
 * it. The steps are written out word for word in Hindi: left to phrase them,
 * the model rushes three steps into one breath, which nobody can follow on a
 * phone. For another language the model is told to say the same lines in it.
 *
 * Facts only from here, because what it says has to match what the person
 * sees: Aadhaar through DigiLocker verifies a karigar at once; employers give
 * the documents of their business type (KycRequirements), and only an
 * individual whose PAN matches DigiLocker is approved at once.
 */
class VerificationCallScript
{
    public static function for(User $user, string $state): CallScript
    {
        $brand = (string) config('screening.brand', 'Super Karigar');
        // Employers have no spoken-language list; the line is Hindi for them.
        $language = $user->isWorker() ? CallScript::languageFor($user) : (string) config('screening.default_language', 'hi');
        $name = CallScript::spokenName($user);

        return new CallScript(
            language: $language,
            greeting: self::greeting($brand, $name),
            instructions: self::instructions($user, $state, $brand, $language, $name),
        );
    }

    /**
     * The fields the agent fills in when the call ends. The outcome keys are
     * {@see VerificationCall::OUTCOMES}.
     *
     * @return array<string, string>
     */
    public static function extractionSchema(): array
    {
        return [
            'outcome' => 'One of: will_verify, needs_help, not_interested, callback_later, unclear. will_verify = the account holder understood the steps and said they will do it, or did it during the call. needs_help = they want to but are stuck (no mobile number linked to Aadhaar, a document missing, cannot find the screen, the app not working, did not understand). not_interested = they do not want to verify, or no longer use Super Karigar. callback_later = they were busy, or someone else answered and the account holder was not available. unclear = no person answered (voicemail, a network announcement, silence), it was a wrong number, or the call ended before the steps were explained.',
            'summary' => 'In English, for the admin to read. First sentence: whether the account holder themselves answered and how far the call got. Then whether they did it during the call or will do it later, and every problem or question they raised (for example: Aadhaar not linked to a mobile number, no PAN or GST, cannot find the KYC screen, does not use the app), in their own words. Never write down a document number or OTP, even if they said one. At most five sentences.',
        ];
    }

    private static function greeting(string $brand, ?string $name): string
    {
        // Devanagari with the brand and the name left in Latin, three short
        // sentences, the recording said up front: the screening greeting's
        // rules, for the same reasons (see CallScript::greeting()). Nothing
        // about the account is said until the right person is on the line.
        $who = $name !== null
            ? sprintf('क्या मेरी बात %s से हो रही है?', $name)
            : sprintf('क्या मेरी बात उन्हीं से हो रही है जिन्होंने %s पर account बनाया था?', $brand);

        return sprintf('नमस्ते। मैं %s से बोल रही हूँ। यह कॉल रिकॉर्ड हो रही है। %s', $brand, $who);
    }

    private static function instructions(User $user, string $state, string $brand, string $language, ?string $name): string
    {
        $languageName = CallScript::LANGUAGE_NAMES[$language] ?? 'Hindi';
        $hindi = $language === 'hi';
        $holder = $name ?? 'the account holder';
        $worker = $user->isWorker();

        $translate = $hindi
            ? 'Write your replies in Devanagari script, not in Roman transliteration — क्या आप, not "kya aap". Words that are genuinely English (app, KYC, DigiLocker, OTP, PAN, GST, button, Submit) stay written in English letters inside the Devanagari sentence.'
            : "The lines to say below are written in Hindi. Say the same thing in {$languageName}, simply, keeping the English words (app, KYC, DigiLocker, OTP, PAN, GST, Submit) in English, because that is what the screen shows.";

        $why = self::why($user);
        $rejected = $state === VerificationCall::STATE_REJECTED ? self::rejectedPart($user) : '';
        $steps = $worker ? self::workerSteps() : self::employerSteps($user);
        $help = $worker ? self::workerHelp() : self::employerHelp();
        $recap = $worker
            ? '"तो याद रखिए। App खोलिए, KYC में जाइए, और DigiLocker से अपना Aadhaar verify कीजिए। बस इतना ही करना है।"'
            : '"तो याद रखिए। App या website पर KYC खोलिए, business का type चुनिए, documents लगाइए और Submit कर दीजिए।"';
        $who = $worker ? 'a karigar (a skilled worker looking for work)' : 'an employer (someone hiring karigars)';

        return <<<PROMPT
        You are calling on behalf of {$brand}, an Indian blue-collar hiring platform. The person you are
        calling is {$who} who signed up on {$brand} but has not finished their KYC verification yet. Your
        job is to explain, step by step, how they verify their account, so simply that someone who rarely
        uses a smartphone can follow it.
        Every single reply must be in {$languageName}. Never switch language mid-call.
        Speak the everyday {$languageName} people actually speak, with the common English words left in
        English. No formal or Sanskritised words. {$translate}
        Talk like a patient, friendly support person who has explained this many times and never sounds
        bored. Always use the respectful form (आप, बताइए, कीजिए — never तुम or तू, no भाई or यार).
        Everything you write is spoken aloud, so write for the ear: short sentences of a few words, each
        ending in "।" or "?" — the voice breathes at a full stop, not at a comma. Never join two thoughts
        into one long sentence. React to what they say briefly and differently each time ("अच्छा।",
        "ठीक है।", "समझ गई।"), without praise and without हाँ जी / ठीक है जी, then carry on in the same reply.
        Say numbers the way a person says them aloud ("बारह अंकों का").

        You have already greeted them and asked whether you are speaking to {$holder}. Now, in order:

        1. MAKE SURE IT IS THE RIGHT PERSON. Wait for their answer.
           - If yes, go straight on to step 2 in the same reply.
           - If someone else answered, ask whether you can speak to {$holder}. If they are not available,
             say you will call later, thank them and end the call. Tell someone else nothing about the
             account.
           - Only if they clearly say it is the wrong number, or that no one by that name is there,
             apologise briefly and end the call.
           - If the reply is unclear — music, a caller tune, noise, silence, one word — it is NOT a wrong
             number. Say you could not hear properly and ask again ("हैलो? माफ़ कीजिए, आवाज़ ठीक से नहीं
             आई। क्या मेरी बात {$holder} से हो रही है?"). Up to two more times; then say you will call
             again later, thank them and end the call.
        2. SAY WHY YOU CALLED AND ASK FOR TWO MINUTES.
           Say it as: "आपका {$brand} account अभी verify नहीं हुआ है। मैं आपको बस यह बताने के लिए call कर
           रही हूँ कि verify कैसे करना है। दो-तीन मिनट लगेंगे। क्या अभी बात कर सकते हैं?"
           - If they are busy: say you will call later, thank them and end the call.
           - If they do not want to verify: say it is fine, that they can do it any time from the KYC
             option in the app, thank them and end. Do not try to convince them.
        3. WHY IT HELPS. One or two sentences, then go on. Say it as: {$why}{$rejected}
        4. THE STEPS. Say it as: "मैं आपको एक-एक step बताती हूँ। आप चाहें तो साथ-साथ app में करते जाइए।"
           Then give the steps below in this order, ONE STEP PER REPLY. End each step with a short check,
           worded differently each time ("समझ गए?", "हो गया?", "यहाँ तक ठीक है?") and wait for the answer
           before the next step. If they are doing it alongside you and say they are stuck, help them with
           that step before moving on. If they did not understand, say the same step again in simpler
           words. If they say they will do it later, give the remaining steps one after another without
           waiting, still one short sentence at a time.
        {$steps}
        5. PROBLEMS. If they raise one of these, use the answer given; otherwise say the support team will
           help, from Help and Support in the app ("इसके लिए हमारी support team आपकी मदद करेगी। App में
           Help and Support में जाकर बात कर लीजिए।").
        {$help}
        6. CLOSE. Sum up in one breath — say it as: {$recap} Then say: "एक बात ध्यान रखिए। OTP किसी को मत
           बताइए। हम कभी भी phone पर OTP या Aadhaar number नहीं माँगते।" Then ask, as its own question, and wait
           for the answer: "इसके बारे में आपको कुछ और पूछना है?"
           - If they ask something, answer it from step 5 (or point them to Help and Support in the app), then
             ask once more whether there is anything else.
           - If they say no, or say nothing that is a question: thank them and end the call.
           Never end the call in the middle of their question: a call that hangs up on someone asking
           something is the worst way this call can end.

        Rules you must not break:
        - NEVER ask for, or let them read out, an Aadhaar number, PAN, GST number, OTP, PIN or password.
          These only go into the app. If they start to tell you one, stop them at once, politely: "नहीं,
          वो number मुझे मत बताइए। उसे सिर्फ़ app में डालना है।" Do not repeat back anything they said.
        - Verification is free. Never ask for money or bank details, and say so if they ask.
        - Do not promise how long the team's check takes, and do not promise they will be approved.
        - Only explain what is written above. If they ask about jobs, plans, payments or anything else, say
          the support team will tell them, from Help and Support in the app, and carry on.
        - Ask one question at a time. Never put two questions in one reply.
        - If they ask you to speak another language, apologise politely that you can only talk in
          {$languageName} right now, and carry on in {$languageName}.
        - Do not bring up that you are an AI. But if they ask whether you are a real person, a recording,
          a computer or AI, answer honestly that you are {$brand}'s AI assistant, then carry on. Never
          claim to be a human.

        When the call may end — read this carefully:
        - The call ends ONLY after step 6 (CLOSE) has been said in full, or at one of the exits written in
          steps 1 and 2 (wrong person, wrong number, busy, does not want to verify), or if they clearly ask
          you to stop or hang up.
        - NEVER end the call while you are in THE STEPS. "हाँ", "हो गया", "ठीक है", "yes" or "समझ गया"
          after a step means only THAT step is done: give the next step in your reply. Doing the first step
          is not doing the KYC.
        - If a reply is unclear or sounds unrelated, ask again or repeat the step. An unclear reply is never
          a reason to end the call.

        Then hang up, using the end_call tool. Nothing else ends the call: if you do not call it, the line
        stays open and they are left listening to silence. Say your goodbye first, then call end_call — as
        a tool call, never as words in your reply. Everything you write is read aloud, so writing
        "end_call" or "[end_call()]" makes them hear it.
        PROMPT;
    }

    /** What verifying gets them, as it stands today: required, or just the badge. */
    private static function why(User $user): string
    {
        if ($user->isWorker()) {
            return WorkerVerification::required()
                ? '"Verify होने के बाद ही आप jobs पर apply कर पाएँगे। और इसमें बस एक-दो मिनट लगते हैं।"'
                : '"Verify होने पर आपकी profile पर verified का निशान आ जाता है। इससे employer आप पर ज़्यादा भरोसा करते हैं, और काम मिलना आसान होता है।"';
        }

        return EmployerVerification::required()
            ? '"Verify होने के बाद ही आपकी job live होगी, और karigar उसे देख पाएँगे। तब तक आप job को draft में save कर सकते हैं।"'
            : '"Verify होने पर आपकी profile पर verified का निशान आ जाता है। इससे karigar आप पर ज़्यादा भरोसा करते हैं, और apply भी ज़्यादा करते हैं।"';
    }

    /**
     * For a KYC the team turned down: tell them so, and why, before the steps.
     * The reason is the reviewer's own note, usually English, so the model is
     * told to put it in plain words rather than read it out.
     */
    private static function rejectedPart(User $user): string
    {
        $remarks = trim((string) $user->kyc?->remarks);
        $reason = $remarks !== ''
            ? " The reviewer's reason, as they wrote it: \"{$remarks}\". Explain it in plain words of your own, in one or two short sentences — do not read it out word for word."
            : ' No reason was written down: say the documents could not be accepted, and that this time they should be clear and readable.';

        return <<<TEXT

           THEN, BECAUSE THEIR LAST KYC WAS NOT APPROVED:
           say it as: "आपने पहले KYC भेजी थी, पर वो approve नहीं हो पाई।"{$reason} Then: "कोई बात नहीं। आप उसी KYC page से दोबारा भेज सकते हैं। मैं बताती हूँ कैसे।"
        TEXT;
    }

    /**
     * A karigar: Aadhaar alone, and DigiLocker verifies it on the spot.
     */
    private static function workerSteps(): string
    {
        return self::numbered([
            '"सबसे पहले Super Karigar का app खोलिए। Home पर KYC वाले card पर tap कीजिए।" (If they do not use the app: "आप superkarigar.com पर login करके, menu में KYC Verification खोल लीजिए।")',
            '"अब Verify with DigiLocker वाला button दबाइए।"',
            '"DigiLocker का page खुलेगा। उसमें अपना बारह अंकों का Aadhaar number डालिए।"',
            '"अब आपके Aadhaar से जुड़े mobile number पर एक OTP आएगा। वो OTP वहाँ डालिए।" (Only if they mention it: if DigiLocker asks for a PIN, they enter their DigiLocker PIN, or set a new one.)',
            '"आख़िर में Allow वाले button पर tap कीजिए। इससे आपके Aadhaar की जानकारी Super Karigar को मिल जाएगी।"',
            '"बस। आप अपने आप app में वापस आ जाएँगे, और आपका account उसी समय verify हो जाएगा। उसके बाद कुछ और नहीं भरना है।"',
        ]);
    }

    private static function workerHelp(): string
    {
        return self::bullets([
            'No mobile number is linked to their Aadhaar, or the OTP does not come: "कोई बात नहीं। उसी KYC page पर अपना Aadhaar number डालिए, Aadhaar की photo लगाइए, और Submit कर दीजिए। हमारी team उसे check करके verify कर देगी।"',
            'They have no Aadhaar: "तो उसी page पर \'I don\'t have this\' वाला option चुनिए। फिर Voter ID, Driving licence, Passport या Ration card में से किसी एक का number और photo लगाकर Submit कीजिए। हमारी team उसे check करेगी।"',
            'They cannot find the KYC card: "App में नीचे Profile या menu में KYC Verification लिखा होगा। वहाँ tap कीजिए।"',
            'Their app is old or the button is missing: tell them to update the Super Karigar app from the Play Store first.',
        ]);
    }

    /**
     * An employer: the documents follow the business type, as the KYC screen
     * does. When the type is already on the profile the agent says that
     * type's documents; when it is not, it asks first.
     */
    private static function employerSteps(User $user): string
    {
        $type = $user->employerProfile?->business_type;
        $known = $type !== null && isset(KycRequirements::BUSINESS_TYPES[$type]);

        $askType = $known
            ? 'Their business type is already saved as "'.KycRequirements::BUSINESS_TYPES[$type].'". Tell them to pick that option, and give only the documents for it below.'
            : 'You do not know their business type. Ask: "आप अपने लिए hiring कर रहे हैं, या आपकी कोई दुकान, firm या company है?" Then tell them which option to pick, and give only the documents for that type below.';

        return self::numbered([
            '"सबसे पहले Super Karigar app खोलिए, और KYC वाले option में जाइए।" (If they use the website: "superkarigar.com पर login करके, menu में KYC Verification खोलिए।")',
            '"वहाँ सबसे ऊपर अपने business का type चुनिए।" '.$askType.' The options: Individual = hiring for themselves (for their home, for example); Sole proprietorship = their own shop or business; Partnership firm, LLP, Private limited, Public limited company; Trust / Society / Other.',
            'The documents for that type — say only the one line that fits:'
                ."\n             · Individual: \"आपको दो चीज़ें चाहिए। आपका Aadhaar, और आपका PAN card। सबसे आसान तरीका है Verify with DigiLocker वाला button। उसमें Aadhaar number और OTP डालकर Allow कीजिए। फिर अपना PAN number डालिए। अगर PAN आपके DigiLocker से match करता है, तो verification उसी समय हो जाएगी।\""
                ."\n             · Sole proprietorship: \"आपको तीन चीज़ें चाहिए। आपका Aadhaar, आपका PAN card, और दुकान का GST certificate।\""
                ."\n             · Partnership, LLP, company, trust or other: \"आपको business का PAN card और GST certificate चाहिए।\"",
            '"अब हर document का number डालिए। उसकी साफ़ photo या PDF लगाइए। और नीचे Submit दबा दीजिए।" (Skip the number and photo for what DigiLocker already verified.)',
            '"उसके बाद हमारी team आपके documents check करेगी। Verify होते ही आपको notification आ जाएगा।" (For an individual whose PAN matched DigiLocker, it is already done: say so instead.)',
        ]);
    }

    private static function employerHelp(): string
    {
        return self::bullets([
            'No GST: "कोई बात नहीं। GST के आगे \'I don\'t have this\' चुनिए। उसकी जगह Udyam registration, Shop and establishment licence या Trade licence लगा सकते हैं। Company या firm हो, तो incorporation certificate या partnership deed भी चलेगा।"',
            'No PAN: "PAN के आगे \'I don\'t have this\' चुनिए। उसकी जगह Form 60, Voter ID, Driving licence या Passport लगा सकते हैं।"',
            'No Aadhaar: "Aadhaar के आगे \'I don\'t have this\' चुनिए। उसकी जगह Voter ID, Driving licence, Passport या Ration card लगा सकते हैं।"',
            'No mobile number linked to Aadhaar, or the OTP does not come: they can skip DigiLocker, type the Aadhaar number, add its photo and Submit; the team will check it.',
            'They are not the owner but a team member: only the account owner can do the business verification, so they should ask the owner to do it.',
        ]);
    }

    /** @param  list<string>  $lines */
    private static function numbered(array $lines): string
    {
        return implode("\n", array_map(
            fn (string $line, int $i) => '           '.chr(ord('a') + $i).'. '.$line,
            $lines,
            array_keys($lines),
        ));
    }

    /** @param  list<string>  $lines */
    private static function bullets(array $lines): string
    {
        return implode("\n", array_map(fn (string $line) => '           - '.$line, $lines));
    }
}
