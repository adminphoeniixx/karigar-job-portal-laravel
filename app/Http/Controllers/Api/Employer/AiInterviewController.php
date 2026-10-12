<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Models\ScreeningCall;
use App\Support\AiInterviewList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "AI Interviews" screen: every AI screening call across the employer's
 * jobs, booked ones first by time, with what each finished call found out.
 */
class AiInterviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['filter' => ['nullable', 'in:'.implode(',', AiInterviewList::FILTERS)]])['filter'] ?? 'upcoming';

        $page = AiInterviewList::page($request->user(), $filter);
        $page->getCollection()->transform(fn (ScreeningCall $call) => AiInterviewList::row($call));

        return response()->json([
            'interviews' => $page,
            'filter' => $filter,
            'counts' => AiInterviewList::counts($request->user()),
        ]);
    }
}
