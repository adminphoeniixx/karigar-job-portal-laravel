# Super Karigar **Employer app** — what's new

Everything the **employer** app needs to integrate since the last handover.
Nothing here concerns the worker app.

Full reference: `docs/employer-app-api.md` ·
Postman: `docs/karigar-employer-app.postman_collection.json`

Base URL `{{base_url}}/api/v1`. Auth unchanged — Sanctum bearer token from OTP
verify, sent as `Authorization: Bearer <token>`. 🔒 = auth required.

**New endpoints for this app: 18.** Plus new fields on responses you already
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
            "download_url": "https://…/employer/applications/14/resume" }
```

About `download_url`:

- It is a **web** URL — no `/api/v1` prefix. It streams the PDF.
- Authorised **per application**: only the employer account that received this
  application can open it. Another employer gets `403`.
- Open it in a webview carrying the session, or fetch it with the bearer token.
  **Do not treat it as a public link** — it isn't one, and it won't work outside
  an authorised context.
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

## 10. Integration checklist

- [ ] Applicant card: `resume` chip + authorised download, `null` and `404` handled
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
- [ ] Don't cache applicant stage/status — the backend can change them

---

## 11. Gotchas that have cost us time before

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
