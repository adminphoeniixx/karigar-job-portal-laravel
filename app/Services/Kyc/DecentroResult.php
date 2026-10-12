<?php

namespace App\Services\Kyc;

/**
 * What one Decentro call came back with: the result block, "no such number",
 * or an error that nobody but an admin can settle.
 */
final class DecentroResult
{
    /**
     * Response keys that mean the number itself is wrong (as opposed to the
     * service failing).
     */
    private const NOT_FOUND_KEYS = [
        'error_invalid_pan',
        'error_invalid_gstin',
        'error_no_record_found',
        'error_invalid_id_number',
        'error_pan_not_found',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly string $outcome,
        public readonly array $data = [],
        public readonly ?string $message = null,
        public readonly ?string $txnId = null,
        public readonly ?string $key = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function ok(array $data, ?string $txnId, ?string $key): self
    {
        return new self('ok', $data, null, $txnId, $key);
    }

    public static function notFound(?string $message, ?string $txnId, ?string $key): self
    {
        return new self('not_found', [], $message, $txnId, $key);
    }

    public static function error(?string $message, ?string $txnId = null, ?string $key = null): self
    {
        return new self('error', [], $message, $txnId, $key);
    }

    public static function isNotFoundKey(string $key): bool
    {
        return in_array($key, self::NOT_FOUND_KEYS, true);
    }

    public function isOk(): bool
    {
        return $this->outcome === 'ok';
    }

    public function isNotFound(): bool
    {
        return $this->outcome === 'not_found';
    }
}
