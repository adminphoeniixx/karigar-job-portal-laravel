<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Pulls plain text out of an uploaded resume PDF so {@see AiMatcher} can read it.
 *
 * Uses smalot/pdfparser (pure PHP — no pdftotext binary, so it behaves the same
 * on the deploy box as locally). Extraction happens once, at upload time; the
 * text is stored on the worker profile and scoring never re-opens the file.
 */
class ResumeParser
{
    /**
     * Hard cap on stored text. Resumes for these roles are short, and the cap
     * keeps a padded or malicious PDF from blowing up the scoring prompt.
     */
    public const MAX_CHARS = 8000;

    /**
     * Extract text from the upload, or null when the PDF yields nothing usable
     * (image-only scans are the common case — those have no text layer).
     */
    public function text(UploadedFile $file): ?string
    {
        try {
            $raw = (new Parser)->parseFile($file->getRealPath())->getText();
        } catch (Throwable $e) {
            Log::warning('ResumeParser could not read the PDF: '.$e->getMessage());

            return null;
        }

        return $this->clean($raw);
    }

    /**
     * Collapse the whitespace soup a PDF text layer usually produces, then trim
     * to MAX_CHARS. Returns null when nothing meaningful is left.
     */
    private function clean(string $raw): ?string
    {
        // Drop control characters, normalise newlines, squeeze runs of spaces
        // and blank lines — PDF extraction is full of stray \r and double spaces.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $raw) ?? $raw;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        // Fewer than ~30 characters means we got page furniture, not a resume.
        if (mb_strlen($text) < 30) {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
