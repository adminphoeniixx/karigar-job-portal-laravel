<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResumeUploadRequest;
use App\Models\JobApplication;
use App\Services\ResumeStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worker resume upload/removal, plus the employer-side download.
 *
 * Resumes are private: only the worker who owns one and an employer who has
 * received an application from that worker may fetch the file.
 */
class ResumeController extends Controller
{
    public function __construct(private ResumeStore $store) {}

    public function store(ResumeUploadRequest $request): RedirectResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        if (! $this->store->put($profile, $request->file('resume'))) {
            return back()->withErrors([
                'resume' => __('We could not read any text in that PDF. If it is a scan or photo, please upload a text PDF instead.'),
            ]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => __('Resume uploaded — new applications will be matched against it.'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);
        $this->store->forget($profile);

        return back()->with('toast', ['type' => 'success', 'message' => __('Resume removed.')]);
    }

    /**
     * Stream a worker's resume to an employer who has their application.
     */
    public function download(Request $request, JobApplication $application): StreamedResponse
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
