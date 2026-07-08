<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Support\TemplatedMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class EmailTemplateController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/EmailTemplates', [
            'templates' => EmailTemplate::orderBy('id')->get([
                'id', 'key', 'name', 'description', 'subject', 'body_html', 'placeholders', 'is_active',
            ]),
        ]);
    }

    public function update(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:20000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $emailTemplate->update($data);
        TemplatedMailer::forget($emailTemplate->key);

        return back()->with('toast', ['type' => 'success', 'message' => __('Template saved.')]);
    }

    /**
     * Send a preview of the template to the admin, using sample placeholder data.
     */
    public function test(Request $request, EmailTemplate $emailTemplate): RedirectResponse
    {
        $email = $request->validate([
            'email' => ['required', 'email'],
        ])['email'];

        $sample = [
            'app_name' => config('app.name'),
            'worker_name' => 'Ramesh Kumar',
            'employer_name' => 'Acme Constructions',
            'job_title' => 'Site Electrician',
            'job_location' => 'Jaipur, Rajasthan',
            'expected_wage' => '₹18,000',
            'cover_note' => 'I have 6 years of experience in residential wiring.',
            'action_url' => url('/dashboard'),
        ];

        $rendered = $emailTemplate->render($sample);

        try {
            Mail::to($email)->send(new TemplatedMail('[Preview] '.$rendered['subject'], $rendered['body']));
        } catch (Throwable $e) {
            report($e);

            return back()->with('toast', ['type' => 'error', 'message' => __('Could not send preview: ').$e->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Preview sent to :email.', ['email' => $email])]);
    }
}
