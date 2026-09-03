<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The legal documents the apps render natively on their "Terms & Privacy"
 * screen.
 *
 * Content lives here rather than in a Blade/Vue template because the apps draw
 * it in their own type and spacing; each section is a list of simple blocks
 * (`paragraph`, `heading`, `list`) so a client only ever has three things to
 * render. The web privacy page (`resources/js/pages/Privacy.vue`) is the same
 * text in editorial markup — **change both together**.
 */
class LegalDocuments
{
    public const KEYS = ['terms', 'privacy'];

    /**
     * Both documents, without their bodies — the index the app lists.
     *
     * @return list<array<string, mixed>>
     */
    public static function index(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'title' => self::get($key)['title'],
            'summary' => self::get($key)['summary'],
            'updated_at' => self::get($key)['updated_at'],
            'updated_label' => self::get($key)['updated_label'],
            'web_url' => self::get($key)['web_url'],
        ], self::KEYS);
    }

    /**
     * One document with its full body.
     *
     * @return array<string, mixed>
     */
    public static function get(string $key): array
    {
        return match ($key) {
            'privacy' => self::privacy(),
            'terms' => self::terms(),
            default => throw new \InvalidArgumentException("Unknown legal document [{$key}]."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function privacy(): array
    {
        // Verification can be switched off in Admin → Settings, and then there
        // are no identity documents to disclose. The web page hides the same
        // section on the same flag.
        $kycEnabled = Setting::bool('kyc_verification_enabled', true);

        $collectSections = [
            self::heading('Everyone'),
            self::list([
                'Your mobile number. This is how you sign in — we send a one-time code to it. It is the only thing we insist on.',
                'Your name, and your email address if you choose to add one.',
                'Your language, so we speak to you in the one you picked.',
                'Usage and device information — pages you open, your IP address, and a notification token if you use our app and allow alerts.',
            ]),
            self::heading('If you are a karigar'),
            self::list([
                'Your trade profile — skills, years of experience, education, languages you speak, a short bio, and the wage you expect.',
                'Where you work — your city and state, and if you allow it, your location and how far you are willing to travel.',
                'Your résumé, if you upload one. We read the text out of it so it can be matched against jobs.',
                'A profile photo, if you add one.',
                'Payout details such as a UPI ID, only when you are being paid through the platform.',
            ]),
            self::heading('If you are an employer'),
            self::list([
                'Your company name, city, and business details including GST where relevant.',
                'The jobs you post, and billing records for anything you pay for.',
            ]),
        ];

        if ($kycEnabled) {
            $collectSections[] = self::heading('If you get verified');
            $collectSections[] = self::list([
                'Identity documents — PAN, Aadhaar or GST, whichever you submit. Verification is optional; you can use Super Karigar without it. We keep these on private storage, never on a public address, and they are never shown to other users. Other people only ever see whether you are verified, never the document itself.',
            ]);
        }

        $collectSections[] = self::heading('What you create by using the platform');
        $collectSections[] = self::list([
            'Applications you send, and their status.',
            'Messages between karigars and employers.',
            'Reviews you leave or receive.',
            'Recordings and transcripts of screening calls — see below.',
        ]);

        return [
            'key' => 'privacy',
            'title' => 'Privacy policy',
            'summary' => 'What we hold about you, why, who else sees it, and how to have it deleted.',
            'updated_at' => '2026-08-12',
            'updated_label' => '12 August 2026',
            'web_url' => url('/privacy'),
            'intro' => 'Super Karigar connects skilled karigars with people who need them. Doing that means holding some genuinely personal things — your phone number, where you work, sometimes an identity document. This page says exactly what we hold, why, and how to make us delete it. Your number is never public, and an employer only sees your contact details once you have applied to them or they have paid to unlock your profile. You can ask us to delete everything at any time.',
            'sections' => [
                self::section('what-we-collect', 'What we collect', $collectSections),
                self::section('why', 'Why we use it', [
                    self::paragraph('We use your information to do the things you came here for, and little else:'),
                    self::list([
                        'To show you jobs worth your time, and to show employers karigars worth theirs.',
                        'To let you sign in, apply, message, and get paid.',
                        'To tell you when something happens — an application moves, a message arrives, an interview is set.',
                        'To keep the platform honest: stopping fraud, spam, fake listings and abuse.',
                        'To meet legal obligations, including tax and telecom rules.',
                    ]),
                    self::paragraph('We do not sell your personal data, and we do not run advertising on it.'),
                ]),
                self::section('ai', 'AI and automated calls', [
                    self::paragraph('Two parts of Super Karigar make decisions with software rather than a person. You should know about both.'),
                    self::heading('Matching and shortlisting'),
                    self::paragraph('When you apply for a job, an AI model reads your profile and résumé against that job and scores how well they fit. Employers see applicants ranked by that score. Where an employer has switched it on, a high score can shortlist you automatically, and a very low score can reject an application automatically.'),
                    self::paragraph('A score is never the last word on you. An employer can always look past it, and if you think a decision about you was wrong, write to us and a person will look at it.'),
                    self::heading('Screening calls'),
                    self::paragraph('If you are shortlisted, we may ring you with an automated voice agent that asks whether you are still interested and when you could attend an interview. It tells you at the start that it is automated and who it is calling for.'),
                    self::list([
                        'The call is recorded and transcribed so the employer can see what you said.',
                        'The agent only proposes an interview time. Nothing is booked until the employer confirms it.',
                        'We only call during daytime hours, and only a limited number of times.',
                        'You can switch these calls off entirely in your profile settings, or by telling the agent. That choice sticks — it applies to every job you apply for afterwards.',
                    ]),
                ]),
                self::section('sharing', 'Who else sees it', [
                    self::heading('Other users'),
                    self::paragraph('Your public profile — trade, skills, experience, city, photo — is visible to employers searching for workers. Your phone number is not. An employer only gets your contact details when you apply to their job, or when they spend credits to unlock your profile. Karigars see an employer\'s company details and the jobs they post.'),
                    self::heading('Companies that help us run the service'),
                    self::paragraph('They may only use what we give them to do their job for us, never for themselves:'),
                    self::list([
                        'Hosting and databases — running the platform itself.',
                        'Payments — card and UPI processing for subscriptions and payouts. We never see or store your full card details.',
                        'Telecom and voice providers — sending one-time codes and placing screening calls.',
                        'AI providers — scoring applications and holding screening calls.',
                        'Notifications and email — delivering alerts and messages.',
                        'Image delivery and search — serving photos and making listings searchable.',
                    ]),
                    self::heading('When the law requires it'),
                    self::paragraph('We will share information where we are legally required to, or where it is genuinely necessary to protect someone\'s safety or to investigate fraud.'),
                ]),
                self::section('keeping', 'How long we keep it', [
                    self::list([
                        'Your account — for as long as it is open.',
                        'Identity documents — kept while your account is open so you do not have to submit them twice, and deleted when you close it.',
                        'Call recordings and transcripts — kept while the application they belong to is live.',
                        'Invoices and payment records — kept as long as tax law requires, even after you leave.',
                    ]),
                    self::paragraph('When you delete your account we remove your personal information. Some things survive in a form that no longer identifies you: reviews you left about someone else, and records we are legally obliged to keep.'),
                ]),
                self::section('rights', 'Your rights and choices', [
                    self::paragraph('Under India\'s data protection law you can ask us to:'),
                    self::list([
                        'Show you what we hold about you.',
                        'Correct anything wrong or out of date.',
                        'Delete your account and the data attached to it.',
                        'Withdraw consent you previously gave — for location, for screening calls, for notifications.',
                        'Nominate someone to exercise these rights if you cannot.',
                    ]),
                    self::paragraph('You can do much of this yourself, without asking us:'),
                    self::list([
                        'Edit or clear your profile, résumé and location from your profile page.',
                        'Turn screening calls off in your profile settings.',
                        'Turn notifications off on your device.',
                        'Close your account from your account settings.',
                    ]),
                    self::paragraph('For anything else, write to us at the address below. We will respond within the time the law allows. We may need to confirm it is really you before we act on a request to delete or hand over data.'),
                ]),
                self::section('security', 'How we protect it', [
                    self::paragraph('Traffic to Super Karigar is encrypted. Identity documents live on private storage that is not reachable from the public internet, and are served only through short-lived links to people entitled to see them. Access to production data is limited to the people who need it to keep the service running.'),
                    self::paragraph('No system is perfect. If we ever discover a breach that puts you at risk, we will tell you and the Data Protection Board as the law requires.'),
                    self::heading('Children'),
                    self::paragraph('Super Karigar is for people aged 18 and over. We do not knowingly collect information about children. If you believe a child has an account, tell us and we will remove it.'),
                    self::heading('Changes to this policy'),
                    self::paragraph('When we change this page we update the date at the top. If a change materially affects your rights, we will tell you in the app or by message rather than quietly editing it.'),
                ]),
                self::section('contact', 'Contact and grievances', [
                    self::paragraph('For anything about your privacy — a request, a complaint, or a question about this page — reach our Grievance Officer at '.config('support.grievance_email').'. We acknowledge every request within 72 hours and resolve it within the period the law allows.'),
                    self::paragraph('If you are not satisfied with how we have handled a complaint, you may escalate it to the Data Protection Board of India.'),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function terms(): array
    {
        return [
            'key' => 'terms',
            'title' => 'Terms of use',
            'summary' => 'The rules for using Super Karigar, for karigars and employers alike.',
            'updated_at' => '2026-09-03',
            'updated_label' => '3 September 2026',
            // No web page for the terms yet — the app is the only place they
            // are published. Point this at url('/terms') once that page exists;
            // a client showing an "open in browser" link must handle null.
            'web_url' => null,
            'intro' => 'Super Karigar is a marketplace: it introduces skilled karigars to people who want to hire them. We are not the employer, and we are not a party to whatever you agree with the person on the other side. These terms explain what you can expect from us, and what we expect from you. By signing in you accept them.',
            'sections' => [
                self::section('account', 'Your account', [
                    self::list([
                        'You must be 18 or over to use Super Karigar.',
                        'You sign in with your mobile number and a one-time code. Keep that number and your phone secure — anything done from your account is treated as done by you.',
                        'One account per person. Do not sign up on someone else\'s behalf without their knowledge.',
                        'Keep your details accurate. A profile that misstates your skills, experience or business wastes everyone\'s time and can get the account suspended.',
                        'You can close your account at any time from account settings.',
                    ]),
                ]),
                self::section('karigars', 'If you are a karigar', [
                    self::list([
                        'Describe your trade honestly. Employers hire on what your profile and résumé say.',
                        'Applying is free. If a job listing says the employer charges a fee, that arrangement is between you and that employer — read it before you apply, and tell us if a listing looks like a scam.',
                        'Turn up to what you accept, or tell the employer as early as you can if you cannot.',
                        'Automated screening calls are optional. You can switch them off in your profile settings, and that choice applies to every job afterwards.',
                    ]),
                ]),
                self::section('employers', 'If you are an employer', [
                    self::list([
                        'Post real jobs only. No listings for work that does not exist, no listings that hide who the work is really for.',
                        'Do not discriminate on caste, religion, gender, disability or region, and do not ask for anything a job does not need.',
                        'Never ask a karigar for a deposit, a security payment, or their identity documents through the platform.',
                        'Contact details you unlock are for hiring for that job. Do not resell them, add them to a marketing list, or pass them on.',
                        'You are responsible for the wages, safety and working conditions of anyone you hire.',
                    ]),
                ]),
                self::section('payments', 'Plans, credits and payments', [
                    self::list([
                        'Employers pay for plans and contact credits. Prices are shown before you pay, with GST added at checkout, and a tax invoice is issued for every payment.',
                        'Payments run through our payment provider. We never see or store your full card details.',
                        'Credits are spent when you unlock a karigar\'s contact details or boost a job. Spending is immediate and cannot be undone.',
                        'A plan runs for the period you bought and does not renew by itself unless the checkout said so.',
                    ]),
                ]),
                self::section('ai', 'AI features', [
                    self::paragraph('An AI model scores how well an applicant fits a job, and an automated voice agent may ring shortlisted karigars to ask if they are still interested and when they could interview.'),
                    self::list([
                        'A score is an aid to a hiring decision, not the decision. Employers can and do look past it.',
                        'The voice agent only proposes an interview time. Nothing is booked until the employer confirms.',
                        'Calls are recorded and transcribed, happen only in daytime hours, and can be switched off by the karigar.',
                        'AI can be wrong. If you think an automated decision about you was unfair, write to us and a person will look at it.',
                    ]),
                ]),
                self::section('reviews', 'Reviews and messages', [
                    self::list([
                        'Review only people you actually worked with, and describe your own experience.',
                        'No abuse, threats, harassment or personal attacks — in reviews or in messages.',
                        'We may remove a review or a message that breaks these rules, and suspend the account behind it.',
                    ]),
                ]),
                self::section('not-allowed', 'What you must not do', [
                    self::list([
                        'Impersonate someone else, or misrepresent who you are or who you work for.',
                        'Scrape, bulk-download or resell profiles, listings or contact details.',
                        'Break, probe or overload the platform, or try to reach data that is not yours.',
                        'Use Super Karigar for anything illegal, or to arrange work that is illegal or unsafe.',
                        'Post malware, spam, or links designed to trick other users.',
                    ]),
                ]),
                self::section('our-role', 'What we do and do not promise', [
                    self::paragraph('We introduce people. We do not employ karigars, we do not supervise work, and we are not a party to your agreement with the other side.'),
                    self::list([
                        'We do not guarantee that you will find work, or that you will find a suitable karigar.',
                        'We do not verify every claim in a profile or a job post. Verification, where a user has completed it, checks an identity document — it is not a character reference or a guarantee of skill.',
                        'Satisfy yourself about the other person before you commit — meet, check the work, agree the terms in writing.',
                        'We keep the service running as well as we reasonably can, but it can be interrupted for maintenance or by things outside our control.',
                    ]),
                    self::paragraph('To the extent the law allows, we are not liable for what happens between you and another user — unpaid wages, work that was not done, injury on site, or a hire that did not work out. Where we are held liable despite this, our liability is limited to what you paid us in the three months before the claim.'),
                ]),
                self::section('suspension', 'Suspension and closing accounts', [
                    self::paragraph('We can suspend or close an account that breaks these terms, that we reasonably believe is fraudulent, or that puts other users at risk. Where we can, we tell you why and give you a chance to respond. You can close your own account at any time; closing it does not refund credits or an unused part of a plan.'),
                ]),
                self::section('changes', 'Changes to these terms', [
                    self::paragraph('We update the date at the top when these terms change. If a change materially affects you, we will tell you in the app or by message rather than quietly editing this page. Continuing to use Super Karigar after that means you accept the new terms.'),
                ]),
                self::section('contact', 'Law and contact', [
                    self::paragraph('These terms are governed by the laws of India, and the courts of '.config('support.jurisdiction').' have exclusive jurisdiction over any dispute.'),
                    self::paragraph('For a complaint about the service, or anything in these terms, write to our Grievance Officer at '.config('support.grievance_email').'. We acknowledge every complaint within 72 hours.'),
                ]),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private static function section(string $id, string $title, array $blocks): array
    {
        return ['id' => $id, 'title' => $title, 'blocks' => $blocks];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paragraph(string $text): array
    {
        return ['type' => 'paragraph', 'text' => $text];
    }

    /**
     * A sub-heading inside a section — the app renders it smaller than the
     * section title, not as a new entry in the contents rail.
     *
     * @return array<string, mixed>
     */
    private static function heading(string $text): array
    {
        return ['type' => 'heading', 'text' => $text];
    }

    /**
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    private static function list(array $items): array
    {
        return ['type' => 'list', 'items' => array_values($items)];
    }
}
