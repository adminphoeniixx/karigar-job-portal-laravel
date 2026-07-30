<?php

namespace App\Services;

use App\Models\WorkerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Saves, replaces and removes a worker's resume PDF.
 *
 * The file lives on the private 'local' disk (same treatment as KYC documents —
 * a resume carries personal data and must never be publicly reachable, so it is
 * deliberately NOT pushed to BunnyCDN the way avatars are). The extracted text
 * is cached on the profile at upload time for {@see AiMatcher}.
 */
class ResumeStore
{
    public const DISK = 'local';

    public const DIRECTORY = 'resumes';

    public function __construct(private ResumeParser $parser) {}

    /**
     * Store (or replace) the profile's resume. Returns false when the PDF had no
     * readable text layer — the caller should reject the upload in that case,
     * since a resume the matcher cannot read is useless to it.
     */
    public function put(WorkerProfile $profile, UploadedFile $file): bool
    {
        $text = $this->parser->text($file);

        if ($text === null) {
            return false;
        }

        $this->deleteFile($profile);

        $profile->forceFill([
            'resume_path' => $file->store(self::DIRECTORY, self::DISK),
            'resume_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'resume_text' => $text,
            'resume_uploaded_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Drop the resume and everything derived from it.
     */
    public function forget(WorkerProfile $profile): void
    {
        $this->deleteFile($profile);

        $profile->forceFill([
            'resume_path' => null,
            'resume_name' => null,
            'resume_text' => null,
            'resume_uploaded_at' => null,
        ])->save();
    }

    private function deleteFile(WorkerProfile $profile): void
    {
        if ($profile->resume_path && Storage::disk(self::DISK)->exists($profile->resume_path)) {
            Storage::disk(self::DISK)->delete($profile->resume_path);
        }
    }
}
