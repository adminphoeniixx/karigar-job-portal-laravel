<?php

namespace App\Services;

use App\Jobs\CheckJobSafety;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Checks a job about to go live for being harmful to the karigars who will
 * read it: a scam that asks them for money (deposit, registration fee,
 * "investment"), illegal or adult work, child labour, trafficking ("hand
 * over your passport"), fake work-from-home earnings, or hate.
 *
 * Two layers. A list of tell-tale phrases (English, Hindi, Hinglish) always
 * runs and catches the obvious; the AI model, when one is configured, reads
 * the job as a whole. Either one flagging it sends the job to admin review.
 *
 * Asked "strict", a model that is down or rate-limited throws, so the queued
 * check ({@see CheckJobSafety}) tries again later rather than
 * waving the job through on the phrase list. Otherwise (its last attempt)
 * only the phrase list decides, so a provider outage never stops honest
 * employers posting; the result says which way it was checked.
 */
class JobModerator
{
    /** Admin → Settings: "AI checks new jobs". */
    public const ENABLED_KEY = 'ai_job_check_enabled';

    /** The model read the job. */
    public const METHOD_AI = 'ai';

    /** No model configured, or it was down: only the phrase list ran. */
    public const METHOD_PHRASES = 'phrases';

    /**
     * Phrases that say a job is harmful, each with the reason shown to the
     * employer and the admin.
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        '/\b(registration|joining|security|processing|training|kit|uniform)\s*(fee|fees|charge|charges|money|deposit|amount)\b/i' => 'Asks karigars to pay money (registration/security fee)',
        '/\b(advance|deposit)\s*(payment|amount|money|jama)\b|\bpay\s+(first|in\s+advance)\b|pehle\s+paise|paise\s+jama|पैसे\s+जमा|पहले\s+पैसे|जमा\s+राशि/iu' => 'Asks karigars to pay money first',
        '/\b(invest(ment)?|double\s+your\s+money|crypto|bitcoin|trading\s+account|mlm|chain\s+system)\b/i' => 'Investment / money-making scheme, not a job',
        // Aadhaar at joining is ordinary; OTPs, PINs and cards are not.
        '/\b(otp|upi\s+pin|atm\s+(card|pin)|net\s*banking\s+password)\b.{0,30}\b(share|send|bhejo|de\s+do|batao)\b/i' => 'Asks for OTP or bank details',
        '/\bpassport\b.{0,40}\b(submit|jama|deposit|keep|rakh)/i' => 'Asks karigars to hand over their passport',
        '/\b(escort|massage\s+girls?|spa\s+girls?|call\s+girls?|adult\s+work|sex\s+work|dance\s+bar)\b/i' => 'Adult work',
        '/\b(ganja|charas|smack|drugs?\s+(supply|delivery)|liquor\s+smuggling|sharab\s+tasker)\b/i' => 'Illegal work',
        // "Kids wear stitching" is a trade, not child labour: only an explicit
        // child worker or an age under 14 counts.
        '/\bchild\s+labou?r|bal\s+mazdoo?r|बाल\s+मजदूर|\b(age|umar|उम्र)\s*:?\s*([6-9]|1[0-3])\s*(\+|to|-|se|years?|yrs?|saal|साल)/iu' => 'Child labour',
        '/\b(like|subscribe|review)\s+(videos?|youtube|pages?).{0,40}(earn|income|kamao)|\bearn\s+(rs\.?|₹)?\s*\d{4,}\s*(per|\/)\s*day\s+from\s+home/i' => 'Fake work-from-home earnings',
        '/\b(only|sirf)\s+(hindu|muslim|sikh|christian|upper\s+caste|brahmin)s?\b|\bno\s+(muslims?|dalits?|sc\/st)\b/i' => 'Discriminates by religion or caste',
    ];

    public function __construct(private AiClient $ai) {}

    public static function enabled(): bool
    {
        return Setting::bool(self::ENABLED_KEY, true);
    }

    /**
     * @param  array<string, mixed>  $job  the job's fields (title, description, skills, perks, fee…)
     * @param  bool  $strict  throw when the model cannot be asked, instead of falling back to the phrase list
     * @return array{flagged: bool, reasons: list<string>, method: string} method: "ai" | "phrases"
     *
     * @throws Throwable when strict and the model is down
     */
    public function check(array $job, bool $strict = false): array
    {
        if (! self::enabled()) {
            return ['flagged' => false, 'reasons' => [], 'method' => self::METHOD_PHRASES];
        }

        $text = $this->text($job);
        $reasons = [];

        foreach (self::PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $text) === 1) {
                $reasons[] = $reason;
            }
        }

        $model = $this->askModel($text, $strict);

        foreach ($model ?? [] as $reason) {
            $reasons[] = $reason;
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'flagged' => $reasons !== [],
            'reasons' => $reasons,
            'method' => $model === null ? self::METHOD_PHRASES : self::METHOD_AI,
        ];
    }

    /**
     * The model's reasons to hold the job, none when it looks fine, null when
     * the model could not be asked.
     *
     * @return list<string>|null
     */
    private function askModel(string $text, bool $strict): ?array
    {
        if (! $this->ai->configured()) {
            return null;
        }

        try {
            $raw = $this->ai->chat($this->systemPrompt(), $text, maxTokens: 250, temperature: 0.0, json: true);
        } catch (Throwable $e) {
            if ($strict) {
                throw $e;
            }

            Log::warning('JobModerator: model unavailable, phrase list only: '.$e->getMessage());

            return null;
        }

        $decoded = json_decode((string) preg_replace('/^```(json)?|```$/m', '', trim($raw)), true);

        if (! is_array($decoded) || ($decoded['verdict'] ?? 'ok') !== 'review') {
            return [];
        }

        return collect($decoded['reasons'] ?? [])
            ->map(fn ($reason) => trim((string) $reason))
            ->filter()
            ->take(3)
            ->map(fn (string $reason) => 'AI: '.mb_substr($reason, 0, 160))
            ->values()
            ->all();
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You review job posts on an Indian marketplace for blue-collar workers and craftsmen
            (karigars), many of them poor and new to the internet. Decide whether a post could harm
            the workers who read it. Hold ("review") a post that is or looks like:
            - a scam asking workers for money: registration, security, training or kit fees,
              deposits, "investment", advance payment;
            - illegal work, drugs, smuggling, adult or sexual work;
            - child labour, or trafficking (taking passports or documents, overseas jobs with fees);
            - fake earnings (work from home, like/subscribe tasks, unrealistically high pay);
            - asking for OTPs, bank or card details;
            - hate or discrimination by religion, caste or gender.
            Ordinary jobs are "ok", even badly written ones, in any language (English, Hindi, Hinglish).
            A modest joining fee the employer declared in the form is not by itself a reason.
            Reply with JSON only: {"verdict": "ok" | "review", "reasons": ["short reason", ...]}.
            PROMPT;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function text(array $job): string
    {
        $list = fn ($value) => is_array($value) ? implode(', ', array_filter(array_map('strval', $value))) : (string) $value;

        return implode("\n", array_filter([
            'Title: '.($job['title'] ?? ''),
            'Category: '.($job['category'] ?? ''),
            'Skills: '.$list($job['skills'] ?? []),
            'Perks: '.$list($job['perks'] ?? []),
            'Wage: '.trim(($job['wage_min'] ?? '').' - '.($job['wage_max'] ?? '').' '.($job['wage_type'] ?? '')),
            ! empty($job['requires_worker_fee']) ? 'Worker pays a joining fee of ₹'.($job['worker_fee_amount'] ?? '?') : null,
            'Description: '.($job['description'] ?? ''),
        ]));
    }
}
