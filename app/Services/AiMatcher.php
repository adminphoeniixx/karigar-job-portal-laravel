<?php

namespace App\Services;

use App\Models\JobListing;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores a job applicant against a job's requirements using an LLM.
 *
 * Provider-agnostic: any OpenAI-compatible chat-completions endpoint works
 * (DigitalOcean Serverless Inference, Groq, OpenAI, local Ollama, …) via the
 * 'digitalocean' / 'openai-compatible' provider, and Anthropic's native
 * Messages API via 'anthropic'. Swap providers by editing config/services.php
 * ('ai' block) or the AI_* env vars — no code change.
 *
 * When no API key is configured the service falls back to a deterministic
 * skill-overlap heuristic, so local/dev and tests work without a key (same
 * pattern as {@see Msg91Service}).
 *
 * @phpstan-type Score array{score:int, recommendation:string, summary:string, matched_skills:list<string>, red_flags:list<string>}
 */
class AiMatcher
{
    private const RECOMMENDATIONS = ['strong_match', 'good_match', 'maybe', 'weak'];

    public function configured(): bool
    {
        return (bool) config('services.ai.key');
    }

    /**
     * Score one applicant against a job. Returns the structured result, or the
     * heuristic fallback when the model is unavailable/unconfigured.
     *
     * @return Score
     */
    public function score(JobListing $job, User $worker): array
    {
        $jobText = $this->jobText($job);
        $candidateText = $this->candidateText($worker);

        if (! $this->configured()) {
            return $this->heuristic($job, $worker);
        }

        try {
            $raw = config('services.ai.provider') === 'anthropic'
                ? $this->callAnthropic($jobText, $candidateText)
                : $this->callOpenAiCompatible($jobText, $candidateText);

            return $this->normalize($raw, $job, $worker);
        } catch (Throwable $e) {
            Log::warning('AiMatcher scoring failed, using heuristic: '.$e->getMessage());

            return $this->heuristic($job, $worker);
        }
    }

    /**
     * OpenAI-compatible chat completion (DigitalOcean, Groq, OpenAI, Ollama…).
     */
    private function callOpenAiCompatible(string $jobText, string $candidateText): string
    {
        $response = Http::timeout((int) config('services.ai.timeout', 45))
            ->withToken((string) config('services.ai.key'))
            ->acceptJson()
            ->post(rtrim((string) config('services.ai.base_url'), '/').'/chat/completions', [
                'model' => config('services.ai.model'),
                'temperature' => 0.2,
                'max_tokens' => 400,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    ['role' => 'user', 'content' => $this->userPrompt($jobText, $candidateText)],
                ],
            ])->throw();

        return (string) data_get($response->json(), 'choices.0.message.content', '');
    }

    /**
     * Anthropic native Messages API (used when AI_PROVIDER=anthropic).
     */
    private function callAnthropic(string $jobText, string $candidateText): string
    {
        $response = Http::timeout((int) config('services.ai.timeout', 45))
            ->withHeaders([
                'x-api-key' => (string) config('services.ai.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post(rtrim((string) config('services.ai.base_url', 'https://api.anthropic.com/v1'), '/').'/messages', [
                'model' => config('services.ai.model', 'claude-haiku-4-5'),
                'max_tokens' => 400,
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $this->userPrompt($jobText, $candidateText)],
                ],
            ])->throw();

        return (string) data_get($response->json(), 'content.0.text', '');
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        You are a hiring assistant for an Indian blue-collar jobs marketplace (plumbers,
        electricians, masons, drivers, helpers, etc.). Score how well a WORKER matches a
        JOB. Judge on skills overlap, relevant experience, expected wage vs the job's
        budget, and location proximity. Do not penalise the worker for a short profile.
        Reply with ONLY a JSON object, no prose, in exactly this shape:
        {"score":<0-100 integer>,"recommendation":"strong_match|good_match|maybe|weak",
        "summary":"<one short sentence, max 20 words>","matched_skills":["..."],
        "red_flags":["..."]}
        recommendation buckets: 80-100 strong_match, 60-79 good_match, 40-59 maybe, 0-39 weak.
        TXT;
    }

    private function userPrompt(string $jobText, string $candidateText): string
    {
        return "JOB:\n{$jobText}\n\nWORKER:\n{$candidateText}\n\nReturn the JSON now.";
    }

    private function jobText(JobListing $job): string
    {
        $wage = $this->wageString($job->wage_min, $job->wage_max, $job->wage_type);
        $skills = is_array($job->skills) ? implode(', ', $job->skills) : '—';
        $location = trim(implode(', ', array_filter([$job->city, $job->state]))) ?: '—';

        return implode("\n", [
            "Title: {$job->title}",
            'Category: '.($job->category ?: '—'),
            "Skills required: {$skills}",
            "Wage: {$wage}",
            "Location: {$location}",
            'Description: '.trim((string) $job->description),
        ]);
    }

    private function candidateText(User $worker): string
    {
        $profile = $worker->workerProfile;
        $skills = is_array($profile?->skills) ? implode(', ', $profile->skills) : '—';
        $langs = is_array($profile?->spoken_languages) ? implode(', ', $profile->spoken_languages) : '—';
        $location = trim(implode(', ', array_filter([$profile?->city, $profile?->state]))) ?: '—';
        $wage = $profile?->expected_wage !== null
            ? '₹'.number_format((float) $profile->expected_wage).($profile->wage_type ? ' / '.$profile->wage_type : '')
            : '—';

        return implode("\n", [
            'Name: '.$worker->name,
            "Skills: {$skills}",
            'Experience: '.($profile?->experience_years !== null ? $profile->experience_years.' years' : '—'),
            'Education: '.($profile?->education ?: '—'),
            "Expected wage: {$wage}",
            "Location: {$location}",
            "Languages: {$langs}",
            'Bio: '.(trim((string) $profile?->bio) ?: '—'),
        ]);
    }

    private function wageString(?string $min, ?string $max, ?string $type): string
    {
        if ($min === null && $max === null) {
            return 'Not disclosed';
        }
        $range = implode('–', array_filter([
            $min !== null ? '₹'.number_format((float) $min) : null,
            $max !== null ? '₹'.number_format((float) $max) : null,
        ]));

        return $range.($type ? ' / '.$type : '');
    }

    /**
     * Coerce the model's raw text into a validated Score, extracting the first
     * JSON object if the model wrapped it in prose.
     *
     * @return Score
     */
    private function normalize(string $raw, JobListing $job, User $worker): array
    {
        $json = null;
        if (preg_match('/\{.*\}/s', $raw, $m)) {
            $json = json_decode($m[0], true);
        }

        if (! is_array($json) || ! isset($json['score'])) {
            return $this->heuristic($job, $worker);
        }

        $score = (int) max(0, min(100, (int) $json['score']));
        $recommendation = in_array($json['recommendation'] ?? '', self::RECOMMENDATIONS, true)
            ? $json['recommendation']
            : $this->bucket($score);

        return [
            'score' => $score,
            'recommendation' => $recommendation,
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 200),
            'matched_skills' => $this->stringList($json['matched_skills'] ?? []),
            'red_flags' => $this->stringList($json['red_flags'] ?? []),
        ];
    }

    /**
     * Deterministic skill-overlap score used when no model is available.
     *
     * @return Score
     */
    private function heuristic(JobListing $job, User $worker): array
    {
        $jobSkills = collect(is_array($job->skills) ? $job->skills : [])->map(fn ($s) => mb_strtolower(trim((string) $s)));
        $profile = $worker->workerProfile;
        $workerSkills = collect(is_array($profile?->skills) ? $profile->skills : [])->map(fn ($s) => mb_strtolower(trim((string) $s)));

        $matched = $jobSkills->intersect($workerSkills);
        $skillScore = $jobSkills->isNotEmpty() ? ($matched->count() / $jobSkills->count()) * 70 : 35;

        // Small bonuses: same city, some experience.
        $sameCity = $profile !== null && $profile->city !== null && $job->city !== null
            && mb_strtolower($profile->city) === mb_strtolower($job->city);
        $experience = $profile !== null ? (int) ($profile->experience_years ?? 0) : 0;
        $locationBonus = $sameCity ? 20 : 0;
        $expBonus = min(10, $experience * 2);

        $score = (int) round(min(100, $skillScore + $locationBonus + $expBonus));

        return [
            'score' => $score,
            'recommendation' => $this->bucket($score),
            'summary' => $matched->isNotEmpty()
                ? 'Matches '.$matched->count().' of '.$jobSkills->count().' required skills.'
                : 'Basic profile match (AI model offline).',
            'matched_skills' => array_values($matched->all()),
            'red_flags' => [],
        ];
    }

    private function bucket(int $score): string
    {
        return match (true) {
            $score >= 80 => 'strong_match',
            $score >= 60 => 'good_match',
            $score >= 40 => 'maybe',
            default => 'weak',
        };
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn ($v) => is_string($v) && trim($v) !== '')
            ->map(fn ($v) => trim($v))
            ->all());
    }
}
