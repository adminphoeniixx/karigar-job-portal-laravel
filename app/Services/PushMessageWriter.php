<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drafts push-notification copy from a one-line idea, so an admin sending a
 * broadcast is not writing "New jobs near you" for the fiftieth time.
 *
 * Runs on {@see AiClient}. With no key, or when the provider is down, it falls
 * back to a small deterministic set built from the idea itself — worse copy,
 * but the admin is never left staring at an error toast with nothing to send.
 *
 * Deliberately NOT cached, unlike {@see JobDescriptionWriter}. A job title
 * describes one fixed thing, so the same title may as well return the same two
 * drafts all day. Here the admin presses Generate *again* precisely because
 * they did not like what came back, and a day-long cache would hand them the
 * identical list every time.
 */
class PushMessageWriter
{
    /** Default number of variations. Enough to choose from without a wall of text. */
    public const COUNT = 5;

    /** Ceiling on what the caller may ask for — each one costs tokens. */
    public const MAX_COUNT = 20;

    /**
     * Android collapses a push title past roughly this, and iOS is not much
     * kinder. Copy that gets cut mid-word reads as a bug to the worker.
     */
    public const TITLE_LIMIT = 50;

    /** Same for the body, which is the line that has to carry the actual news. */
    public const BODY_LIMIT = 120;

    /** @var array<string, string> How each choice is described to the model. */
    private const LANGUAGES = [
        'hinglish' => 'Hinglish — Hindi written in Latin script, mixed with the English words Indian workers actually use (job, site, salary, apply). This is the default and what most karigars read most comfortably.',
        'hindi' => 'Hindi, in Devanagari script.',
        'english' => 'Simple English, short words only.',
    ];

    public function __construct(private AiClient $ai) {}

    /**
     * @return list<array{title: string, body: string}>
     */
    public function suggest(string $idea, int $count = self::COUNT, string $language = 'hinglish'): array
    {
        $idea = trim($idea);

        if ($idea === '') {
            return [];
        }

        $count = max(1, min(self::MAX_COUNT, $count));
        $language = isset(self::LANGUAGES[$language]) ? $language : 'hinglish';

        if (! $this->ai->configured()) {
            return $this->fallback($idea, $count);
        }

        try {
            $raw = $this->ai->chat(
                $this->systemPrompt($count, $language),
                "Idea: {$idea}\n\nReturn the JSON now.",
                // Each variation is two short strings, but JSON punctuation and
                // a 20-item ceiling add up — hence per-item rather than fixed.
                maxTokens: 250 + ($count * 90),
                temperature: 0.9,
                json: true,
            );

            $variations = $this->parse($raw, $count);
        } catch (Throwable $e) {
            Log::warning('PushMessageWriter failed, using fallback: '.$e->getMessage());

            $variations = [];
        }

        return $variations !== [] ? $variations : $this->fallback($idea, $count);
    }

    private function systemPrompt(int $count, string $language): string
    {
        $languageRule = self::LANGUAGES[$language];
        $titleLimit = self::TITLE_LIMIT;
        $bodyLimit = self::BODY_LIMIT;

        return <<<TXT
        You write push notifications for Super Karigar, an Indian marketplace
        where blue-collar workers — plumbers, electricians, masons, carpenters,
        drivers, tailors — find work. The people reading these are karigars on
        a phone, often mid-job.

        Write {$count} different notifications for the idea below.

        Rules:
        - Language: {$languageRule}
        - Title: at most {$titleLimit} characters. Body: at most {$bodyLimit}.
          These are hard limits — a phone truncates anything longer.
        - Say the actual thing. No "Exciting news!", no "Don't miss out".
        - Invent nothing that was not in the idea: no wages, no counts, no
          employer names, no dates, no city unless the idea named one.
        - Plain text only. No markdown, no emoji, no ALL CAPS.
        - Make the {$count} genuinely different from each other — vary the angle
          and the opening, not just the word order.

        Reply with ONLY a JSON object in exactly this shape:
        {"variations":[{"title":"...","body":"..."}]}
        TXT;
    }

    /**
     * Pull the variations out of the model's reply, tolerating prose wrapped
     * around the JSON and dropping anything malformed rather than the whole
     * batch — four usable drafts beat an error.
     *
     * @return list<array{title: string, body: string}>
     */
    private function parse(string $raw, int $count): array
    {
        $json = null;

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
        }

        if (! is_array($json) || ! is_array($json['variations'] ?? null)) {
            return [];
        }

        $out = [];

        foreach ($json['variations'] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
            $body = is_string($item['body'] ?? null) ? trim($item['body']) : '';

            if ($title === '' || $body === '') {
                continue;
            }

            // The model is told the limits and mostly respects them; this is
            // the guard for when it does not.
            $out[] = [
                'title' => mb_substr($title, 0, self::TITLE_LIMIT),
                'body' => mb_substr($body, 0, self::BODY_LIMIT),
            ];

            if (count($out) === $count) {
                break;
            }
        }

        return $out;
    }

    /**
     * What the admin gets with no model behind this. Plain on purpose: it is
     * a starting point to edit, and it should not pretend to be written copy.
     *
     * @return list<array{title: string, body: string}>
     */
    private function fallback(string $idea, int $count): array
    {
        $short = mb_substr($idea, 0, self::BODY_LIMIT);

        $drafts = [
            ['title' => __('Super Karigar'), 'body' => $short],
            ['title' => mb_substr($idea, 0, self::TITLE_LIMIT), 'body' => __('Open the app to see more.')],
        ];

        return array_slice($drafts, 0, $count);
    }
}
