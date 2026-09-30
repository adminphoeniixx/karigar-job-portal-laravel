<?php

namespace App\Http\Controllers\Employer;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Services\ContactList;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Worker Database's "My contacts" tabs: karigars unlocked from the
 * database with the plan, and applicants to the employer's own jobs.
 */
class ContactListController extends Controller
{
    public function database(Request $request): Response
    {
        $filters = $request->validate([
            ...self::rules(),
            'period' => ['nullable', 'string', 'in:all,cycle'],
        ]);

        $contacts = ContactList::for($request->user());

        return Inertia::render('workers/Contacts', [
            'tab' => 'database',
            'contacts' => $contacts->database($filters),
            // An object even when empty: as [] the client would read [].sort.
            'filters' => (object) $filters,
            'usage' => $contacts->usage(),
            'jobs' => [],
            'stages' => JobApplication::STAGES,
        ]);
    }

    public function applicants(Request $request): Response
    {
        $filters = $request->validate([
            ...self::rules(),
            'job' => ['nullable', 'integer'],
            'stage' => ['nullable', 'string', 'in:all,'.implode(',', JobApplication::STAGES)],
        ]);

        $contacts = ContactList::for($request->user());

        return Inertia::render('workers/Contacts', [
            'tab' => 'applicants',
            'contacts' => $contacts->applicants($filters),
            'filters' => (object) $filters,
            'usage' => $contacts->usage(),
            'jobs' => $contacts->jobs(),
            'stages' => JobApplication::STAGES,
        ]);
    }

    /**
     * Filters both lists take.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'skill' => ['nullable', 'string', 'max:50'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', 'in:'.implode(',', ContactList::SORTS)],
        ];
    }
}
