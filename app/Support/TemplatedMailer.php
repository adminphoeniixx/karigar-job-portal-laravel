<?php

namespace App\Support;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TemplatedMailer
{
    /**
     * Look up an admin-managed template by key, render it with the given data
     * and send it. Silently no-ops if the template is missing, inactive, or the
     * recipient has no address — and never lets a mail failure break the caller.
     *
     * @param  array<string, string|int|null>  $data
     */
    public static function send(string $key, ?string $email, array $data = []): void
    {
        if (empty($email)) {
            return;
        }

        $template = static::template($key);

        if (! $template || ! $template->is_active) {
            return;
        }

        $data['app_name'] ??= config('app.name');

        $rendered = $template->render($data);

        try {
            Mail::to($email)->send(new TemplatedMail($rendered['subject'], $rendered['body']));
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected static function template(string $key): ?EmailTemplate
    {
        return Cache::remember(
            "email_template.$key",
            now()->addHour(),
            fn () => EmailTemplate::where('key', $key)->first(),
        );
    }

    /**
     * Bust the cached copy of a template (called after an admin edit).
     */
    public static function forget(string $key): void
    {
        Cache::forget("email_template.$key");
    }
}
