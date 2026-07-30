# Karigar apps — what's new for the app developer

Everything the worker and employer apps need to integrate since the last
handover. **22 new endpoints**, some new fields on responses you already parse,
and two behaviour changes that need handling even though no endpoint changed.

Full request/response reference (unchanged location):

| | Reference | Postman |
| --- | --- | --- |
| Worker app | `docs/worker-app-api.md` | `docs/karigar-worker-app.postman_collection.json` |
| Employer app | `docs/employer-app-api.md` | `docs/karigar-employer-app.postman_collection.json` |

Base URL `{{base_url}}/api/v1`. Auth unchanged: Sanctum bearer token from OTP
verify, sent as `Authorization: Bearer <token>`. 🔒 = auth required.

---

## 1. Shared by both apps

### Chat — employer ↔ worker messaging 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/conversations` | Thread list + `unread_total` |
| `POST` | `/conversations` | Open (or reuse) a thread |
| `GET` | `/conversations/{id}` | Latest 30 messages, marks the thread read |
| `POST` | `/conversations/{id}/messages` | Send a message |
| `POST` | `/conversations/{id}/read` | Mark read |

```jsonc
// POST /conversations
{ "employer_id": 8, "job_id": 3, "body": "Hi, is this still open?" }
// → 201 (new) or 200 (existing)
{ "conversation": { "id": 4, "other_party": {...}, "job": {...}, "unread": 0, "last_message_at": "..." } }

// POST /conversations/{id}/messages
{ "body": "Yes, I can start Monday." }
// → 201
{ "message": { "id": 88, "body": "...", "mine": true, "read_at": null, "created_at": "..." } }
```

Rules worth knowing before you build the screen:

- An **employer may only open a thread with a worker who applied** to one of
  their jobs **or whose contact they have unlocked**. Anything else is `422`
  with `"code": "chat_not_allowed"` — use that code to decide whether to show
  "Unlock contact to message" instead of the chat button.
- Acting on an existing thread you are not a participant of is `403`.
- `unread` counts *messages the other side sent*, so employer team members do
  not mark each other's messages unread.
- Messages arrive as an FCM push (`type: "chat.message"`, with
  `conversation_id` and `url`) — reuse the existing push handler.
- `GET /conversations/{id}` marks the thread read as a side effect. Don't call
  it just to refresh a badge; use `GET /conversations`.

### App settings 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/preferences` | Theme + alert toggles |
| `PUT\|PATCH` | `/preferences` | Any subset of the keys |

```jsonc
{ "preferences": { "theme": "system", "applicant_alerts": true,
                   "job_alerts": true, "message_alerts": true } }
```
`theme` is one of `system \| light \| dark`. Send only the keys you're changing.

### Signed-in devices 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/auth/sessions` | Devices holding a token |
| `DELETE` | `/auth/sessions/{token}` | Sign that device out |

```jsonc
{ "sessions": [ { "id": 27, "device": "Pixel 8", "current": true,
                  "last_used_ago": "2 minutes ago", "last_used_at": "...", "created_at": "..." } ] }
```
`404` if the token isn't yours. Deleting the **current** session logs this
device out — confirm before calling it.

---

## 2. Worker app only

### Resume upload 🔒 — new feature

This is the big one. The AI reads the worker's resume when scoring their
application, so uploading one **directly changes the match score the employer
sees**. Worth a prompt on the profile screen.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/worker/resume` | Current resume, or `null` |
| `POST` | `/worker/resume` | Upload / replace (**multipart**) |
| `DELETE` | `/worker/resume` | Remove |

```jsonc
// GET → none uploaded
{ "resume": null }

// GET → uploaded
{ "resume": { "name": "suresh-plumber.pdf",
              "uploaded_at": "2026-07-30T05:20:11+00:00",
              "uploaded_ago": "2 minutes ago",
              "characters": 1660, "max_characters": 8000 } }
```

**POST** is `multipart/form-data`, single field `resume`:

- **PDF only**, max **4 MB**. Uploading again replaces the old one — no separate
  delete needed.
- `201` on success, with the same `resume` object as above.
- `422` when it isn't a PDF, is too big, **or has no readable text layer**:

```jsonc
{ "message": "We could not read any text in that PDF. If it is a scan or photo, please upload a text PDF instead.",
  "errors": { "resume": ["No readable text found in the PDF."] } }
```

That last case is the one to design for. Workers will photograph a paper resume
and save it as a PDF; there is no text in it, so the AI cannot read it and we
refuse the upload. Show the message as-is — it tells them what to do instead.

`characters` is how much text we actually extracted. Showing something like
"1,660 characters read from your resume" reassures the worker it worked.

The PDF is stored privately (same as KYC documents) and is never publicly
reachable — there is no public URL for it, by design. Only an employer the
worker has applied to can fetch it.

---

## 3. Employer app only

### Job boost 🔒

`POST /employer/jobs/{job}/boost` — body `{ "tier": "standard" }`

Spends **purchased** credits (not the plan allowance): `standard` = 1 credit /
3 days, `turbo` = 3 credits / 7 days. Tiers come from `boost_tiers` in
`/reference` — read them from there rather than hardcoding.

```jsonc
// → 200
{ "message": "Job boosted for 3 days.", "job": {...}, "credits": { ...CreditSummary } }
// → 422, drives the "out of credits" sheet
{ "message": "You do not have enough credits to boost this job.",
  "code": "out_of_credits", "credits": {...} }
```
Boosting an already-boosted job **extends** it — it never shortens it.

### Matched workers + invite 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/employer/jobs/{job}/matches` | "✨ Matched for this job" |
| `POST` | `/employer/jobs/{job}/invite` | Invite one to apply |

```jsonc
// GET → workers whose skills/city/experience fit and who have NOT applied
{ "workers": [ { "id", "user_id", "name", "avatar_url", "skills": [...], "city",
    "experience_years", "expected_wage", "wage_type", "available", "verified",
    "rating", "invited": false } ], "total": 6 }

// POST body → 201
{ "worker_id": 42, "message": "Site is in Guindy, start Monday." }
{ "message": "Invite sent to Ravi.", "invited": true }
```
Invite is **idempotent** — inviting twice returns `200` with `"invited": true`
and sends nothing. Use the `invited` flag to keep the button in the right state.

### Interviews 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/employer/applicants/{application}/interview` | Schedule / reschedule |
| `DELETE` | `/employer/applicants/{application}/interview` | Cancel |

```jsonc
// POST body → 200
{ "interview_at": "2026-08-02T10:30:00+05:30", "mode": "site", "note": "Gate 2, ask for Anil" }
{ "message": "Interview invite sent.", "applicant": { ...stage: "interview" } }
```
`mode` is `site \| phone \| video` (see `interview_modes` in `/reference`).
Scheduling **auto-shortlists** the applicant and notifies the worker. Cancelling
drops them back to `shortlisted`, not to `pending`.

The applicant pipeline is now **five** stages, not four:
`pending → shortlisted → interview → hired \| rejected`. Filter with
`GET /employer/jobs/{job}/applicants?stage=interview`; `counts` in the response
includes the new stage.

### Credits & plans 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/employer/plans` | Catalogue, current plan, packs, invoices, Razorpay key |
| `POST` | `/employer/plans/{plan}/subscribe` | Start a subscription |
| `POST` | `/employer/plans/callback` | Verify checkout, activate |
| `POST` | `/employer/credits/top-up` | Buy a credit pack |
| `POST` | `/employer/credits/callback` | Verify the top-up |

`GET /employer/plans` returns everything the Credits & Plans screen needs in one
call — `plans`, `credit_packs`, `boost_tiers`, `invoices`, `credits`
(**CreditSummary**), and `payment.key` for Razorpay checkout.

**CreditSummary** also comes back from the dashboard, contact unlock and boost —
refresh your local balance from it every time rather than decrementing yourself:

```jsonc
{ "balance": 12, "unmetered": false, "purchased": 12, "plan_limit": 50,
  "plan_remaining": 0, "unlocks_used": 50, "plan": "Starter",
  "plan_label": "Starter · renews 28 Aug 2026", "directory_quota": 25 }
```

Flow for both purchases is the same two-step: call the create endpoint, open
Razorpay checkout with the returned id + key, then post the signature to the
matching `callback`. Top-up callback is **idempotent** — replaying an order does
not double-credit.

- Unlocks spend the **plan allowance first**, then purchased credits.
- Boosts always spend **purchased** credits.
- `plan_limit: 0` means the plan does not meter unlocks (`unmetered: true`) —
  don't show a remaining count in that case.
- Subscribe is **owner only**; team members get `403`.

---

## 4. New fields on responses you already parse

These are additive, so nothing breaks — but the screens won't show the data
until you read them.

**ApplicantResource** (employer app)

| Field | Notes |
| --- | --- |
| `resume` | `null`, or `{ name, uploaded_at, download_url }`. See below. |
| `offer` | `null`, or `{ wage, start_date, message }` — set when hiring |
| `interview` | `null`, or `{ at, at_label, mode, note }` |

`resume.download_url` streams the PDF. It is a **web** URL (no `/api/v1`
prefix) and is authorised per application — only the employer account that
received the application may open it. Open it in a browser/webview with the
session, or download it with the bearer token; do not treat it as a public link.
`404` if the worker has since removed their resume.

**ApplicationResource** (worker app) — gains the same `offer` and `interview`
objects, so the worker can see the offered wage, start date and interview slot.

**EmployerJobResource** — `experience_min`, `views`, `share_url`, and
`boost: { active, tier, until }` for the boost badge and the analytics funnel.

**EmployerProfileResource** — `hiring_as`, `industry`, `company_size`,
`hiring_categories` for the registration wizard.

---

## 5. Behaviour changes with no new endpoint

Both of these are **admin-controlled and off by default**, so you will not see
them until the admin switches them on — but the app must handle them whenever
they are on, because they change an application with **no employer action**.

### AI scoring is always on

Every applicant is scored the moment they apply. `ApplicantResource.ai` carries
it (`null` until scoring finishes, usually a few seconds):

```jsonc
"ai": { "score": 95, "recommendation": "strong_match",
        "summary": "Experienced plumber",
        "matched_skills": ["Plumbing", "Pipe Fitting", "Welding"],
        "red_flags": [] }
```
`recommendation` is `strong_match \| good_match \| maybe \| weak`. The applicant
list defaults to `sort=best_match`; `sort=recent` is the other option.

Because scoring is asynchronous, **an applicant can appear with `ai: null`**.
Show the row without a badge rather than blocking on it, and re-fetch shortly
after.

### Auto-shortlist and auto-reject

When the admin enables them, the backend can move an application on its own:

- **Auto-shortlist** — a high scorer becomes `shortlisted` and the worker is
  notified, with no employer tap.
- **Auto-reject** — a low scorer becomes `rejected` and the worker is notified.

What this means for you:

- **Employer app:** do not assume a `rejected` or `shortlisted` applicant was
  moved by this user. Refresh the list from the server after opening the screen;
  don't rely on locally cached stage counts.
- **Worker app:** an application can go to `rejected` without the employer ever
  opening it. The existing `application.rejected` push and status field already
  cover this — just make sure the applications screen reflects a status change
  that arrives while the app is open.

Auto-reject never touches an application that has been shortlisted, has an
interview booked, or was already decided by the employer.

---

## 6. Integration checklist

- [ ] Chat: thread list, thread view, send, unread badge, FCM `chat.message`
- [ ] Settings: theme + three alert toggles wired to `/preferences`
- [ ] Login & security: device list, sign-out-device
- [ ] **Worker:** resume upload / replace / remove, with the "no text in PDF" error handled
- [ ] **Employer:** applicant `resume` chip + download
- [ ] **Employer:** interview stage in the pipeline (five stages now)
- [ ] **Employer:** offer fields on the hire sheet
- [ ] **Employer:** matched workers + invite (idempotent button state)
- [ ] **Employer:** boost sheet + out-of-credits sheet on `code: "out_of_credits"`
- [ ] **Employer:** Credits & Plans screen, both Razorpay flows
- [ ] Applicant list: AI badge, `ai: null` handled, `sort=best_match` default
- [ ] Both: don't cache stage/status locally — the backend can change them

## 7. Gotchas that have bitten us before

- **OTP is 4 digits**, not 6. The mockups draw six boxes; the backend issues
  four.
- Resume upload is `multipart/form-data`. Don't set `Content-Type` by hand —
  let the HTTP client set the boundary.
- Send `Accept: application/json` on every call, otherwise validation failures
  come back as a redirect instead of a `422`.
- `GET /reference` (public) now carries `interview_modes`, `boost_tiers`,
  `credit_packs`, `industries`, `company_sizes` and `hiring_as` alongside the
  lists you already use. `boost_tiers` and `credit_packs` also come back from
  `/employer/plans`. Read them from the API — they change without an app release.
- Money fields arrive as strings (`"800.00"`). Parse, don't concatenate.

Questions on any shape: the two reference docs above have the full payloads, and
both Postman collections are importable and current.
