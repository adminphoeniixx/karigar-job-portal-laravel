<?php

namespace App\Notifications\Messages;

/**
 * Simple value object a notification returns from its toFcm() method to
 * describe the push it wants delivered.
 */
class FcmMessage
{
    /**
     * @param  array<string, string|int|null>  $data  Deep-link / payload data sent alongside the notification.
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    /**
     * @param  array<string, string|int|null>  $data
     */
    public static function create(string $title, string $body, array $data = []): self
    {
        return new self($title, $body, $data);
    }
}
