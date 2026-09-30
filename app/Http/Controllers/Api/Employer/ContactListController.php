<?php

namespace App\Http\Controllers\Api\Employer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Employer\ContactListController as WebContactListController;
use App\Models\JobApplication;
use App\Services\ContactList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My contacts" for the employer app — the same two lists as the web
 * Worker Database tabs: karigars unlocked from the database with the plan,
 * and applicants to the employer's own jobs.
 */
class ContactListController extends Controller
{
    public function database(Request $request): JsonResponse
    {
        $filters = $request->validate([
            ...WebContactListController::rules(),
            'period' => ['nullable', 'string', 'in:all,cycle'],
        ]);

        $contacts = ContactList::for($request->user());

        return response()->json([
            'contacts' => $contacts->database($filters),
            // An object even when empty: as [] the client would read [].sort.
            'filters' => (object) $filters,
            'usage' => $contacts->usage(),
        ]);
    }

    public function applicants(Request $request): JsonResponse
    {
        $filters = $request->validate([
            ...WebContactListController::rules(),
            'job' => ['nullable', 'integer'],
            'stage' => ['nullable', 'string', 'in:all,'.implode(',', JobApplication::STAGES)],
        ]);

        $contacts = ContactList::for($request->user());

        return response()->json([
            'contacts' => $contacts->applicants($filters),
            'filters' => (object) $filters,
            'usage' => $contacts->usage(),
            'jobs' => $contacts->jobs(),
        ]);
    }
}
