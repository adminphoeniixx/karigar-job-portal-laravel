# Employer app: changes for the next release (Oct 2026)

Everything here goes live with the next server deploy. Base URL `/api/v1`,
Sanctum token on every route. Sections 1–3 only add fields. **Section 4
removes contact credits**: the `credits` field, the top-up and boost endpoints
are gone, so the credits card, top-up and boost screens must go in this
release.

Full API reference: `docs/employer-app-api.md`.

## Summary

| # | Screen | What to build | Priority |
| --- | --- | --- | --- |
| 1 | Post / edit job | "AI help" card with two switches: **AI shortlist** and **AI screening call** | **Must** |
| 2 | Post / edit job | A switch the admin has turned off: show it disabled, with "Switched off by the admin" | **Must** |
| 3 | Post / edit job | Disable the AI call switch while AI shortlist is off on the job | **Must** |
| 4 | Find Workers filter | Hide "Only KYC-verified" when `features.worker_verification_enabled` is `false` | **Must** |
| 5 | Invite to job | Handle the new `422` `worker_unavailable` and show its `message` as a toast | **Must** |
| 6 | Applicant → Call with AI | Show the new blocked reason "This worker is not available for work right now." | **Must** |
| 7 | Home | Replace the "contact credits / Buy" card with the **Worker Database** card (`database`) | **Must** |
| 8 | Plans | "Buy Database" opens the **database plans**; remove the credit top-up and the boost sheet | **Must** |
| 9 | Unlock (applicant / Find Workers) | Read `unlocks` instead of `credits`; `422` code is now `unlock_limit_reached` | **Must** |

---

## 1. AI switches on each job

### What each switch does

| Field | When on | When off |
| --- | --- | --- |
| `ai_shortlist_enabled` | Strong matches are shortlisted automatically and the karigar is notified. Clear mismatches are rejected automatically, if the admin has auto-reject on. | Nothing is decided automatically. The employer shortlists and rejects by hand. |
| `ai_call_enabled` | Every auto-shortlisted karigar gets an AI screening call. | No automatic call. |

These do **not** change:
- Applicants are always AI-scored and sorted best match first, whatever the
  switches say.
- The employer's own "Call with AI" button on an applicant still works.
- The admin decides first. If the admin has a feature off platform-wide, the
  job's switch does nothing (see `ai` below).
- The call only follows an auto-shortlist, so `ai_call_enabled` does nothing
  while `ai_shortlist_enabled` is off.

### Which switches the admin has on

`GET /employer/jobs/form-options` now also returns `ai`:
```json
{
  "category_skills": { "...": "..." },
  "perks": ["..."],
  "shifts": ["day", "night", "rotational", "flexible"],
  "ai": { "shortlist_available": true, "call_available": false }
}
```
- `shortlist_available: false`: show the AI shortlist switch disabled, with
  "Switched off by the admin right now."
- `call_available: false`: same for the AI call switch. It is also `false`
  whenever the shortlist is off platform-wide.
- On the job itself, disable the call switch while the shortlist switch is
  off, with "Needs AI shortlist on."

On a disabled switch, show the employer's stored value without changing it.

### Saving

`POST /employer/jobs` and `PUT /employer/jobs/{job}` take two optional booleans:
```json
{
  "...": "the usual job fields",
  "ai_shortlist_enabled": true,
  "ai_call_enabled": false
}
```
- Left out on a new job: both default to `true`.
- Left out on an edit: the job keeps its current values, so older builds that
  never send them don't switch anything off.
- Not a boolean: `422`.

### Reading

Every `EmployerJobResource` carries both fields. That covers the job list,
`GET /employer/jobs/{job}`, and the create and update responses:
```json
{ "id": 42, "title": "...", "ai_shortlist_enabled": true, "ai_call_enabled": false }
```
A repost copies both switches from the old job.

### Wording (all 8 languages are in the web's `jobForm.ai*` strings)

- Card title: **AI help**
- **AI shortlist**: "AI scores every applicant. Strong matches are shortlisted
  for you and clear mismatches are turned down."
- **AI screening call**: "An AI agent calls each auto-shortlisted karigar to
  check they are still interested and asks a few first questions. You get the
  answers."

---

## 2. Karigar verification can be off (admin switch)

The admin can now switch **karigar** verification off while employers still
verify.

- `GET /employer/dashboard` → `features.worker_verification_enabled` (new
  boolean).
- When it is `false`:
  - Hide the **"Only KYC-verified"** toggle in the Find Workers filter. The
    server ignores `verified=1` in that case, so the list never comes back
    empty because of it.
  - A karigar's `verified` comes back `false` everywhere (worker cards,
    applicants, worker profile). Don't show a verified badge.
- The employer's **own** verification is not affected:
  `features.verification_enabled`, `features.employer_verification_required`
  and `/employer/kyc` behave as before.

```json
"features": {
  "verification_enabled": true,
  "employer_verification_required": true,
  "worker_verification_enabled": false
}
```

---

## 3. Karigar not available for work

A karigar can switch **Available for work** off in their app. While it is off,
nothing from the employer reaches them, and they already don't appear in Find
Workers.

### Invite to job

`POST /employer/jobs/{job}/invite` can now return:
```json
// 422
{ "message": "This karigar is not available for work right now.", "code": "worker_unavailable" }
```
Show the `message` as a toast. Nothing is sent to the karigar.

### Call with AI on an applicant

The AI call can now be blocked with the reason **"This worker is not available
for work right now."** Show it the same way as the other blocked reasons.

### Shortlist, reject, interview, hire, chat

These still work as before from the employer side. The karigar sees the update
in their in-app notifications, but gets no push or email while unavailable.
Nothing new to build.

---

## 4. Contact credits removed: "Buy Database" instead

There is no credit system any more. A karigar's number is unlocked only
through a plan. The employer buys the **Worker Database** as its own plan:
each database plan opens a number of karigar contacts to browse and lets the
employer unlock some of them each month. Example: **Database Basic** opens
1,000 contacts and 50 of them can be unlocked a month — unlocking shows that
karigar's phone number.

| Plan | Price (before GST) | Contacts | Unlocks / month |
| --- | --- | --- | --- |
| Database Basic | ₹299 | 1,000 | 50 |
| Database Standard | ₹599 | 3,000 | 125 |
| Database Pro | ₹999 | 10,000 | 300 |

Admin can change these in Admin → Plans; always render what the API sends.

### Home card

`GET /employer/dashboard` no longer has `credits`. It has `database`, the card
that replaces "0 contact credits / Free plan · unlock karigar numbers / Buy":

```json
"database": {
  "active": false, "plan": null, "plan_type": null, "contacts": 0,
  "unlock_limit": 0, "unlocks_used": 0, "unlocks_remaining": 0, "renews_at": null,
  "title": "Worker Database",
  "subtitle": "Buy a database plan to see karigar numbers",
  "cta": "Buy Database"
}
```

With a plan it reads `"title": "1,000 karigar contacts"`, `"subtitle": "38 of
50 unlocks left · Database Basic"`, `"cta": "View plans"` (`"Upgrade"` when no
unlocks are left). Show `title`, `subtitle` and a button labelled `cta` as
they are. Tapping the card or the button opens the plans screen on the
**database plans** (`GET /employer/plans`, the plans with `type: "database"`).

### Plans screen

- `GET /employer/plans`: `credits`, `credit_packs` and `boost_tiers` are gone;
  `unlocks` and `database` (the same card) are new. Show the database plans
  first when the screen was opened from "Buy Database". Buying works as before
  (`POST /employer/plans/{plan}/subscribe`, then `/employer/plans/callback`).
- Remove the "Just need a few unlocks? +25 credits" top-up row.
- `GET /reference` no longer returns `credit_packs` or `boost_tiers`.

### Removed endpoints (now `404`)

| Endpoint | What to remove in the app |
| --- | --- |
| `POST /employer/credits/top-up` | Credit top-up button |
| `POST /employer/credits/callback` | Top-up payment callback |
| `POST /employer/jobs/{job}/boost` | "Boost job" action and the Boost sheet |

A job's `boost` field stays in the job payload but never turns active again.

### Unlocking a number

`POST /employer/applicants/{application}/unlock` and
`POST /employer/workers/{worker}/unlock` (and `GET /employer/workers`) now
return `unlocks` instead of `credits` (same shape, minus `balance`,
`purchased` and `plan_label`). When nothing is left, the `422` code is
`unlock_limit_reached` (was `out_of_credits`): show the message and offer the
database plans. `no_plan` is unchanged: open the database plans.

---

## Checklist

- [ ] "AI help" card on post / edit job, both switches on by default
- [ ] Switches disabled per `form-options.ai`, with the admin note
- [ ] Call switch disabled while the shortlist switch is off
- [ ] Both values sent on save and read back from the job (also on repost)
- [ ] "Only KYC-verified" filter and verified badge hidden when `features.worker_verification_enabled` is `false`
- [ ] Invite: `422 worker_unavailable` shown as a toast
- [ ] Call with AI: "not available for work" blocked reason shown
- [ ] Home: credits card replaced by the `database` card ("Buy Database")
- [ ] "Buy Database" opens the database plans; top-up row and Boost removed
- [ ] Unlock calls read `unlocks`; `unlock_limit_reached` opens the database plans
