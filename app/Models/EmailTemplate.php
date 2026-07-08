<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $subject
 * @property string $body_html
 * @property array<int, string>|null $placeholders
 * @property bool $is_active
 */
class EmailTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'subject', 'body_html', 'placeholders', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Render the subject + body with the given data, replacing {{ placeholder }} tokens.
     *
     * @param  array<string, string|int|null>  $data
     * @return array{subject: string, body: string}
     */
    public function render(array $data): array
    {
        return [
            'subject' => $this->interpolate($this->subject, $data),
            'body' => $this->interpolate($this->body_html, $data),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $data
     */
    protected function interpolate(string $template, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn (array $m): string => (string) ($data[$m[1]] ?? ''),
            $template,
        ) ?? $template;
    }
}
