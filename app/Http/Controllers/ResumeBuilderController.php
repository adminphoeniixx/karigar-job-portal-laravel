<?php

namespace App\Http\Controllers;

use App\Services\ResumeStore;
use App\Services\ResumeWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The AI resume builder on the web: a form for what a resume needs, an AI
 * draft the karigar can edit, and a save that turns it into their resume PDF.
 * The app's mirror is {@see Api\Worker\ResumeBuilderController}.
 */
class ResumeBuilderController extends Controller
{
    public function __construct(private ResumeWriter $writer, private ResumeStore $store) {}

    public function edit(Request $request): Response
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        return Inertia::render('worker/ResumeBuilder', [
            'form' => $this->writer->form($request->user()),
            // The resume saved last time, so the preview can open on it.
            'saved' => $profile->resume_builder['resume'] ?? null,
            'resume' => $profile->resumeSummary(),
            'limits' => ['jobs' => ResumeWriter::MAX_JOBS, 'skills' => ResumeWriter::MAX_SKILLS],
            'templates' => ResumeWriter::TEMPLATES,
            'accents' => ResumeWriter::ACCENTS,
            // For the photo switch and the strength meter: the photo itself
            // is changed on the profile page.
            'photoUrl' => $profile->avatar_url,
            'verified' => $request->user()->isKycVerified(),
        ]);
    }

    /**
     * Draft a resume from the form. Called with fetch, so it validates by hand
     * and answers 422 JSON — a ValidationException here would redirect.
     */
    public function draft(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ResumeWriter::inputRules());

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $this->store->rememberInput($request->user()->workerProfile()->firstOrCreate([]), $validator->validated());

        return response()->json(['resume' => $this->writer->draft($validator->validated())]);
    }

    /**
     * The PDF as it would be saved, for the live preview. Validated by hand
     * for the same reason as draft(): it is fetched, not visited.
     */
    public function preview(Request $request): JsonResponse|HttpResponse
    {
        $validator = Validator::make($request->all(), [...ResumeWriter::inputRules(), ...ResumeWriter::resumeRules()]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $pdf = $this->store->preview(
            $request->user()->workerProfile()->firstOrCreate([]),
            collect($data)->except('resume')->all(),
            $data['resume'],
        );

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="resume-preview.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([...ResumeWriter::inputRules(), ...ResumeWriter::resumeRules()]);

        $this->store->build(
            $request->user()->workerProfile()->firstOrCreate([]),
            collect($data)->except('resume')->all(),
            $data['resume'],
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Resume saved — employers see it and new applications are matched against it.'),
        ]);

        return to_route('worker.resume.builder');
    }
}
