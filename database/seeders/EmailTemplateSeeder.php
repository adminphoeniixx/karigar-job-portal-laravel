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
        ];
    }
}
