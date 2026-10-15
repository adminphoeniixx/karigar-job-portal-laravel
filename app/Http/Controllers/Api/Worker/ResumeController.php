<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResumeUploadRequest;
use App\Services\ResumeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resume upload for the worker app — the mobile-side mirror of
 * {@see \App\Http\Controllers\ResumeController}.
 */
class ResumeController extends Controller
{
    public function __construct(private ResumeStore $store) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'resume' => $request->user()->workerProfile?->resumeSummary(),
        ]);
    }

    /**
     * The resume PDF itself, so the app can show the karigar what employers
     * will open — an upload or one made in the resume builder.
     */
    public function file(Request $request): StreamedResponse
    {
        $profile = $request->user()->workerProfile()->first();

        abort_unless($this->store->available($profile), 404);

        return Storage::disk(ResumeStore::DISK)->download($profile->resume_path, $profile->resume_name ?: 'resume.pdf');
    }

    public function store(ResumeUploadRequest $request): JsonResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        if (! $this->store->put($profile, $request->file('resume'))) {
            return response()->json([
                'message' => __('We could not read any text in that PDF. If it is a scan or photo, please upload a text PDF instead.'),
                'errors' => ['resume' => [__('No readable text found in the PDF.')]],
            ], 422);
        }

        return response()->json([
            'message' => __('Resume uploaded — new applications will be matched against it.'),
            'resume' => $profile->resumeSummary(),
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->store->forget($request->user()->workerProfile()->firstOrCreate([]));

        return response()->json([
            'message' => __('Resume removed.'),
            'resume' => null,
        ]);
    }
}
