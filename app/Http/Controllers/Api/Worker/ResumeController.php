<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResumeUploadRequest;
use App\Services\ResumeParser;
use App\Services\ResumeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'resume' => $this->payload($request),
        ]);
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
            'resume' => $this->payload($request),
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

    /**
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        $profile = $request->user()->workerProfile;

        if ($profile?->resume_path === null) {
            return null;
        }

        return [
            'name' => $profile->resume_name,
            'uploaded_at' => $profile->resume_uploaded_at?->toIso8601String(),
            'uploaded_ago' => $profile->resume_uploaded_at?->diffForHumans(),
            // Length of the text the matcher will actually read, so the app can
            // reassure the worker their resume was parsed.
            'characters' => mb_strlen((string) $profile->resume_text),
            'max_characters' => ResumeParser::MAX_CHARS,
        ];
    }
}
