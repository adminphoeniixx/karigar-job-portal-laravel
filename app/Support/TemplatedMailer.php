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
        // No address, or a phone-OTP placeholder address (<phone>@phone.karigar)
        // that has no real inbox — skip rather than send a guaranteed bounce,
        // which would waste quota and hurt sender reputation.
        if (empty($email) || str_ends_with($email, '@phone.karigar')) {
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

    /**
     * Cache the template's ATTRIBUTES, not the model.
     *
     * config/cache.php sets 'serializable_classes' => false, so every cache
     * store unserialises with allowed_classes: false — any cached object comes
     * back as __PHP_Incomplete_Class. Caching the Eloquent model therefore threw
     * a TypeError from here on the first cache hit, 500-ing whichever request was
     * sending mail (applying to a job, shortlisting, status changes). A plain
     * array survives that round trip; re-hydrate an unsaved model from it so
     * render() and is_active still work for the caller.
     */
    protected static function template(string $key): ?EmailTemplate
    {
        $attributes = Cache::remember(
            "email_template.$key",
            now()->addHour(),
            fn () => EmailTemplate::where('key', $key)->first()?->only([
                'key', 'name', 'description', 'subject', 'body_html', 'placeholders', 'is_active',
            ]),
        );

        return $attributes === null ? null : EmailTemplate::make($attributes);
    }

    /**
     * Bust the cached copy of a template (called after an admin edit).
     */
    public static function forget(string $key): void
    {
        Cache::forget("email_template.$key");
    }
}
