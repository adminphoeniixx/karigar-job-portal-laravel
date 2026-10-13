<?php

namespace App\Http\Controllers\Api\Worker;

use App\Http\Controllers\Controller;
use App\Services\ResumeStore;
use App\Services\ResumeWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The AI resume builder for the worker app — the mobile-side mirror of
 * {@see \App\Http\Controllers\ResumeBuilderController}: open the form, get an
 * AI draft, save the (edited) draft as the karigar's resume PDF.
 */
class ResumeBuilderController extends Controller
{
    public function __construct(private ResumeWriter $writer, private ResumeStore $store) {}

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        return response()->json([
            'form' => $this->writer->form($request->user()),
            'saved' => $profile->resume_builder['resume'] ?? null,
            'resume' => $profile->resumeSummary(),
            'languages' => ResumeWriter::LANGUAGES,
            'limits' => ['jobs' => ResumeWriter::MAX_JOBS, 'skills' => ResumeWriter::MAX_SKILLS],
        ]);
    }

    public function draft(Request $request): JsonResponse
    {
        $input = $request->validate(ResumeWriter::inputRules());

        $this->store->rememberInput($request->user()->workerProfile()->firstOrCreate([]), $input);

        return response()->json(['resume' => $this->writer->draft($input)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([...ResumeWriter::inputRules(), ...ResumeWriter::resumeRules()]);
        $profile = $request->user()->workerProfile()->firstOrCreate([]);

        $this->store->build($profile, collect($data)->except('resume')->all(), $data['resume']);

        return response()->json([
            'message' => __('Resume saved — employers see it and new applications are matched against it.'),
            'resume' => $profile->resumeSummary(),
            'saved' => $profile->resume_builder['resume'],
        ], 201);
    }
}
