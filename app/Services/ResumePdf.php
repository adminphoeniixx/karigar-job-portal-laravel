<?php

namespace App\Services;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders a resume from {@see ResumeWriter} as a one-or-two page A4 PDF.
 *
 * mPDF rather than the dompdf the invoices use: dompdf does not shape
 * Devanagari, so a Hindi resume came out with its matras in the wrong place.
 * mPDF's bundled FreeSans covers Latin and Devanagari and is shaped with
 * OpenType layout, so one font serves both languages.
 */
class ResumePdf
{
    /**
     * @param  array<string, mixed>  $resume
     */
    public function render(array $resume): string
    {
        $tmp = storage_path('framework/cache/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        $pdf = new Mpdf([
            'tempDir' => $tmp,
            'format' => 'A4',
            'margin_left' => 16,
            'margin_right' => 16,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'default_font' => 'freesans',
        ]);

        $pdf->SetTitle(($resume['name'] ?? 'Resume').' — Resume');
        $pdf->SetCreator('Super Karigar');
        $pdf->WriteHTML(view('resumes.pdf', [
            'r' => $resume,
            'hi' => ($resume['language'] ?? 'en') === 'hi',
        ])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }
}
