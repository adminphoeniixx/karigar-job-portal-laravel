<?php

namespace App\Support;

/**
 * The apps' "Help & Support" screen: the questions people actually ask, and
 * the ways to reach a person when the answer is not here.
 *
 * FAQs carry an `audience` so one endpoint serves both apps — the worker app
 * asks for `worker`, the employer app for `employer`, and both get the entries
 * marked `all`.
 */
class HelpCentre
{
    public const AUDIENCES = ['worker', 'employer'];

    /**
     * Ways to reach support. An unconfigured channel is dropped rather than
     * returned empty, so the app can render whatever it gets.
     *
     * @return array<string, mixed>
     */
    public static function channels(): array
    {
        return array_filter([
            'email' => config('support.email'),
            'whatsapp' => config('support.whatsapp'),
            'phone' => config('support.phone'),
            'hours' => config('support.hours'),
        ], fn ($value) => filled($value));
    }

    /**
     * @return list<array<string, string>>
     */
    public static function faqs(?string $audience = null): array
    {
        $faqs = array_merge(self::shared(), self::worker(), self::employer());

        if ($audience === null) {
            return $faqs;
        }

        return array_values(array_filter(
            $faqs,
            fn (array $faq) => $faq['audience'] === 'all' || $faq['audience'] === $audience,
        ));
    }

    /**
     * @return list<array<string, string>>
     */
    private static function shared(): array
    {
        return [
            self::faq('otp-not-received', 'all', 'I did not get my OTP.',
                'The code takes a few seconds. Check that the number you typed is right and that you have signal. You can ask for a new code after the countdown finishes. If nothing arrives after two tries, write to us and we will sign you in another way.'),
            self::faq('change-number', 'all', 'Can I change my mobile number?',
                'Not from the app yet. Your number is how you sign in, so we change it by hand after checking it is really you — write to support from the email on your account, or message us on WhatsApp from the old number.'),
            self::faq('change-language', 'all', 'How do I change the app language?',
                'Settings → Language. It changes the app and the messages we send you.'),
            self::faq('notifications-off', 'all', 'I am not getting notifications.',
                'Check two things: notifications are allowed for Super Karigar in your phone settings, and the alert toggles in Settings are on. If both look right, sign out and sign back in — that refreshes the device token we send alerts to.'),
            self::faq('delete-account', 'all', 'How do I delete my account?',
                'Settings → Delete account. It removes your profile, applications and messages. Some records stay where the law requires us to keep them — invoices, for example. Reviews you left about other people stay, without your name attached.'),
            self::faq('report-user', 'all', 'Someone is asking me for money or behaving badly.',
                'Do not pay. No one on Super Karigar should ask a karigar for a deposit, a security payment or identity documents. Report the account to us with the job or the message and we will look into it, and suspend the account if it checks out.'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function worker(): array
    {
        return [
            self::faq('more-jobs', 'worker', 'I am not getting any jobs.',
                'Fill in your skills, years of experience and city, mark yourself available, and upload a résumé if you have one — employers are matched to you on exactly those things, and a résumé is read by our matching model too. Applying to jobs near you, and early, helps more than anything else.'),
            self::faq('resume-rejected', 'worker', 'My résumé will not upload.',
                'It has to be a PDF whose text can be read. A photo or a scan of a printed page has no text in it, so we cannot match it against jobs — ask whoever typed it to send you the PDF they printed from.'),
            self::faq('application-status', 'worker', 'What do the application stages mean?',
                'Applied means the employer has it. Shortlisted means they are interested. Interview means a time is fixed. Hired means you got it. Some employers use automatic shortlisting, so a stage can move without a person touching it.'),
            self::faq('screening-call', 'worker', 'Who called me from Super Karigar?',
                'That is our automated assistant, ringing shortlisted karigars to ask if you are still interested and when you could come for an interview. It says so at the start. Nothing it discusses is fixed until the employer confirms. You can switch these calls off in your profile settings.'),
            self::faq('employer-contact', 'worker', 'When does an employer get my number?',
                'Only when you apply to their job, or when they spend credits to unlock your profile. Your number is never shown publicly.'),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private static function employer(): array
    {
        return [
            self::faq('post-job', 'employer', 'How do I post a job?',
                'My Jobs → Post a job. Fill in the title, the work, the city and the wage. An active plan is needed to post. If you are stuck on the description, tap Suggest with AI and edit what it drafts.'),
            self::faq('credits', 'employer', 'What are credits for?',
                'Unlocking a karigar\'s contact details, and boosting a job so it shows higher. Your plan includes an unlock allowance; boosts always use purchased credits. Spending is immediate and cannot be undone, so unlock only the people you mean to call.'),
            self::faq('ai-score', 'employer', 'What is the AI score on an applicant?',
                'How well our model thinks that karigar fits this job, judged on their profile, their résumé and the job you wrote. It sorts your applicants so the likely ones are at the top. It is an aid, not a decision — read the summary and look past the number where it matters.'),
            self::faq('screening-calls', 'employer', 'What is a screening call?',
                'We ring the applicant, ask whether they are still interested, and collect an interview time they could attend. You then confirm or move that time — nothing is booked without you. Calls only go out in daytime hours, and a karigar who has opted out is never called.'),
            self::faq('invoice', 'employer', 'Where are my invoices?',
                'Credits & Plans → Invoices. Every payment has a GST invoice you can open and share. Add your GSTIN in your business profile before you pay if you need it printed on them.'),
            self::faq('team', 'employer', 'Can my colleague use the same account?',
                'Add them as a team member instead of sharing a login — Settings → Team. They get their own sign-in and see the same jobs and applicants. Only the account owner can change the plan.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function faq(string $id, string $audience, string $question, string $answer): array
    {
        return ['id' => $id, 'audience' => $audience, 'question' => $question, 'answer' => $answer];
    }
}
