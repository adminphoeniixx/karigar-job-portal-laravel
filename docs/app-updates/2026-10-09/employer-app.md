# Super Karigar **Employer app**: changes of 9 Oct 2026

Applicants' phone numbers no longer need an unlock. Unlocking is now only for
the **Worker Database** (Find Karigars). Full reference:
`docs/employer-app-api.md` §6, §7, §7b, §13 · Postman:
`docs/karigar-employer-app.postman_collection.json`.

## What changed on the server

- **An applicant's contact always shows.** Every applicant payload
  (`ApplicantResource`) now carries the karigar's `phone` and `email`, with
  nothing to unlock. This covers the job's applicants list, the applicant
  profile, the Shortlisted screen, and `recent_applicants` on the home
  dashboard.
- **`contact_unlocked` is always `true`** on an applicant. It stays in the
  payload only so older builds keep working.
- **Applicants never spend an unlock.** `POST /employer/applicants/{id}/unlock`
  is deprecated. It still answers `200 { "applicant": {...} }`, spends nothing
  and never returns `422`. New builds should not call it.
- **Unlocks belong to the Worker Database only.** The database plan's
  allowance is spent first, then the job plan's share of the database. Plan
  cards now say "**50 database unlocks per month**" (it used to say "contact
  unlocks").
- **A karigar who applied to your job shows unlocked in Find Karigars too.**
  Their number shows there (`contact_unlocked: true`, `can_unlock: false`), and
  `POST /employer/workers/{worker}/unlock` on them is free.
- **Which applicants you see has not changed.** Applicants still arrive in
  batches (20, then 15 more), and only while a job plan is active. Without a
  plan, only shortlisted, interviewed and hired karigars stay visible. A
  karigar you can see now comes with their number. One you can't see yet
  doesn't.

## What the app must change

| # | Change | Where | Priority |
|---|---|---|---|
| 1 | Remove the **"Unlock contact"** button and the **"Contact unlocked"** pill from applicant cards. Always show the **Call / WhatsApp / Chat** buttons. | Home → Recent applicants, Job → Applicants, Applicant profile, Shortlisted | **Must** |
| 2 | Show `worker.phone` straight away on the applicant profile. Don't mask it or show a lock icon. Hide the email row when `worker.email` is `null`. | Applicant profile | **Must** |
| 3 | Stop calling `POST /employer/applicants/{id}/unlock`, and stop checking `contact_unlocked` on applicants. | Applicant screens | **Must** |
| 4 | Remove the "out of unlocks / buy a plan" sheet from the applicant flow. It now belongs only to Find Karigars. | Applicant screens | **Must** |
| 5 | Keep **Unlock** exactly as it is in **Find Karigars / Worker Database** (`can_unlock`, `POST /employer/workers/{worker}/unlock`, `422 no_plan` / `unlock_limit_reached`). | Find Karigars, karigar profile from search | No change |
| 6 | In wording about unlocks, say "database unlocks" instead of "contact unlocks" (counter, plan sheet, banners). | Home database card, plans, My contacts | Should |
| 7 | My contacts → **Applicants** tab now lists every visible applicant, not only "unlocked" ones. Drop the "N from applicants" usage line (`usage.used_applicants` is now `0` for new activity). | `GET /employer/contacts/applicants` | Should |

### 1–3. Applicant card, before and after

Before (screenshot of 9 Oct): the card showed "🔓 Contact unlocked" above
Shortlist / Hire, and a locked applicant showed an "Unlock contact" button.

After:

```
┌──────────────────────────────────────┐
│ (AS) atul sachan ✓          Accepted │
│ Electrical · 3 yrs exp · ★ 0.0       │
│ [Electrical] [Plumbing] [Tiling] …   │
│ [📞 Call] [WhatsApp] [💬 Chat]        │   ← always, no unlock
│ [☆ Shortlist] [   Hire   ] [ ✕ ]     │
└──────────────────────────────────────┘
```

```jsonc
// Any ApplicantResource (list, detail, dashboard recent_applicants, shortlisted)
{
  "id": 14,
  "stage": "hired",
  "contact_unlocked": true,          // always true now: ignore it
  "worker": {
    "id": 88, "name": "atul sachan",
    "phone": "9876543210",           // always sent
    "email": null                    // null when the karigar has no email: hide the row
  }
}
```

Call / WhatsApp should dial `worker.phone` straight away. Don't put an unlock
step before them.

### 3. Old unlock endpoint (deprecated)

```jsonc
// POST /employer/applicants/{application}/unlock: kept for old builds only
// 200, always. Nothing is spent, and it never returns 422.
{ "applicant": { ...ApplicantResource } }
```

### 5. Worker Database: unchanged, one addition

```jsonc
// GET /employer/workers/{worker} for a karigar who applied to your job
{ "worker": { "phone": "9876543210", "contact_unlocked": true, "can_unlock": false, ... } }
```

Karigars who never applied work as before: `phone: null` and `can_unlock: true`
until the employer unlocks them.

### 7. My contacts

`GET /employer/contacts/applicants` lists every applicant the employer can see,
with numbers. `usage.applicants_hidden` only counts applicants hidden because
the job plan ended. Applicants still waiting in a batch are not counted.

## Testing

Use the test employer `9000000001` (OTP `1234`). Open a job with applicants:
every visible applicant shows a number, and the unlock counter on the home
**Worker Database** card does not change. In Find Karigars, unlocking a
karigar who never applied still uses one database unlock.
