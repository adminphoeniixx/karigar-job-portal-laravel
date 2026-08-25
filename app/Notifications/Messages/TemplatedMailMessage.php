<?php

namespace App\Notifications\Messages;

/**
 * What a notification hands to TemplatedMailChannel: which admin-editable
 * template to render, and the values its {{ placeholders }} need.
 *
 * Deliberately not a Mailable. The body lives in the email_templates table so
 * an admin can reword it without a deploy, which is the whole point of
 * TemplatedMailer — this class only carries the key and the data to it.
 */
class TemplatedMailMessage
{
    /**
     * @param  array<string, string|int|null>  $data
     */
    public function __construct(
        public string $key,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, string|int|null>  $data
     */
    public static function create(string $key, array $data = []): self
    {
        return new self($key, $data);
    }
}
