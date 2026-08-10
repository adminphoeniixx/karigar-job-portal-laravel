<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * One-shot chat completions against whichever LLM the app is pointed at.
 *
 * Provider-agnostic: any OpenAI-compatible chat-completions endpoint works
 * (DigitalOcean Serverless Inference, Groq, OpenAI, local Ollama, …) via the
 * 'digitalocean' / 'openai-compatible' provider, and Anthropic's native
 * Messages API via 'anthropic'. Swap providers by editing config/services.php
 * ('ai' block) or the AI_* env vars — no code change.
 *
 * Callers are expected to handle the no-key case themselves ({@see configured()})
 * and to catch failures: every feature built on this falls back to something
 * deterministic rather than showing the employer an error.
 */
class AiClient
{
    public function configured(): bool
    {
        return (bool) config('services.ai.key');
    }

    /**
     * Send one system+user turn and return the model's raw text.
     *
     * @param  bool  $json  Ask an OpenAI-compatible provider for a JSON object.
     *                      Anthropic has no equivalent switch, so prompts that
     *                      need JSON must say so in the system message too.
     *
     * @throws \Throwable when the provider is unreachable or returns an error
     */
    public function chat(string $system, string $user, int $maxTokens = 400, float $temperature = 0.2, bool $json = false): string
    {
        return config('services.ai.provider') === 'anthropic'
            ? $this->anthropic($system, $user, $maxTokens, $temperature)
            : $this->openAiCompatible($system, $user, $maxTokens, $temperature, $json);
    }

    private function openAiCompatible(string $system, string $user, int $maxTokens, float $temperature, bool $json): string
    {
        $payload = [
            'model' => config('services.ai.model'),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
        ];

        if ($json) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $payload['messages'] = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];

        $response = Http::timeout((int) config('services.ai.timeout', 45))
            ->withToken((string) config('services.ai.key'))
            ->acceptJson()
            ->post(rtrim((string) config('services.ai.base_url'), '/').'/chat/completions', $payload)
            ->throw();

        return (string) data_get($response->json(), 'choices.0.message.content', '');
    }

    private function anthropic(string $system, string $user, int $maxTokens, float $temperature): string
    {
        $response = Http::timeout((int) config('services.ai.timeout', 45))
            ->withHeaders([
                'x-api-key' => (string) config('services.ai.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->post(rtrim((string) config('services.ai.base_url', 'https://api.anthropic.com/v1'), '/').'/messages', [
                'model' => config('services.ai.model', 'claude-haiku-4-5'),
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ])->throw();

        return (string) data_get($response->json(), 'content.0.text', '');
    }
}
