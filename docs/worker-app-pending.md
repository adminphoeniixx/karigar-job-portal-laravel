# Worker app — what is still pending

**As of:** 2026-09-07
**Scope:** Worker side only (worker mobile app + the backend that serves it).
The employer side has its own list.

Everything here is either **backend work** (an endpoint or a field that does not
exist yet, so the app cannot build the screen) or **app work** (the API is live
and documented, the screen is not built yet). They are separated on purpose —
item 1 in each list is not the same kind of task.

---

## 1. Backend pending

### 1.1 `screening_calls_opted_out` cannot be read back

The column exists (`worker_profiles.screening_calls_opted_out`), the update
request accepts it (`app/Http/Requests/Api/WorkerProfileRequest.php:48`), and
`ScreeningService` honours it — but **`WorkerProfileResource` does not return
it**, so the app can write the toggle and never render its current state.

- Missing from: `app/Http/Resources/Api/WorkerProfileResource.php`
- Missing from: `docs/worker-app-api.md` §3 (the `PATCH /worker/profile` field list)

TRAI's rules on automated voice calls require a working opt-out, and the
screening call feature dials workers — so this is a compliance item, not a
nice-to-have. The web profile page already has the switch
(`resources/js/pages/profile/Worker.vue:226`); the app has nothing.

**Fix:** add `'screening_calls_opted_out' => (bool) $this->screening_calls_opted_out`
to the resource and document the field. Small.

### 1.2 No endpoint for job invites

An employer can invite a worker to apply (`POST /employer/jobs/{job}/invite`,
`job_invites` table, `JobInviteNotification`), but the worker side has **no
`GET /worker/invites`**. The invite reaches the worker only as a push /
notification row; the invite's `message` is not readable anywhere, and there is
no "Invitations" screen to build.

**Decide first:** is an invites screen part of the worker app? If yes, this needs
a list endpoint (+ probably `accepted_at` / dismissed state). If no, the employer
app's invite button is sending into a void beyond the one push.

### 1.3 No "reviews I have written"

`GET /worker/reviews` returns ratings **received**. There is deliberately no
endpoint for ratings the worker has *given* — see `docs/reviews-api.md`
("Given vs received"). Nothing in the app needs it today. Listed here so it is a
decision on record, not a surprise later.

### 1.4 Escrow / payouts are not in the app API

Web has the whole surface (fund, release, refund, admin ledger, RazorpayX
payouts). The app API has **only `payout_upi` on the profile** — a worker can
enter where the money should go and then cannot see a single escrow.

Also blocked on config: `RAZORPAYX_ACCOUNT_NUMBER` is unset, so
`PayoutService::payout()` throws before it reaches Razorpay. Verify on the
server, not locally.

Deferred by choice so far. Revisit as one piece of work: config + API + screens.

### 1.5 No public Terms page

`GET /legal` returns `web_url: null` for `terms` — `https://superkarigar.com/terms`
is a **404**. Only `/privacy` exists (`resources/js/pages/Privacy.vue`).

The in-app terms screen renders fine from the JSON blocks, so the app is not
blocked — but the "open in browser" button has nowhere to go, and app store
submissions ask for a terms URL alongside the privacy URL.

---

## 2. App pending (API is live and documented)

These are the open items from the worker integration checklist. Backend needs no
work; the shapes are in `docs/worker-app-api.md` and the Postman collection.

- [ ] **Resume** — upload / replace / remove on the profile screen
      (`GET|POST|DELETE /worker/resume`). Handle the "no readable text in PDF"
      `422` with its own message, and show `characters` so the worker knows it
      was read. Workers without a resume score measurably worse — worth a nudge.
- [ ] **Chat** — thread list, thread view, send, unread badge
      (`/conversations`), plus the FCM `chat.message` handler. Button only where
      the worker has applied.
- [ ] **"Rate employer" button** driven by `can_review` on the application row —
      never by `status` alone. Full rules in `docs/reviews-api.md`.
- [ ] **Application tracker** — the 4-step `tracking_steps` timeline
      (`docs/app-changelog-email-and-tracker.md`). Render from `state`, never
      recompute from `status`.
- [ ] **Interview and offer blocks** on the application row.
- [ ] **Terms & Privacy + Help & Support screens** — three block types, `null`
      `web_url` (see 1.5), missing channel keys, `audience=worker`.
- [ ] **Settings** — theme + `job_alerts` / `message_alerts` (`/preferences`).
- [ ] **Login & security** — device list + sign-out-device
      (`/auth/sessions`), with a confirm step on the current device.
- [ ] **Email field** on profile/registration — `null` means "not set", never
      show the `@phone.karigar` placeholder.

---

## 3. The mockup is stale

`app-mockup/worker-app.html` predates most of the above. Screens present:
onboard, otp, register, home, jobs, job detail, applications, saved, reviews,
notifications, profile, edit profile, KYC, settings.

Not drawn at all: **resume**, **chat / conversations**, **login & security
(devices)**, **legal & support**, **invites**. Also: the mockup draws **six** OTP
boxes; the backend issues **four**.

Treat `docs/worker-app-api.md` as the contract and the mockup as a visual hint
only. If the mockup needs to catch up, say so — it is a separate piece of work.

---

## 4. Live but undocumented

`/api/v1/calls` (six routes: list, initiate, answer, reject, end, refresh) is
deployed and reachable, and appears in **neither app doc**. It is the in-app
voice calling groundwork (Agora), unrelated to the AI screening calls, and
currently dormant — `AGORA_APP_ID` is not configured.

Either document it or leave it dormant deliberately; right now an app developer
cannot know it exists.

---

## 5. Suggested order

1. **1.1 opt-out field** — smallest item here, and the only compliance one.
2. **2. app-side screens** — resume and chat are the two that change day-to-day
   usage most; everything they need is already live.
3. **1.5 terms page** — needed before store submission.
4. **1.2 invites** — decide the screen first, then build the endpoint.
5. **1.4 escrow / payouts** — one deliberate piece of work, config included.
6. **1.3 reviews-given** — only if a screen actually asks for it.
