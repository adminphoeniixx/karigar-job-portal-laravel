<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Services\ResumeStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Token-authenticated resume download for the employer app.
 *
 * The web route streams the same file behind a session; the app carries a
 * Bearer token instead, so it needs its own entry point. Same rule either way:
 * only an employer holding the worker's application may open it.
 *
 * @see \App\Http\Controllers\ResumeController::download()
 */
class ResumeController extends Controller
{
    public function download(JobApplication $application): StreamedResponse
    {
        $this->authorize('view', $application->job);

        $profile = $application->worker?->workerProfile;

        abort_if($profile?->resume_path === null, 404);
        abort_unless(Storage::disk(ResumeStore::DISK)->exists($profile->resume_path), 404);

        return Storage::disk(ResumeStore::DISK)->download(
            $profile->resume_path,
            $profile->resume_name ?: 'resume.pdf',
        );
    }
}
