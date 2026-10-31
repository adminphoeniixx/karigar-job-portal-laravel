# App updates, by date

Each folder is one day's server changes that the mobile apps need. Hand the
developer the folder for the dates since their last build.

- `employer-app.md`: changes for the employer app
- `worker-app.md`: changes for the worker app

A folder only has the files for an app that changed that day. Every file is
for one app: a change both apps need gets a `worker-…` and an `employer-…`
file, each with that app's own endpoints.

| Date | Employer app | Worker app |
|---|---|---|
| [2026-10-31](2026-10-31/) | [DigiLocker opens DigiLocker itself (API Setu, no Decentro): let `/kyc/digilocker/callback` load, close only on `return_url_prefix`; which business types are approved at once](2026-10-31/employer-digilocker-signin-via-apisetu.md) · [Leaving DigiLocker without sharing: plain message instead of Decentro's "initial_decentro_transaction_id" error](2026-10-31/employer-digilocker-cancel-plain-message.md) · [`/employer/kyc*` 404 now carries `code: verification_disabled`](2026-10-31/employer-kyc-404-says-why.md) | [DigiLocker opens DigiLocker itself (API Setu, no Decentro): let `/kyc/digilocker/callback` load, close only on `return_url_prefix`; PAN matching DigiLocker is verified at once](2026-10-31/worker-digilocker-signin-via-apisetu.md) · [Leaving DigiLocker without sharing: plain message instead of Decentro's "initial_decentro_transaction_id" error](2026-10-31/worker-digilocker-cancel-plain-message.md) · [Home "KYC Status" card: hide it while `features.verification_enabled` is false, tap opens the KYC screen (DigiLocker Aadhaar + PAN); `/kyc` 404 now carries `code: verification_disabled`](2026-10-31/worker-kyc-status-tile-opens-kyc.md) |
| [2026-10-30](2026-10-30/) | — | [AI resume builder in the app, same as the website: 4 steps, 3 designs × 4 colours, strength meter, live PDF preview (no server change)](2026-10-30/worker-ai-resume-builder-same-as-web.md) · [Home "Available Jobs" counts the karigar's own feed, same as the website (140 → 72)](2026-10-30/worker-available-jobs-count-matches-web.md) |
| [2026-10-27](2026-10-27/) | — | [A job leaves the feed and home "Latest jobs" once the karigar applies (search still finds it)](2026-10-27/worker-applied-jobs-leave-the-feed.md) |
| [2026-10-26](2026-10-26/) | [A cancelled plan (UPI AutoPay turned off) runs to its `ends_at`; `current.status` can be `cancelled` / `completed` while on](2026-10-26/employer-cancelled-plan-runs-to-its-end.md) | — |
| [2026-10-24](2026-10-24/) | [Plan ended: no free post to fall back on (`subscription_required`), invite refuses paused jobs (`job_not_hiring`), booked AI calls cancelled](2026-10-24/employer-plan-lapse-checks.md) · [Plan `badge` text, admin-written `feature_list`, AI call credits included with each plan payment](2026-10-24/employer-plan-badge-and-feature-lines.md) | — |
| [2026-10-23](2026-10-23/) | [Job safety check runs in the background: `job_checking` code, `moderation` on every job (`approved` + `method`), 409 while checking, `passed`/`updated` push states](2026-10-23/employer-job-safety-check-queued.md) · [Plan renews / ends / payment failed / ended reminders: `plan.reminder` push](2026-10-23/employer-plan-reminders.md) | — |
| [2026-10-21](2026-10-21/) | [Apna-priced plans: `tagline`, `mrp` + `discount_percent`, `duration_days`, `job_live_days`; jobs end after the plan's job days](2026-10-21/employer-plans-mrp-and-lengths.md) | — |
| [2026-10-16](2026-10-16/) | [Hand shortlist starts the AI screening call (now or booked for the morning)](2026-10-16/employer-shortlist-starts-ai-call.md) · [AI call credits: recharge packs, per-minute charge, ledger, job-form gate](2026-10-16/employer-ai-call-credits.md) · [AI call recording: `recording_url` on every call, play bar on View conversation](2026-10-16/employer-ai-call-recording.md) | — |
| [2026-10-15](2026-10-15/) | [AI call result on the applicant card, View conversation (`GET /employer/screening-calls/{call}`), "Strong match" label](2026-10-15/employer-app.md) | [Home "Latest jobs near you" is a plain array (doc was wrong) · search box finds jobs by city/state](2026-10-15/worker-app.md) |
| [2026-10-13](2026-10-13/) | — | [AI resume builder: form → AI draft → edit → PDF resume; `GET /worker/resume/file`](2026-10-13/worker-app.md) |
| [2026-10-12](2026-10-12/) | [Business verification checked automatically (PAN/GST, DigiLocker), instant approval · Worker Database: free reject, distance + area, "unlocked by"](2026-10-12/employer-app.md) · [30-day plans, job post limits](2026-10-12/employer-plans-30-days-job-post-limits.md) · [AI Interviews + booking AI calls](2026-10-12/employer-ai-interviews-and-call-scheduling.md) · [AI shortlist from database](2026-10-12/employer-ai-shortlist-from-database.md) · [Job safety check](2026-10-12/employer-job-safety-check.md) · [Applicant card fixes: no "Contact unlocked", rejected buttons, distance line](2026-10-12/employer-applicant-card-fixes.md) | [KYC needed to apply; DigiLocker Aadhaar, instant approval · faster "Jobs near" · karigar's area](2026-10-12/worker-app.md) |
| [2026-10-09](2026-10-09/) | [Applicants' numbers show without an unlock; unlocks are for the Worker Database only](2026-10-09/employer-app.md) · [Hire sheet: send the offer](2026-10-09/hire-offer.md) | [Hire offer card, "You're hired" push](2026-10-09/hire-offer.md) |
| [2026-10-07](2026-10-07/) | [Invoice per payment, invoice email, worker email `null`, app update + maintenance](2026-10-07/employer-app.md) · [Order history + already-purchased plans](2026-10-07/employer-app-orders.md) | [App update + maintenance; Terms & Help endpoints](2026-10-07/worker-app.md) · both apps: [4 APIs](2026-10-07/app-update-maintenance-legal-support-apis.md) |

Older handover notes, from before this folder existed, are the
`employer-app-*.md` and `worker-app-*.md` files in `docs/`.
Full API references: `docs/employer-app-api.md`, `docs/worker-app-api.md`.
