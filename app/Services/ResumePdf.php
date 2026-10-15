<?php

namespace App\Services;

use App\Models\WorkerProfile;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a resume from {@see ResumeWriter} as an A4 PDF, in the design the
 * karigar picked (resources/views/resumes/templates/<template>.blade.php).
 *
 * mPDF rather than the dompdf the invoices use: dompdf does not shape
 * Devanagari, so a Hindi resume came out with its matras in the wrong place.
 * mPDF's bundled FreeSans covers Latin and Devanagari and is shaped with
 * OpenType layout, so one font serves both languages.
 */
class ResumePdf
{
    /** Page margins per template, in mm: [left, right, top, bottom]. */
    private const MARGINS = [
        // Modern prints its sidebar in the left band, so the text starts after it.
        'modern' => [80, 14, 16, 14],
        'classic' => [18, 18, 16, 14],
        'bold' => [16, 16, 16, 14],
    ];

    public function __construct(private ResumePhoto $photo) {}

    /**
     * @param  array<string, mixed>  $resume
     */
    public function render(array $resume, ?WorkerProfile $profile = null): string
    {
        $template = in_array($resume['template'] ?? null, ResumeWriter::TEMPLATES, true) ? $resume['template'] : 'modern';
        $accent = in_array($resume['accent'] ?? null, ResumeWriter::ACCENTS, true) ? $resume['accent'] : ResumeWriter::ACCENTS[0];
        [$left, $right, $top, $bottom] = self::MARGINS[$template];

        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $pdf = new Mpdf([
            'tempDir' => $tmp,
            'format' => 'A4',
            'margin_left' => $left,
            'margin_right' => $right,
            'margin_top' => $top,
            'margin_bottom' => $bottom,
            'margin_header' => 0,
            'margin_footer' => 6,
            'default_font' => 'freesans',
        ]);

        $hi = ($resume['language'] ?? 'en') === 'hi';

        $pdf->SetTitle(($resume['name'] ?? 'Resume').' — Resume');
        $pdf->SetCreator('Super Karigar');
        $pdf->WriteHTML(view("resumes.templates.{$template}", [
            'r' => $resume,
            'hi' => $hi,
            'L' => $this->labels($hi),
            'accent' => $accent,
            'tint' => $this->mix($accent, 0.9),
            'soft' => $this->mix($accent, 0.75),
            'photo' => ($resume['show_photo'] ?? true) ? $this->photo->path($profile) : null,
            'initials' => $this->initials((string) ($resume['name'] ?? '')),
            'verified' => (bool) $profile?->user?->isKycVerified(),
            'stats' => $this->stats($resume, $hi),
            'band' => $template === 'modern' ? $this->band() : null,
        ])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * A4-proportioned page background with the Modern template's ink sidebar
     * down the left. mPDF has no full-height column, so it is an image; it is
     * drawn once and kept beside mPDF's cache.
     */
    private function band(): string
    {
        $file = storage_path('framework/cache/mpdf/resume-band-v1.png');

        if (! is_file($file)) {
            $img = imagecreatetruecolor(840, 1188);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            // 68 mm of the page's 210 mm.
            imagefilledrectangle($img, 0, 0, (int) round(840 * 68 / 210) - 1, 1187, imagecolorallocate($img, 0x24, 0x1D, 0x19));
            imagepng($img, $file);
            imagedestroy($img);
        }

        return $file;
    }

    /**
     * Section headings and fixed words, in the resume's language.
     *
     * @return array<string, string>
     */
    private function labels(bool $hi): array
    {
        return $hi ? [
            'contact' => 'संपर्क', 'summary' => 'परिचय', 'skills' => 'हुनर', 'experience' => 'काम का अनुभव',
            'education' => 'पढ़ाई और ट्रेनिंग', 'languages' => 'भाषाएँ', 'more' => 'और जानकारी',
            'salary' => 'अपेक्षित वेतन', 'verified' => 'Super Karigar पर वेरिफ़ाइड', 'phone' => 'फ़ोन',
            'email' => 'ईमेल', 'location' => 'पता', 'profile' => 'प्रोफ़ाइल', 'made' => 'Super Karigar पर बना · superkarigar.com',
        ] : [
            'contact' => 'Contact', 'summary' => 'Profile', 'skills' => 'Skills', 'experience' => 'Work experience',
            'education' => 'Education & training', 'languages' => 'Languages', 'more' => 'More about me',
            'salary' => 'Expected salary', 'verified' => 'Verified on Super Karigar', 'phone' => 'Phone',
            'email' => 'Email', 'location' => 'Location', 'profile' => 'Profile', 'made' => 'Made on Super Karigar · superkarigar.com',
        ];
    }

    /**
     * The headline numbers some templates print as tiles.
     *
     * @param  array<string, mixed>  $resume
     * @return list<array{value: string, label: string}>
     */
    private function stats(array $resume, bool $hi): array
    {
        $stats = [];

        if ($years = (int) ($resume['experience_years'] ?? 0)) {
            $stats[] = ['value' => $years.'+', 'label' => $hi ? 'साल का अनुभव' : ($years === 1 ? 'Year experience' : 'Years experience')];
        }
        if ($jobs = count($resume['experience'] ?? [])) {
            $stats[] = ['value' => (string) $jobs, 'label' => $hi ? 'जगह काम किया' : ($jobs === 1 ? 'Workplace' : 'Workplaces')];
        }
        if ($skills = count($resume['skills'] ?? [])) {
            $stats[] = ['value' => (string) $skills, 'label' => $hi ? 'हुनर' : 'Skills'];
        }
        if ($resume['relocate'] ?? false) {
            $stats[] = ['value' => $hi ? 'हाँ' : 'Yes', 'label' => $hi ? 'शहर बदलने को तैयार' : 'Ready to relocate'];
        }

        return array_slice($stats, 0, 3);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim(preg_replace('/\(.*?\)/u', '', $name) ?? $name)) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice(array_filter($parts), 0, 2))));
    }

    /**
     * Blend a hex colour toward white: 0 keeps it, 1 is white.
     */
    private function mix(string $hex, float $amount): string
    {
        [$r, $g, $b] = array_map('hexdec', str_split(ltrim($hex, '#'), 2));

        return sprintf('#%02x%02x%02x', ...array_map(fn ($c) => (int) round($c + (255 - $c) * $amount), [$r, $g, $b]));
    }
}
