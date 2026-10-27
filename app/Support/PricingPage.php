<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Billing\Gst;

/**
 * The words on the public /pricing page around the plans, edited in
 * Admin → Plans. Kept as one JSON setting; a field the admin left blank
 * shows the built-in text below, so the page never renders a hole.
 *
 * `{gst}` anywhere in the text becomes today's GST rate ("18").
 */
final class PricingPage
{
    public const KEY = 'pricing_page';

    /** Fields edited in a textarea rather than a one-line input. */
    public const LONG = ['meta_description', 'intro', 'gst_note', 'job_lede', 'database_lede', 'credits_lede', 'cta_text'];

    /**
     * @return array<string, string|list<array{q: string, a: string}>>
     */
    public static function defaults(): array
    {
        return [
            'meta_title' => 'Pricing for employers — Super Karigar',
            'meta_description' => 'Super Karigar plans for employers: job posting plans, Worker Database access and AI call credits. Prices in rupees, GST invoice with every payment.',
            'eyebrow' => 'Pricing · For employers',
            'headline' => 'Pay for the hiring you do. Nothing hidden.',
            'intro' => 'Pick a plan, sign in with your mobile number and pay — your plan starts the moment the payment goes through.',
            'gst_note' => 'Prices below are before {gst}% GST; you get a GST invoice for every payment.',
            'job_label' => 'Hire',
            'job_title' => 'Job plans',
            'job_lede' => 'Post jobs, get applicants from your area, and let the AI rank them so the best karigar is on top.',
            'database_label' => 'Search',
            'database_title' => 'Worker Database',
            'database_lede' => 'Skip the wait. Search verified karigars by craft and location and call them directly.',
            'credits_label' => 'Add-on',
            'credits_title' => 'AI call credits',
            'credits_lede' => 'Our AI voice agent calls shortlisted applicants in Hindi, screens them and books the interview. Prepaid, one-time — no renewal.',
            'credits_badge' => 'Best value',
            'faq_label' => 'Questions',
            'faq_title' => 'Before you pay',
            'faqs' => [
                ['q' => 'How do I buy a plan?', 'a' => 'Press Buy, enter your mobile number and the OTP we send. If you are new, that same step creates your employer account. You land on the checkout for the plan you picked and pay through Razorpay — UPI, cards or net banking.'],
                ['q' => 'Job plan or Worker Database — which one do I need?', 'a' => 'A job plan is for posting jobs and receiving applicants. The Worker Database is for finding karigars yourself and calling them. You can hold one of each at the same time, and every job plan already opens part of the database.'],
                ['q' => 'How long does a job stay live?', 'a' => 'Each job stays live for the number of days written on its plan, counted from the moment it goes live. When it ends, repost it from My Jobs — that uses one more job post from your plan.'],
                ['q' => 'Does the plan renew on its own?', 'a' => 'Yes. Plans renew every cycle through Razorpay, so your jobs keep running without a gap. To stop a renewal, write to us at the address at the bottom of this page.'],
                ['q' => 'Is GST included?', 'a' => 'Prices are shown before GST; {gst}% GST is added at checkout. Every payment, including each renewal, comes with a GST tax invoice by email — add your GSTIN in your profile to claim input credit.'],
                ['q' => 'What are AI call credits?', 'a' => 'When AI calling is on for a job, our voice agent rings shortlisted applicants, asks your screening questions and books an interview slot. Credits pay for those minutes. They never expire, and calls that are not picked up cost nothing.'],
                ['q' => 'Have a coupon code?', 'a' => 'Apply it on the checkout screen, after you press Buy. The discount and the GST on the discounted price are shown before you pay.'],
            ],
            'cta_title' => 'Not sure yet? Look around first.',
            'cta_text' => 'Create your employer account for free, set up your profile and see karigars near you before you buy.',
            'cta_button' => 'Create employer account',
        ];
    }

    /**
     * What the admin saved, over the defaults, as the admin form edits it.
     *
     * @return array<string, mixed>
     */
    public static function content(): array
    {
        $stored = json_decode((string) Setting::get(self::KEY), true);
        $stored = is_array($stored) ? $stored : [];

        $content = [];

        foreach (self::defaults() as $key => $default) {
            $value = $stored[$key] ?? null;

            // An emptied FAQ list is a choice; an emptied line of text is not.
            $content[$key] = $key === 'faqs'
                ? (is_array($value) ? array_values($value) : $default)
                : (is_string($value) && trim($value) !== '' ? $value : $default);
        }

        return $content;
    }

    /**
     * The text as the public page shows it, `{gst}` filled in.
     *
     * @return array<string, mixed>
     */
    public static function forPage(): array
    {
        $gst = rtrim(rtrim(number_format(Gst::percent(), 2, '.', ''), '0'), '.');
        $fill = fn (string $text): string => str_replace('{gst}', $gst, $text);

        return collect(self::content())->map(fn ($value, $key) => $key === 'faqs'
            ? array_map(fn (array $faq) => ['q' => $fill($faq['q']), 'a' => $fill($faq['a'])], $value)
            : $fill($value))->all();
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public static function save(array $content): void
    {
        $keep = array_intersect_key($content, self::defaults());
        $keep['faqs'] = array_map(fn (array $faq) => ['q' => trim($faq['q']), 'a' => trim($faq['a'])], $content['faqs'] ?? []);

        Setting::set(self::KEY, json_encode($keep, JSON_UNESCAPED_UNICODE));
    }
}
