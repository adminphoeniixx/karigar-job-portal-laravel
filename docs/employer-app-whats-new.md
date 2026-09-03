# Super Karigar **Employer app** — what's new

Everything the **employer** app needs to integrate since the last handover.
Nothing here concerns the worker app.

Full reference: `docs/employer-app-api.md` ·
Postman: `docs/karigar-employer-app.postman_collection.json`

Base URL `{{base_url}}/api/v1`. Auth unchanged — Sanctum bearer token from OTP
verify, sent as `Authorization: Bearer <token>`. 🔒 = auth required.

**New endpoints for this app: 25.** Plus new fields on responses you already
parse, and two behaviour changes that need handling even though no endpoint
changed.

---

## 1. Applicant resumes 🔒 — new

Workers can now upload a resume PDF, and **the AI scores against it** instead of
their profile fields alone. Two consequences for this app: the match scores get
noticeably better, and you can show/download the actual document.

`ApplicantResource` gains a `resume` key — `null` when the worker has none:

```jsonc
"resume": { "name": "suresh-plumber.pdf",
            "uploaded_at": "2026-07-30T05:20:11+00:00",
            "download_url": "https://…/api/v1/employer/applicants/14/resume" }
```

`GET /employer/applicants/{application}/resume` 🔒 — this is what `download_url`
points at. About it:

- **Token-authenticated**, like every other call: send the usual
  `Authorization: Bearer` header and you get the PDF back
  (`Content-Disposition: attachment` with the worker's original filename), so
  you can preview or save it in-app. No webview or session cookie needed.
- Authorised **per application**: only the employer account that received this
  application can open it. Another employer gets `403`.
- **Not a public link** — it will not open outside an authorised request.
- `404` if the worker has since removed their resume, so handle that rather than
  assuming the URL stays good.

A "Resume" chip on the applicant card that opens the PDF is the whole feature.

---

## 2. Job boost 🔒

`POST /employer/jobs/{job}/boost` — body `{ "tier": "standard" }`

```jsonc
// → 200
{ "message": "Job boosted for 3 days.", "job": {...}, "credits": { ...CreditSummary } }

// → 422, drives the "out of credits" sheet
{ "message": "You do not have enough credits to boost this job.",
  "code": "out_of_credits", "credits": {...} }
```

- Spends **purchased** credits, never the plan's unlock allowance.
- `standard` = 1 credit / 3 days, `turbo` = 3 credits / 7 days — but read the
  tiers from `boost_tiers` in `GET /reference` rather than hardcoding them.
- Boosting an already-boosted job **extends** it. It never shortens it, so a
  second boost is always safe.

The job's own boost state comes back on `EmployerJobResource`:
`"boost": { "active": true, "tier": "standard", "until": "2026-08-02T…" }`.

---

## 3. Matched workers + invite 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/employer/jobs/{job}/matches` | "✨ Matched for this job" |
| `POST` | `/employer/jobs/{job}/invite` | Invite one to apply |

```jsonc
// GET → available workers whose skills/city/experience fit and who have NOT applied
{ "workers": [ { "id", "user_id", "name", "avatar_url", "skills": [...], "city",
    "experience_years", "expected_wage", "wage_type", "available", "verified",
    "rating", "invited": false } ], "total": 6 }

// POST body → 201
{ "worker_id": 42, "message": "Site is in Guindy, start Monday." }
{ "message": "Invite sent to Ravi.", "invited": true }
```

Invite is **idempotent** — inviting the same worker twice returns `200` with
`"invited": true` and sends nothing. Drive the button state off `invited` rather
than tracking it locally.

---

## 4. Interviews 🔒 — the pipeline is now five stages

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/employer/applicants/{application}/interview` | Schedule / reschedule |
| `DELETE` | `/employer/applicants/{application}/interview` | Cancel |

```jsonc
// POST body → 200
{ "interview_at": "2026-08-02T10:30:00+05:30", "mode": "site",
  "note": "Gate 2, ask for Anil" }
{ "message": "Interview invite sent.", "applicant": { ...stage: "interview" } }
```

- `mode` is `site \| phone \| video` — see `interview_modes` in `GET /reference`.
- Scheduling **auto-shortlists** the applicant and notifies the worker.
- Cancelling drops them back to `shortlisted`, **not** to `pending`.

The pipeline is now `pending → shortlisted → interview → hired | rejected`.
Filter with `GET /employer/jobs/{job}/applicants?stage=interview`; the `counts`
object in that response includes the new stage. If your segmented control has
four tabs, it needs a fifth.

Applicants also gain an `offer` block, set when you hire:
`"offer": { "wage": "900.00", "start_date": "2026-08-05", "message": "Report by 9 AM" }`
— these are the Hire sheet's fields, echoed back. Ignored on a reject.

---

## 5. Credits & plans 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/employer/plans` | Catalogue, current plan, packs, invoices, Razorpay key |
| `POST` | `/employer/plans/{plan}/subscribe` | Start a subscription |
| `POST` | `/employer/plans/callback` | Verify checkout, activate |
| `POST` | `/employer/credits/top-up` | Buy a credit pack |
| `POST` | `/employer/credits/callback` | Verify the top-up |

`GET /employer/plans` returns everything the Credits & Plans screen needs in one
call — `plans`, `credit_packs`, `boost_tiers`, `invoices`, `credits`, `current`,
and `payment.key` for Razorpay checkout.

**CreditSummary** also comes back from the dashboard, contact unlock and boost.
Refresh your local balance from it every time rather than decrementing yourself:

```jsonc
{ "balance": 12, "unmetered": false, "purchased": 12, "plan_limit": 50,
  "plan_remaining": 0, "unlocks_used": 50, "plan": "Starter",
  "plan_label": "Starter · renews 28 Aug 2026", "directory_quota": 25 }
```

Both purchases follow the same two-step flow: call the create endpoint, open
Razorpay checkout with the returned id + `razorpay_key`, then post the signature
to the matching `callback`.

```jsonc
// POST /employer/credits/top-up  { "pack": "topup_25" }
// → 201
{ "purchase_id", "razorpay_order_id", "razorpay_key", "amount", "credits", "currency" }

// POST /employer/credits/callback
{ "razorpay_payment_id": "pay_x", "razorpay_order_id": "order_x", "razorpay_signature": "..." }
// → 200
{ "message": "25 credits added.", "credits": { ...CreditSummary } }
```

Things that will bite otherwise:

- Contact unlocks spend the **plan allowance first**, then purchased credits.
  Boosts always spend **purchased** credits.
- `plan_limit: 0` means the plan does not meter unlocks (`unmetered: true`) —
  don't show a remaining count in that case.
- Subscribe is **owner only**. Team members get `403` with
  "Only the account owner can change the plan." Hide the button for them.
- The top-up callback is **idempotent** — replaying an order does not
  double-credit, so a retry after a flaky network is safe.
- `422` on subscribe when payments are unconfigured, the plan has no Razorpay
  plan id, or the coupon is invalid.

---

## 6. Chat with workers 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/conversations` | Thread list + `unread_total` |
| `POST` | `/conversations` | Open (or reuse) a thread |
| `GET` | `/conversations/{id}` | Latest 30 messages, marks the thread read |
| `POST` | `/conversations/{id}/messages` | Send a message |
| `POST` | `/conversations/{id}/read` | Mark read |

```jsonc
// POST /conversations
{ "worker_id": 42, "job_id": 3, "body": "Can you start Monday?" }
// → 201 (new) or 200 (existing thread)
{ "conversation": { "id": 4, "other_party": {...}, "job": {...},
                    "unread": 0, "last_message_at": "..." } }
```

Rules to build against:

- **You can only message a worker who has applied to one of your jobs.**
  Anything else is `422` with `"code": "chat_not_allowed"`. In particular this
  means **no chat button on the Find Workers / directory screens** — those
  workers haven't applied. (The error text also mentions unlocked contacts, but
  the backend does not currently allow that path; treat "has applied" as the
  rule.)
- Threads are scoped to the employer **account**, so team members share them.
- `unread` counts messages the **worker** sent — team members don't mark each
  other's messages unread.
- New messages arrive as FCM `type: "chat.message"` with `conversation_id` and
  `url`. Reuse the existing push handler.
- `GET /conversations/{id}` marks the thread read as a side effect. Use
  `GET /conversations` to refresh a badge.

---

## 7. App settings & devices 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/preferences` | Theme + alert toggles |
| `PUT\|PATCH` | `/preferences` | Any subset of the keys |
| `GET` | `/auth/sessions` | Signed-in devices |
| `DELETE` | `/auth/sessions/{token}` | Sign that device out |

```jsonc
{ "preferences": { "theme": "system", "applicant_alerts": true,
                   "job_alerts": true, "message_alerts": true } }

{ "sessions": [ { "id": 27, "device": "Pixel 8", "current": true,
                  "last_used_ago": "2 minutes ago",
                  "last_used_at": "...", "created_at": "..." } ] }
```

`theme` is `system \| light \| dark`; send only the keys you're changing. For
this app `applicant_alerts` and `message_alerts` are the relevant toggles.
Deleting the session with `"current": true` logs **this** device out — confirm
first. `404` if the token isn't yours.

---

## 8. Other new fields on responses you already parse

**EmployerJobResource** — `experience_min`, `views` (the funnel's first bar),
`share_url`, and `boost: { active, tier, until }`.

**EmployerProfileResource** — `hiring_as`, `industry`, `company_size`,
`hiring_categories`, for the registration wizard. Their option lists come from
`GET /reference` (`hiring_as`, `industries`, `company_sizes`, `job_categories`).

---

## 9. Behaviour changes with no new endpoint

### AI scoring is always on, and lands asynchronously

Every applicant is scored the moment they apply. `ApplicantResource.ai` carries
it:

```jsonc
"ai": { "score": 95, "recommendation": "strong_match",
        "summary": "Experienced plumber",
        "matched_skills": ["Plumbing", "Pipe Fitting", "Welding"],
        "red_flags": [] }
```

- `recommendation` is `strong_match \| good_match \| maybe \| weak`.
- The applicant list defaults to `sort=best_match`; `sort=recent` is the other.
- **`ai` can be `null`** for a few seconds after someone applies, because
  scoring runs in the background. Render the row without a badge rather than
  blocking on it, and re-fetch shortly after. Do not treat `null` as an error.

### Auto-shortlist and auto-reject

Two admin-controlled switches, **both off by default**, that let the backend
move an application on its own:

- a high scorer becomes **shortlisted** and the worker is notified
- a low scorer becomes **rejected** and the worker is notified

Neither has an endpoint and neither is togglable from this app — but when they
are on, **an applicant's stage can change with no tap from this user**.

What that means for you:

- Don't assume a `shortlisted` or `rejected` applicant was moved by the person
  holding the phone.
- Refresh the list and the `counts` from the server when the screen opens.
  Locally cached stage counts will drift.

Auto-reject never touches an application that has been shortlisted, has an
interview booked, or was already decided by the employer — so it will not
overwrite anything this app did.

---

## 10. AI screening calls 🔒 — new screen

The biggest new thing, and there is **no screen for it in the mockup** — please
design one with us. The platform rings the applicant on a real phone line, asks
in their language whether they are still interested, and collects an interview
time to offer. The agent never books anything: **the employer confirms the
slot**, because the agent has no idea what the employer's week looks like.

Three endpoints, all on an applicant you already have:

```
GET  /employer/applicants/{application}/screening-calls
POST /employer/applicants/{application}/screening-calls      → 202
POST /employer/screening-calls/{call}/confirm
```

**The list** returns the calls plus the button state:

```jsonc
{ "calls": [ { ...ScreeningCallResource } ],
  "can_call": false, "blocked_because": "already_screened" }
```

Drive the "Call & schedule" button off `can_call` — do not compute it yourself.
When it is `false`, `blocked_because` is one of `provider_not_configured`,
`no_caller_id`, `application_closed`, `interview_already_scheduled`,
`no_phone_number`, `worker_opted_out`, `call_in_progress`, `already_screened`.
Show it as the disabled reason (wording is in `docs/employer-app-api.md` §6b).

**Placing a call** takes no body and returns `202` with `calling_at`. Calls only
go out inside the permitted daytime window, so one queued at night is **held
until morning** — compare `calling_at` to now and show either "Calling now…" or
"Queued for 9:00 AM". `422` with a `code` from the list above when it is blocked.

The call itself runs in the background. The app learns the result from the push
notification and by re-fetching the list — **there is no websocket**, so do not
sit in a polling loop; refresh on screen open and on push.

**A finished call** carries what the worker said:

```jsonc
{ "id": 7, "status": "completed", "outcome": "interested",
  "summary": "Suresh is interested and can come Thursday morning.",
  "proposed_interview_at": "2026-08-06T10:00:00+05:30",
  "proposed_interview_label": "06 Aug 2026, 10:00 AM",
  "proposed_mode": "site", "awaiting_confirmation": true,
  "duration_seconds": 74, "created_ago": "20 minutes ago" }
```

`awaiting_confirmation: true` is the call to action — show a "Confirm interview"
button. `POST .../confirm` with an **empty body** accepts the slot as proposed;
send `interview_at` only to move it, and `mode` from `interview_modes` in
`GET /reference`. On success the interview is booked and the worker notified,
and you get the updated `applicant` back — so the applicant jumps to the
`interview` stage (§4) without a second fetch. `422 { "code":
"no_proposed_slot" }` when the call produced no time to confirm (the worker said
no, or never answered).

`transcript` is only included when you pass `?with_transcript=1`. It is long —
ask for it on the call-detail screen only, never in the list.

The worker's phone number is **not** in any of these payloads. The platform
placed the call; the number stays behind the contact-unlock paywall.

---

## 11. AI job-description drafts 🔒

`GET /employer/jobs/suggest-description?title=…&category=…&city=…&state=…&skills[]=…`

A "Suggest with AI" button on the Post Job screen, so the employer is not
staring at an empty textarea — the web has had this for a while and the app
should match.

```jsonc
{ "suggestions": [ "We need an experienced plumber…", "Looking for a skilled plumber…" ] }
```

- `title` is required (3–150 chars). Everything else is optional and only
  sharpens the draft — send whatever the form has so far.
- Normally **two** drafts come back; show them as pickable cards and let the
  employer edit after choosing.
- With no AI key configured (or the provider down) you get **one**
  template-built draft instead. Always render whatever length the array is —
  do not index `[1]` blindly.
- Throttled to 20/min, and the same wording returns cached drafts for a day, so
  a repeat tap is cheap but not a fresh idea.

---

## 12. Shortlisted across all jobs 🔒

`GET /employer/shortlisted` — paginated 20/page, most recently shortlisted
first, each row carrying its `job`.

The web has a standalone Shortlisted screen and the app should too: employers
think in terms of "people I liked", not "people I liked on job #12". Rows are
plain `ApplicantResource`, so the applicant card component is reusable as-is.

The per-job tab is unchanged — that is still the applicants list with
`stage=shortlisted`.

---

## 13. Invoices 🔒

`GET /employer/invoices/{subscription}` returns the tax invoice **as data**, so
the app lays it out natively (and can print or share from there):

```jsonc
{ "invoice": { "number": "KRG-2026-00001", "date": "28 Jul 2026",
               "plan": { "name": "Starter", "interval": "monthly", "price": 399 },
               "coupon_code": "FIRST20", "discount": 100, "subtotal": 399,
               "gst_percent": 18, "gst_amount": 71.82, "total": 470.82,
               "period": { "from": "28 Jul 2026", "to": "28 Aug 2026" },
               "payment_ref": "sub_xxx" },
  "seller": { "name", "address", "gstin", "email" },
  "buyer":  { "name", "address", "gstin", "email", "phone" } }
```

Ids come from `invoices[].id` on `GET /employer/plans`, where each row now
carries **two** links: `url` (this JSON endpoint — use it) and `web_url` (the
printable web page, for an "open in browser" fallback). `403` for another
account's invoice, `404` before the subscription is paid. Team members read the
**owner's** invoices, matching the rest of billing.

---

## 16. Terms & Privacy and Help & Support 🔓 — two new screens

Both settings rows in the mockup are wired up now, and **neither needs a
token** — the OTP screen links to the legal documents before an account exists.

```
GET /legal                 → both documents, no bodies (the settings row)
GET /legal/{document}      → terms | privacy, full body
GET /support?audience=employer  → FAQs + ways to reach a person
```

**Legal documents** come back as sections of blocks, so you render them in your
own type rather than in a webview:

```jsonc
"sections": [
  { "id": "what-we-collect", "title": "What we collect", "blocks": [
      { "type": "heading",   "text": "Everyone" },
      { "type": "list",      "items": ["Your mobile number…", "Your name…"] },
      { "type": "paragraph", "text": "We use your information to…" }
  ] }
]
```

- **Exactly three block types.** `paragraph` and `heading` carry `text`, `list`
  carries `items`. Nothing else will ever appear — three renderers and you are done.
- `heading` is a sub-heading *inside* a section. Render it smaller than the
  section title, and keep it out of any contents rail — build that from
  `sections[].title` + `sections[].id`.
- **Sections can disappear.** The privacy policy drops its identity-document
  section when an admin switches verification off. Build the screen from what
  the response contains, never from a hardcoded list.
- `web_url` is an "open in browser" link, and is **`null` for the terms** — no
  web page for them yet. Handle null or you will ship a dead button.
- English only for now.

**Help & Support:**

```jsonc
{ "channels": { "email": "…", "whatsapp": "919000000000", "phone": "…",
                "hours": "Monday to Saturday, 10 AM to 7 PM IST" },
  "faqs": [ { "id": "otp-not-received", "audience": "all",
              "question": "…", "answer": "…" } ] }
```

- **A channel that is not configured is left out of `channels` entirely.** Do
  not render a row for a key that is not there — that is how we switch a channel
  on later without an app release.
- `whatsapp` is digits with the country code and **no `+`**, so you build the
  `wa.me` link yourself.
- Pass `audience=employer` to get your app's questions plus the shared ones. Omitting
  it returns every question including the other app's; anything else → `422`.
- `id` is stable, so an answer can be deep-linked from elsewhere in the app.

---

## 14. Integration checklist

- [ ] Applicant card: `resume` chip + **token-auth** download, `null` and `404` handled
- [ ] Applicant list: AI badge, **`ai: null` handled**, `sort=best_match` default
- [ ] Pipeline: **five** stages, `stage=interview` filter, updated `counts`
- [ ] Interview: schedule / reschedule / cancel sheet
- [ ] Hire sheet: `offered_wage`, `start_date`, `message` → `offer` back
- [ ] Matched workers + invite (button state from `invited`)
- [ ] Boost sheet + out-of-credits sheet on `code: "out_of_credits"`
- [ ] Credits & Plans screen, both Razorpay flows, owner-only subscribe
- [ ] CreditSummary refreshed from responses, never decremented locally
- [ ] Chat: thread list/view/send + FCM; **no chat button in Find Workers**
- [ ] Settings: theme + `applicant_alerts` / `message_alerts`
- [ ] Login & security: device list + sign-out-device
- [ ] Job card: `views`, `boost` badge, `share_url`
- [ ] Registration wizard: `hiring_as`, `industry`, `company_size`, `hiring_categories`
- [ ] Screening calls: new screen, `can_call` / `blocked_because`, `calling_at`
      window copy, confirm-slot sheet, refresh on push (**no polling loop**)
- [ ] Post Job: "Suggest with AI" — handle a **1-item** suggestions array
- [ ] Shortlisted screen across all jobs (`GET /employer/shortlisted`)
- [ ] Invoice screen rendered from JSON; `web_url` only as a fallback
- [ ] Terms & Privacy + Help & Support screens (3 block types, null `web_url`,
      missing channel keys, `audience=employer`)
- [ ] Don't cache applicant stage/status — the backend can change them

---

## 15. Gotchas that have cost us time before

- **OTP is 4 digits**, not 6. The mockups draw six boxes; the backend issues four.
- Send `Accept: application/json` on **every** call. Without it a validation
  failure comes back as a redirect instead of a `422`.
- `GET /reference` (public) now also carries `interview_modes`, `boost_tiers`,
  `credit_packs`, `industries`, `company_sizes` and `hiring_as`. Read these from
  the API — they change without an app release.
- Money fields arrive as **strings** (`"800.00"`). Parse, don't concatenate.
- Several endpoints signal a business failure as `422` **with a `code`**
  (`out_of_credits`, `chat_not_allowed`). Branch on `code`, not on the message
  text — the text is translated.

Questions on any payload: `docs/employer-app-api.md` has the full shapes, and
the Postman collection is importable and current.
