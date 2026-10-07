<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ScoreApplication;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\ApplicantAccess;
use App\Services\Billing\Gst;
use App\Services\Screening\ScreeningService;
use App\Support\GstStates;
use App\Support\MobileApps;
use App\Support\Verification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'first_post_free_enabled' => Setting::bool('first_post_free_enabled', true),
                'kyc_verification_enabled' => Setting::bool('kyc_verification_enabled', true),
                'worker_verification_enabled' => Setting::bool(Verification::WORKER_KEY, true),
                'employer_verification_required' => Setting::bool(
                    'employer_verification_required',
                    (bool) config('services.kyc.employer_required', true),
                ),
                'ai_auto_shortlist_enabled' => Setting::bool(ScoreApplication::ENABLED_KEY, false),
                'ai_auto_shortlist_threshold' => Setting::int(
                    ScoreApplication::THRESHOLD_KEY,
                    ScoreApplication::DEFAULT_THRESHOLD,
                ),
                'ai_auto_reject_enabled' => Setting::bool(ScoreApplication::REJECT_ENABLED_KEY, false),
                'ai_auto_reject_below' => Setting::int(
                    ScoreApplication::REJECT_BELOW_KEY,
                    ScoreApplication::DEFAULT_REJECT_BELOW,
                ),
                'ai_screening_call_enabled' => Setting::bool(ScreeningService::ENABLED_KEY, false),
                'applicant_first_batch' => ApplicantAccess::firstBatch(),
                'applicant_next_batch' => ApplicantAccess::nextBatch(),
            ],
            'billing' => $this->billing(),
            'apps' => [
                'versions' => MobileApps::versions(),
                'settings' => collect(MobileApps::appSettings())->map(fn (array $row) => [
                    'update_message' => (string) $row['update_message'],
                    'maintenance' => $row['maintenance'],
                    'maintenance_message' => (string) $row['maintenance_message'],
                    // datetime-local wants "2026-10-07T22:00" in the admin's own time.
                    'maintenance_until' => $row['maintenance_until'] !== null
                        ? Carbon::parse($row['maintenance_until'])->timezone(config('app.display_timezone'))->format('Y-m-d\TH:i')
                        : '',
                ])->all(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'first_post_free_enabled' => ['required', 'boolean'],
            'kyc_verification_enabled' => ['required', 'boolean'],
            'worker_verification_enabled' => ['sometimes', 'boolean'],
            'employer_verification_required' => ['sometimes', 'boolean'],
            'ai_auto_shortlist_enabled' => ['required', 'boolean'],
            // Below 40 the model calls a candidate a "weak" match, so allow the
            // admin to be lenient but not to shortlist literally everyone.
            'ai_auto_shortlist_threshold' => ['required', 'integer', 'min:40', 'max:100'],
            'ai_auto_reject_enabled' => ['required', 'boolean'],
            // Capped at 40 (the model's "weak" ceiling) so auto-reject can never
            // be widened into applicants the model considered a plausible match.
            'ai_auto_reject_below' => ['required', 'integer', 'min:5', 'max:40'],
            'ai_screening_call_enabled' => ['required', 'boolean'],
            // Applicants shown per job at first, and per batch after that.
            'applicant_first_batch' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'applicant_next_batch' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        Setting::set('first_post_free_enabled', $data['first_post_free_enabled'] ? '1' : '0');
        Setting::set('kyc_verification_enabled', $data['kyc_verification_enabled'] ? '1' : '0');
        if (isset($data['worker_verification_enabled'])) {
            Setting::set(Verification::WORKER_KEY, $data['worker_verification_enabled'] ? '1' : '0');
        }
        if (isset($data['employer_verification_required'])) {
            Setting::set('employer_verification_required', $data['employer_verification_required'] ? '1' : '0');
        }
        Setting::set(ScoreApplication::ENABLED_KEY, $data['ai_auto_shortlist_enabled'] ? '1' : '0');
        Setting::set(ScoreApplication::THRESHOLD_KEY, (string) $data['ai_auto_shortlist_threshold']);
        Setting::set(ScoreApplication::REJECT_ENABLED_KEY, $data['ai_auto_reject_enabled'] ? '1' : '0');
        Setting::set(ScoreApplication::REJECT_BELOW_KEY, (string) $data['ai_auto_reject_below']);
        Setting::set(ScreeningService::ENABLED_KEY, $data['ai_screening_call_enabled'] ? '1' : '0');
        foreach ([ApplicantAccess::FIRST_BATCH_KEY, ApplicantAccess::NEXT_BATCH_KEY] as $key) {
            if (isset($data[$key])) {
                Setting::set($key, (string) $data[$key]);
            }
        }

        return back()->with('toast', ['type' => 'success', 'message' => __('Settings updated.')]);
    }

    /**
     * GST and the seller details printed on invoices. Saved on its own so a
     * typo in a GSTIN cannot block the feature toggles above it, and the other
     * way round.
     *
     * A new rate or price reaches Razorpay by itself: the next checkout on each
     * plan finds its Razorpay plan charging the old amount and makes a new one.
     */
    public function updateBilling(Request $request): RedirectResponse
    {
        $request->merge([
            'seller_gstin' => strtoupper(trim((string) $request->input('seller_gstin'))),
        ]);

        $data = $request->validate([
            'gst_enabled' => ['required', 'boolean'],
            'gst_percent' => ['required', 'numeric', 'min:0', 'max:28'],
            'seller_name' => ['required', 'string', 'max:150'],
            'seller_address' => ['required', 'string', 'max:300'],
            'seller_gstin' => ['required', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'sac_code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'invoice_copy_email' => ['nullable', 'email', 'max:150'],
        ], [
            'seller_gstin.regex' => __('That is not a valid GSTIN (15 characters, e.g. 06AAFCP6967R1ZF).'),
            'sac_code.regex' => __('SAC is 4 to 8 digits.'),
        ]);

        if (GstStates::codeFromGstin($data['seller_gstin']) === null) {
            return back()->withErrors(['seller_gstin' => __('The first two digits are not an Indian state code.')]);
        }

        Setting::set(Gst::ENABLED_KEY, $data['gst_enabled'] ? '1' : '0');
        Setting::set(Gst::PERCENT_KEY, (string) round((float) $data['gst_percent'], 2));
        Setting::set(Gst::SELLER_NAME_KEY, trim($data['seller_name']));
        Setting::set(Gst::SELLER_ADDRESS_KEY, trim($data['seller_address']));
        Setting::set(Gst::SELLER_GSTIN_KEY, $data['seller_gstin']);
        Setting::set(Gst::SAC_KEY, $data['sac_code']);
        Setting::set(Gst::INVOICE_COPY_KEY, trim((string) ($data['invoice_copy_email'] ?? '')));

        return back()->with('toast', ['type' => 'success', 'message' => __('Billing settings updated.')]);
    }

    /**
     * The mobile apps' update check and maintenance switch (GET /api/v1/app/*).
     */
    public function updateApps(Request $request): RedirectResponse
    {
        $version = ['nullable', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,3}$/'];
        $rules = [];

        foreach (MobileApps::APPS as $app) {
            $rules["settings.$app.update_message"] = ['nullable', 'string', 'max:300'];
            $rules["settings.$app.maintenance"] = ['required', 'boolean'];
            $rules["settings.$app.maintenance_message"] = ['nullable', 'string', 'max:300'];
            $rules["settings.$app.maintenance_until"] = ['nullable', 'date'];

            foreach (MobileApps::PLATFORMS as $platform) {
                $rules["versions.$app.$platform.latest"] = $version;
                $rules["versions.$app.$platform.min"] = $version;
                $rules["versions.$app.$platform.store_url"] = ['nullable', 'url', 'max:300'];
            }
        }

        $data = $request->validate($rules, ['regex' => __('Use a version like 1.4.2.')]);

        foreach (MobileApps::APPS as $app) {
            foreach (MobileApps::PLATFORMS as $platform) {
                $row = $data['versions'][$app][$platform] ?? [];
                $latest = $row['latest'] ?? null;
                $min = $row['min'] ?? null;

                if ($latest !== null && $min !== null && version_compare($min, $latest, '>')) {
                    return back()->withErrors(["versions.$app.$platform.min" => __('The minimum cannot be above the latest version.')]);
                }
            }
        }

        MobileApps::saveVersions($data['versions'] ?? []);
        MobileApps::saveAppSettings($data['settings']);

        $down = array_values(array_filter(MobileApps::APPS, fn (string $app) => (bool) $data['settings'][$app]['maintenance']));

        return back()->with('toast', [
            'type' => 'success',
            'message' => match (count($down)) {
                0 => __('App settings saved.'),
                1 => $down[0] === 'worker' ? __('Saved. The worker app is now in maintenance.') : __('Saved. The employer app is now in maintenance.'),
                default => __('Saved. Both apps are now in maintenance.'),
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function billing(): array
    {
        $seller = Gst::seller();
        $stored = Setting::get(Gst::PERCENT_KEY);

        return [
            'gst_enabled' => Gst::enabled(),
            // The rate itself, even while GST is off, so switching it back on
            // does not lose it.
            'gst_percent' => is_numeric($stored) ? (float) $stored : (float) config('billing.gst_percent', 18),
            'seller_name' => $seller['name'],
            'seller_address' => $seller['address'],
            'seller_gstin' => $seller['gstin'],
            'seller_state' => $seller['state_code'] ? GstStates::label($seller['state_code']) : null,
            'sac_code' => $seller['sac'],
            'invoice_prefix' => config('billing.invoice_prefix', 'KRG'),
            'invoice_copy_email' => Gst::invoiceCopyTo() ?? '',
            'plans' => Plan::orderBy('price')->get()->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'price' => (float) $plan->price,
                'interval' => $plan->interval,
            ]),
        ];
    }
}
