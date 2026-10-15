<?php

namespace App\Services;

use App\Models\WorkerProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Saves, replaces and removes a worker's resume PDF.
 *
 * The file lives on the 'private' disk (same treatment as KYC documents): on
 * BunnyCDN storage, so it survives deploys, but under private/ and only ever
 * read through our controllers, never handed out as a CDN link the way avatars
 * are. The extracted text is cached on the profile at upload time for
 * {@see AiMatcher}.
 */
class ResumeStore
{
    public const DISK = 'private';

    public const DIRECTORY = 'resumes';

    public function __construct(
        private ResumeParser $parser,
        private ResumeWriter $writer,
        private ResumePdf $pdf,
    ) {}

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

        $this->save($profile, [
            'resume_path' => $file->store(self::DIRECTORY, self::DISK),
            'resume_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'resume_text' => $text,
            'resume_uploaded_at' => now(),
            'resume_source' => 'upload',
        ]);

        return true;
    }

    /**
     * Save a resume made in the AI builder as the profile's resume: render the
     * PDF, replace whatever file was there, and keep the form answers and the
     * resume so the builder opens filled in next time. The matcher reads the
     * resume's own text — there is no PDF to parse back.
     *
     * @param  array<string, mixed>  $input  the builder form answers
     * @param  array<string, mixed>  $resume  the resume, as edited in the preview
     */
    public function build(WorkerProfile $profile, array $input, array $resume): void
    {
        $input = $this->writer->input($input);
        $resume = $this->writer->clean($resume, $input);

        $path = self::DIRECTORY.'/'.Str::random(40).'.pdf';
        Storage::disk(self::DISK)->put($path, $this->pdf->render($resume, $profile));

        $this->deleteFile($profile);

        $name = trim(preg_replace('/[^\pL\pN ]+/u', '', (string) $resume['name']) ?? '');

        $this->save($profile, [
            'resume_path' => $path,
            'resume_name' => 'Resume'.($name !== '' ? ' - '.$name : '').'.pdf',
            'resume_text' => $this->writer->text($resume),
            'resume_uploaded_at' => now(),
            'resume_builder' => ['input' => $input, 'resume' => $resume],
            'resume_source' => 'ai',
        ]);
    }

    /**
     * Whether the profile's resume PDF can be served. A builder resume whose
     * file has gone (the disk was wiped) is drawn again from the answers kept
     * on the profile, so the karigar and employers never hit a dead link; an
     * uploaded PDF that has gone cannot be, and the caller should 404.
     */
    public function available(?WorkerProfile $profile): bool
    {
        if ($profile?->resume_path === null) {
            return false;
        }

        $disk = Storage::disk(self::DISK);

        if ($disk->exists($profile->resume_path)) {
            return true;
        }

        $resume = $profile->resume_builder['resume'] ?? null;

        if ($profile->resume_source !== 'ai' || ! is_array($resume)) {
            return false;
        }

        $disk->put($profile->resume_path, $this->pdf->render($resume, $profile));

        return $disk->exists($profile->resume_path);
    }

    /**
     * The PDF the karigar would get, without saving anything — the builder's
     * live preview re-renders it as they edit and switch designs.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $resume
     */
    public function preview(WorkerProfile $profile, array $input, array $resume): string
    {
        return $this->pdf->render($this->writer->clean($resume, $this->writer->input($input)), $profile);
    }

    /**
     * Keep the builder answers between visits, even before a resume is saved,
     * so a karigar who drafts and walks away does not lose what they typed.
     *
     * @param  array<string, mixed>  $input
     */
    public function rememberInput(WorkerProfile $profile, array $input): void
    {
        $this->save($profile, [
            'resume_builder' => [...($profile->resume_builder ?? []), 'input' => $this->writer->input($input)],
        ]);
    }

    /**
     * Drop the resume and everything derived from it. The builder answers
     * stay, so the karigar can make it again in one tap.
     */
    public function forget(WorkerProfile $profile): void
    {
        $this->deleteFile($profile);

        $this->save($profile, [
            'resume_path' => null,
            'resume_name' => null,
            'resume_text' => null,
            'resume_uploaded_at' => null,
            'resume_source' => null,
        ]);
    }

    private function deleteFile(WorkerProfile $profile): void
    {
        if ($profile->resume_path && Storage::disk(self::DISK)->exists($profile->resume_path)) {
            Storage::disk(self::DISK)->delete($profile->resume_path);
        }
    }

    /**
     * Write resume columns without re-indexing the profile: none of them are
     * in the worker search index, and a sync here made every resume save wait
     * on (or fail with) Typesense.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function save(WorkerProfile $profile, array $attributes): void
    {
        WorkerProfile::withoutSyncingToSearch(fn () => $profile->forceFill($attributes)->save());
    }
}
