<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Seed the default transactional email templates. Uses updateOrCreate on the
     * key so re-running never duplicates rows and never clobbers an admin's edits
     * to an existing template (only missing templates are (re)created).
     */
    public function run(): void
    {
        foreach ($this->templates() as $template) {
            EmailTemplate::firstOrCreate(
                ['key' => $template['key']],
                $template,
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function templates(): array
    {
        $applicationPlaceholders = [
            'app_name', 'worker_name', 'employer_name', 'job_title',
            'job_location', 'expected_wage', 'cover_note', 'action_url',
        ];

        return [
            [
                'key' => 'job_posted',
                'name' => 'Job posted (to employer)',
                'description' => 'Confirmation sent to the employer right after they post a job.',
                'subject' => 'Your job “{{ job_title }}” is now live',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>Your job <strong>“{{ job_title }}”</strong> in {{ job_location }} has been posted successfully and is now live for {{ app_name }} workers to see.</p>
<p>We’ll notify you as soon as candidates start applying.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my job</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'job_title', 'job_location', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'application_received',
                'name' => 'New application received (to employer)',
                'description' => 'Sent to the employer when a worker applies to one of their jobs.',
                'subject' => 'New application for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p><strong>{{ worker_name }}</strong> has applied for your job <strong>“{{ job_title }}”</strong> in {{ job_location }}.</p>
<p><strong>Expected wage:</strong> {{ expected_wage }}</p>
<p><strong>Cover note:</strong><br>{{ cover_note }}</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Review applicant</a>
</p>
HTML,
                'placeholders' => $applicationPlaceholders,
                'is_active' => true,
            ],
            [
                'key' => 'application_submitted',
                'name' => 'Application submitted (to worker)',
                'description' => 'Confirmation sent to the worker right after they apply to a job.',
                'subject' => 'Your application for “{{ job_title }}” was submitted',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p>Your application for <strong>“{{ job_title }}”</strong> in {{ job_location }} has been submitted successfully.</p>
<p>We’ll notify you as soon as the employer responds. You can track its status any time from your applications.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my applications</a>
</p>
HTML,
                'placeholders' => $applicationPlaceholders,
                'is_active' => true,
            ],
            [
                'key' => 'application_accepted',
                'name' => 'Application accepted (to worker)',
                'description' => 'Sent to the worker when an employer accepts their application.',
                'subject' => 'Good news! You were accepted for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p>Great news — <strong>{{ employer_name }}</strong> has <strong>accepted</strong> your application for <strong>“{{ job_title }}”</strong> in {{ job_location }}.</p>
<p>Log in to view the details and connect with the employer.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my applications</a>
</p>
HTML,
                'placeholders' => $applicationPlaceholders,
                'is_active' => true,
            ],
            [
                'key' => 'application_shortlisted',
                'name' => 'Application shortlisted (to worker)',
                'description' => 'Sent to the worker when an employer shortlists their application.',
                'subject' => 'You’ve been shortlisted for “{{ job_title }}”!',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p>Great news — <strong>{{ employer_name }}</strong> has <strong>shortlisted</strong> you for <strong>“{{ job_title }}”</strong> in {{ job_location }}.</p>
<p>The employer may reach out soon. Keep an eye on your applications for updates.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#f24711;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my applications</a>
</p>
HTML,
                'placeholders' => $applicationPlaceholders,
                'is_active' => true,
            ],
            [
                'key' => 'application_rejected',
                'name' => 'Application rejected (to worker)',
                'description' => 'Sent to the worker when an employer rejects their application.',
                'subject' => 'Update on your application for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p>Thank you for applying for <strong>“{{ job_title }}”</strong> in {{ job_location }}. Unfortunately, <strong>{{ employer_name }}</strong> has decided not to move forward with your application this time.</p>
<p>Don’t be discouraged — there are plenty of other jobs waiting for you.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Browse more jobs</a>
</p>
HTML,
                'placeholders' => $applicationPlaceholders,
                'is_active' => true,
            ],
            [
                'key' => 'interview_scheduled',
                'name' => 'Interview scheduled (to worker)',
                'description' => 'Sent to the worker when an employer books an interview, from the applicants screen or after a screening call is confirmed.',
                'subject' => 'Interview scheduled for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p><strong>{{ employer_name }}</strong> has scheduled an interview with you for <strong>“{{ job_title }}”</strong> in {{ job_location }}.</p>
<table cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">When</td><td style="padding:6px 0;font-weight:600;">{{ interview_at }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">How</td><td style="padding:6px 0;font-weight:600;">{{ interview_mode }}</td></tr>
</table>
<p>Please be on time. If you cannot make it, let the employer know as early as you can.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#f24711;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my applications</a>
</p>
HTML,
                'placeholders' => ['app_name', 'worker_name', 'employer_name', 'job_title', 'job_location', 'interview_at', 'interview_mode', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'job_invite',
                'name' => 'Invited to apply (to worker)',
                'description' => 'Sent to the worker when an employer personally invites them to apply for a job.',
                'subject' => '{{ employer_name }} invited you to apply for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p><strong>{{ employer_name }}</strong> saw your profile and would like you to apply for <strong>“{{ job_title }}”</strong> in {{ job_location }}.</p>
<p style="border-left:3px solid #e5e5e5;padding-left:14px;color:#4a4a4a;">{{ note }}</p>
<p>An invite means the employer is already interested — applying now puts you near the front of the queue.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#f24711;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View the job</a>
</p>
HTML,
                'placeholders' => ['app_name', 'worker_name', 'employer_name', 'job_title', 'job_location', 'note', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'job_posted_match',
                'name' => 'New matching job (to worker)',
                'description' => 'Sent to workers whose skills match a newly posted job. This is the highest-volume template — keep it short.',
                'subject' => 'New {{ job_category }} job: “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ worker_name }},</p>
<p>A new job matching your work has just been posted on {{ app_name }}:</p>
<p style="font-size:18px;font-weight:600;margin:16px 0 4px;">{{ job_title }}</p>
<p style="color:#6b6b6b;margin:0 0 20px;">{{ job_location }}</p>
<p>Good jobs get filled quickly, so it is worth applying early.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View this job</a>
</p>
HTML,
                'placeholders' => ['app_name', 'worker_name', 'employer_name', 'job_title', 'job_location', 'job_category', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'chat_message',
                'name' => 'New chat message',
                'description' => 'Sent to whichever side of a conversation did not send the message. Goes to workers and employers alike.',
                'subject' => 'New message from {{ sender_name }}',
                'body_html' => <<<'HTML'
<p>Hi {{ recipient_name }},</p>
<p><strong>{{ sender_name }}</strong> sent you a message on {{ app_name }}:</p>
<p style="border-left:3px solid #e5e5e5;padding-left:14px;color:#4a4a4a;">{{ message_preview }}</p>
<p>Reply in the app — replying to this email will not reach them.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Open the conversation</a>
</p>
HTML,
                'placeholders' => ['app_name', 'recipient_name', 'sender_name', 'message_preview', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'screening_call_completed',
                'name' => 'Screening call result (to employer)',
                'description' => 'Sent to the employer after the AI screening call. When the worker suggested an interview time, this is the employer’s cue to confirm it.',
                'subject' => 'Screening call done: {{ worker_name }} for “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>We called <strong>{{ worker_name }}</strong> about <strong>“{{ job_title }}”</strong>. Here is how it went.</p>
<table cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Outcome</td><td style="padding:6px 0;font-weight:600;">{{ outcome }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Suggested time</td><td style="padding:6px 0;font-weight:600;">{{ proposed_interview_at }}</td></tr>
</table>
<p style="border-left:3px solid #e5e5e5;padding-left:14px;color:#4a4a4a;">{{ summary }}</p>
<p>A suggested time is only the worker’s preference — nothing is booked until you confirm it.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#f24711;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Review and confirm</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'worker_name', 'job_title', 'outcome', 'summary', 'proposed_interview_at', 'action_url'],
                'is_active' => true,
            ],
        ];
    }
}
