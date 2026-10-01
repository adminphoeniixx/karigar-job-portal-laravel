<?php

namespace App\Support;

/**
 * Which identity documents a user is asked for, and what may stand in for one
 * they do not have. A worker gives Aadhaar + PAN; an employer is asked by its
 * business type — a person or a proprietor proves who they are, a firm or a
 * company proves the business (its PAN + GST).
 *
 * Any document may be marked "I don't have it": an alternate ID, its photo and
 * a reason go to the admin instead, who verifies it by hand.
 */
class KycRequirements
{
    /** Business types an employer picks on the verification screen. */
    public const BUSINESS_TYPES = [
        'individual' => 'Individual (hiring for myself)',
        'proprietorship' => 'Sole proprietorship',
        'partnership' => 'Partnership firm',
        'llp' => 'LLP',
        'private_limited' => 'Private limited company',
        'public_limited' => 'Public limited company',
        'other' => 'Trust / Society / Other',
    ];

    /**
     * Each document: its label, the request field holding its number, and
     * the format that number must have.
     *
     * @var array<string, array{label: string, number_field: string, pattern: string, hint: string}>
     */
    public const DOCUMENTS = [
        'aadhaar' => [
            'label' => 'Aadhaar card',
            'number_field' => 'aadhaar_number',
            'pattern' => '^\d{12}$',
            'hint' => '12 digits',
        ],
        'pan' => [
            'label' => 'PAN card',
            'number_field' => 'pan_number',
            'pattern' => '^[A-Z]{5}[0-9]{4}[A-Z]$',
            'hint' => 'ABCDE1234F',
        ],
        'gst' => [
            'label' => 'GST certificate',
            'number_field' => 'gstin',
            'pattern' => '^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$',
            'hint' => '22ABCDE1234F1Z5',
        ],
    ];

    /**
     * What may be sent instead of each document.
     *
     * @var array<string, array<string, string>>
     */
    public const ALTERNATES = [
        'aadhaar' => [
            'voter_id' => 'Voter ID',
            'driving_licence' => 'Driving licence',
            'passport' => 'Passport',
            'ration_card' => 'Ration card',
            'other' => 'Other government ID',
        ],
        'pan' => [
            'form_60' => 'Form 60 (no PAN declaration)',
            'voter_id' => 'Voter ID',
            'driving_licence' => 'Driving licence',
            'passport' => 'Passport',
            'other' => 'Other government ID',
        ],
        'gst' => [
            'udyam' => 'Udyam registration',
            'shop_establishment' => 'Shop & establishment licence',
            'trade_licence' => 'Trade licence',
            'incorporation' => 'Certificate of incorporation / partnership deed',
            'other' => 'Other business proof',
        ],
    ];

    /** Business types whose verification is about the person, not a registered entity. */
    private const PERSONAL = ['individual'];

    /** Business types run by one owner: the owner's ID plus the business's GST. */
    private const PROPRIETOR = ['proprietorship'];

    /**
     * Documents asked of a worker, or of an employer of this business type.
     *
     * @return list<string>
     */
    public static function documentsFor(string $role, ?string $businessType = null): array
    {
        if ($role !== 'employer') {
            return ['aadhaar', 'pan'];
        }

        return match (true) {
            in_array($businessType, self::PERSONAL, true) => ['aadhaar', 'pan'],
            in_array($businessType, self::PROPRIETOR, true) => ['aadhaar', 'pan', 'gst'],
            // A firm or company (or an older submission made before business
            // types existed): the business's own PAN and GST.
            default => ['pan', 'gst'],
        };
    }

    /**
     * Everything the apps need to build the verification screen, for /reference.
     *
     * @return array<string, mixed>
     */
    public static function reference(): array
    {
        return [
            'business_types' => collect(self::BUSINESS_TYPES)
                ->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'documents' => self::documentsFor('employer', $key),
                ])
                ->values(),
            'worker_documents' => self::documentsFor('worker'),
            'documents' => collect(self::DOCUMENTS)
                ->map(fn (array $doc, string $key) => [
                    'key' => $key,
                    'label' => $doc['label'],
                    'number_field' => $doc['number_field'],
                    'pattern' => $doc['pattern'],
                    'hint' => $doc['hint'],
                    'alternates' => collect(self::ALTERNATES[$key])
                        ->map(fn (string $label, string $alt) => ['key' => $alt, 'label' => $label])
                        ->values(),
                ])
                ->values(),
        ];
    }
}
