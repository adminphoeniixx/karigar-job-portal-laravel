<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drafts job descriptions from the title an employer just typed, so posting a
 * job does not stall on a blank textarea.
 *
 * Runs on {@see AiClient}; with no key (or when the provider is down) it falls
 * back to a deterministic template built from the same fields, which is still
 * a usable starting point — the employer edits either way.
 */
class JobDescriptionWriter
{
    /** How many drafts to offer. Two is enough to pick a tone without a wall of text. */
    public const COUNT = 2;

    /** Same wording gets the same drafts for a day — the LLM call is the expensive part. */
    public const CACHE_TTL = 86400;

    /** Languages the drafts can be written in: English, or Hindi in Devanagari. */
    public const LANGUAGES = ['en', 'hi'];

    public function __construct(private AiClient $ai) {}

    /**
     * @param  list<string>  $skills
     * @return list<string>
     */
    public function suggest(string $title, ?string $category = null, array $skills = [], ?string $city = null, ?string $state = null, string $language = 'en'): array
    {
        $title = trim($title);
        $language = in_array($language, self::LANGUAGES, true) ? $language : 'en';

        if ($title === '') {
            return [];
        }

        if (! $this->ai->configured()) {
            return [$this->template($title, $category, $skills, $city, $state, $language)];
        }

        $key = 'job-description:'.md5(mb_strtolower(implode('|', [$title, $category, implode(',', $skills), $city, $state, $language])));

        /** @var list<string> $cached */
        $cached = Cache::remember($key, self::CACHE_TTL, function () use ($title, $category, $skills, $city, $state, $language): array {
            try {
                $raw = $this->ai->chat(
                    $this->systemPrompt($language),
                    $this->userPrompt($title, $category, $skills, $city, $state),
                    maxTokens: 700,
                    temperature: 0.7,
                    json: true,
                );

                $drafts = $this->parse($raw);
            } catch (Throwable $e) {
                Log::warning('JobDescriptionWriter failed, using template: '.$e->getMessage());

                $drafts = [];
            }

            return $drafts !== []
                ? $drafts
                : [$this->template($title, $category, $skills, $city, $state, $language)];
        });

        return $cached;
    }

    private function systemPrompt(string $language): string
    {
        $count = self::COUNT;

        // Hindi as a karigar reads it: Devanagari, everyday words, the usual
        // English work words left in English.
        $writing = $language === 'hi'
            ? 'plain simple Hindi written in Devanagari script, the everyday Hindi a karigar speaks; keep common English work words (machine, design, order) in English'
            : 'plain simple English a worker can read easily';

        return <<<TXT
        You write job descriptions for an Indian marketplace of skilled karigars:
        handmade crafts such as weaving, embroidery, knitting, pottery, wood
        carving and tailoring.
        Given a job title and whatever else the employer has filled in so far,
        write {$count} different descriptions the employer can post as-is.
        Rules:
        - 50 to 90 words each, in {$writing}.
        - Cover the actual work, who should apply (experience/tools), and timing.
        - Invent nothing specific that was not given: no wages, no company name,
          no phone number, no address beyond the city given.
        - Plain text only. No markdown, no bullet characters, no headings.
        - Vary the two: one straightforward, one warmer and more inviting.
        Reply with ONLY a JSON object in exactly this shape:
        {"suggestions":["<first description>","<second description>"]}
        TXT;
    }

    /**
     * @param  list<string>  $skills
     */
    private function userPrompt(string $title, ?string $category, array $skills, ?string $city, ?string $state): string
    {
        $lines = ["Job title: {$title}"];

        if ($category) {
            $lines[] = "Category: {$category}";
        }
        if ($skills !== []) {
            $lines[] = 'Skills required: '.implode(', ', $skills);
        }

        $location = trim(implode(', ', array_filter([$city, $state])));
        if ($location !== '') {
            $lines[] = "Location: {$location}";
        }

        return implode("\n", $lines)."\n\nReturn the JSON now.";
    }

    /**
     * Pull the suggestion strings out of the model's reply, tolerating prose
     * wrapped around the JSON.
     *
     * @return list<string>
     */
    private function parse(string $raw): array
    {
        $json = null;

        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
        }

        if (! is_array($json) || ! is_array($json['suggestions'] ?? null)) {
            return [];
        }

        return array_values(collect($json['suggestions'])
            ->filter(fn ($s) => is_string($s) && trim($s) !== '')
            // A padded reply must not become a 5k-character textarea.
            ->map(fn (string $s) => mb_substr(trim($s), 0, 1200))
            ->take(self::COUNT)
            ->all());
    }

    /**
     * Deterministic draft used when no model is available. Deliberately plain:
     * it should read as a starting point the employer will edit, not as filler.
     *
     * @param  list<string>  $skills
     */
    private function template(string $title, ?string $category, array $skills, ?string $city, ?string $state, string $language = 'en'): string
    {
        $location = trim(implode(', ', array_filter([$city, $state])));

        if ($language === 'hi') {
            $sentences = [
                "हमें {$title} की ज़रूरत है।",
                'काम में नियमित '.($category ? "{$category} का" : 'यह').' काम शामिल है'.($location !== '' ? ", {$location} में हमारी साइट पर" : '').'।',
            ];

            if ($skills !== []) {
                $sentences[] = 'आपको '.implode(', ', $skills).' का काम आना चाहिए।';
            }

            $sentences[] = 'अपने औज़ार और पहले का अनुभव रखने वालों को प्राथमिकता मिलेगी, लेकिन काम सीखने वाले नए लोग भी आवेदन कर सकते हैं।';
            $sentences[] = 'कृपया अपना अनुभव और आप कब से काम शुरू कर सकते हैं, बताएं।';

            return implode(' ', $sentences);
        }

        $work = $category ? mb_strtolower($category).' work' : 'this work';

        $sentences = [
            "We are looking for {$title}.",
            'The job involves regular '.$work.($location !== '' ? " at our site in {$location}" : '').'.',
        ];

        if ($skills !== []) {
            $sentences[] = 'You should know '.implode(', ', $skills).'.';
        }

        $sentences[] = 'Workers with their own basic tools and previous experience will be preferred, but freshers who can learn on the job may also apply.';
        $sentences[] = 'Please share your experience and when you can start.';

        return implode(' ', $sentences);
    }
}
