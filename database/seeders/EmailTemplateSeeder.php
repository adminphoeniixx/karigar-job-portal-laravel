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
            [
                'key' => 'payment_received',
                'name' => 'Payment received, with tax invoice (to employer)',
                'description' => 'Sent once a plan payment goes through. The tax invoice is attached as a PDF.',
                'subject' => 'Payment received — tax invoice {{ invoice_number }}',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>Thank you — your payment for the <strong>{{ plan_name }}</strong> plan has gone through and the plan is active.</p>
<table cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Invoice</td><td style="padding:6px 0;font-weight:600;">{{ invoice_number }} ({{ invoice_date }})</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Plan price</td><td style="padding:6px 0;">{{ amount_before_tax }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">GST</td><td style="padding:6px 0;">{{ gst_breakup }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Total paid</td><td style="padding:6px 0;font-weight:600;">{{ total_paid }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Valid until</td><td style="padding:6px 0;">{{ valid_until }}</td></tr>
</table>
<p>Your tax invoice is attached to this email as a PDF.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#bf3a16;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View invoice</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'plan_name', 'invoice_number', 'invoice_date', 'amount_before_tax', 'gst_breakup', 'total_paid', 'valid_until', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'ai_credits_recharged',
                'name' => 'AI call credits recharged, with tax invoice (to employer)',
                'description' => 'Sent once an AI call credit recharge goes through. The tax invoice is attached as a PDF.',
                'subject' => 'AI call credits added — tax invoice {{ invoice_number }}',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>Thank you — your recharge has gone through and <strong>{{ credits }} AI call credits</strong> are in your account.</p>
<table cellpadding="0" cellspacing="0" style="margin:20px 0;border-collapse:collapse;">
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Invoice</td><td style="padding:6px 0;font-weight:600;">{{ invoice_number }} ({{ invoice_date }})</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Pack</td><td style="padding:6px 0;">{{ plan_name }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Price</td><td style="padding:6px 0;">{{ amount_before_tax }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">GST</td><td style="padding:6px 0;">{{ gst_breakup }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Total paid</td><td style="padding:6px 0;font-weight:600;">{{ total_paid }}</td></tr>
  <tr><td style="padding:6px 16px 6px 0;color:#6b6b6b;">Balance now</td><td style="padding:6px 0;font-weight:600;">{{ balance }} credits</td></tr>
</table>
<p>Your tax invoice is attached to this email as a PDF. Credits never expire.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#bf3a16;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">See your credits</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'plan_name', 'credits', 'balance', 'invoice_number', 'invoice_date', 'amount_before_tax', 'gst_breakup', 'total_paid', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'ai_credits_low',
                'name' => 'AI call credits low or used up (to employer)',
                'description' => 'Sent when the employer’s AI call credits drop below the alert level in Admin → AI Credits, or run out while calls are waiting.',
                'subject' => 'Your AI call credits: {{ balance }} left',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>{{ headline }}</p>
<p>AI calls ring your shortlisted applicants, ask if they are still interested and when they can come for an interview. Recharge to keep them going.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#bf3a16;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Recharge credits</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'balance', 'headline', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'job_under_review',
                'name' => 'Job held for review (to employer)',
                'description' => 'Sent when the automatic safety check holds a job for an admin to look at, instead of putting it live.',
                'subject' => 'Your job “{{ job_title }}” is being reviewed',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>Before <strong>“{{ job_title }}”</strong> goes live, our team needs to take a quick look at it. This usually takes less than a working day.</p>
<p>Nothing from your plan has been used. We will let you know as soon as it is decided. If something in the job needs changing, you can edit it in the meantime.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#0d9488;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">View my job</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'job_title', 'reason', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'job_not_approved',
                'name' => 'Job not approved (to employer)',
                'description' => 'Sent when an admin turns down a job the safety check held, with the admin’s reason.',
                'subject' => 'Your job “{{ job_title }}” was not approved',
                'body_html' => <<<'HTML'
<p>Hi {{ employer_name }},</p>
<p>We could not put <strong>“{{ job_title }}”</strong> live.</p>
<p style="border-left:3px solid #e5e5e5;padding-left:14px;color:#4a4a4a;">{{ reason }}</p>
<p>Nothing from your plan has been used. You can change the job and post it again.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#bf3a16;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Edit the job</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'job_title', 'reason', 'action_url'],
                'is_active' => true,
            ],
            [
                'key' => 'job_review_needed',
                'name' => 'Job needs review (to admins)',
                'description' => 'Sent to every admin when the safety check holds a job. Approve or reject it in Admin → Job Moderation.',
                'subject' => 'Job held for review: “{{ job_title }}”',
                'body_html' => <<<'HTML'
<p>The safety check held a job by <strong>{{ employer_name }}</strong>:</p>
<p style="font-size:18px;font-weight:600;margin:16px 0 4px;">{{ job_title }}</p>
<p style="border-left:3px solid #e5e5e5;padding-left:14px;color:#4a4a4a;">{{ reasons }}</p>
<p>It stays off the site until someone approves or rejects it. The employer has been told it is under review.</p>
<p style="margin-top:24px;">
  <a href="{{ action_url }}" style="display:inline-block;background:#bf3a16;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:600;">Review it</a>
</p>
HTML,
                'placeholders' => ['app_name', 'employer_name', 'job_title', 'reasons', 'action_url'],
                'is_active' => true,
            ],
        ];
    }
}
