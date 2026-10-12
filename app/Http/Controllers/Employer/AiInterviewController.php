<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\ScreeningCall;
use App\Support\AiInterviewList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every AI screening call across the employer's jobs, on one page.
 */
class AiInterviewController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->validate(['filter' => ['nullable', 'in:'.implode(',', AiInterviewList::FILTERS)]])['filter'] ?? 'upcoming';

        $page = AiInterviewList::page($request->user(), $filter);
        $page->getCollection()->transform(fn (ScreeningCall $call) => AiInterviewList::row($call));

        return Inertia::render('employer/AiInterviews', [
            'interviews' => $page,
            'filter' => $filter,
            'counts' => AiInterviewList::counts($request->user()),
        ]);
    }
}
