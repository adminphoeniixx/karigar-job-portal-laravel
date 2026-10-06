# Both apps: AI switches on each job, and karigar verification on/off

Two new switches:

1. **Employer app:** on every job, the employer can turn the **AI shortlist**
   and the **AI screening call** on or off.
2. **Admin:** can switch **karigar verification** off while employers keep
   verifying. The worker app learns this from a flag it already reads.

Base URL `/api/v1`, Sanctum token on every route. Both changes ship with the
next server deploy.

| # | App | Screen | What to build | Priority |
| --- | --- | --- | --- | --- |
| 1 | Employer | Post / edit job | "AI help" card with two switches: AI shortlist, AI screening call | **Must** |
| 2 | Employer | Post / edit job | A switch the admin has off: disabled, with "Switched off by the admin" | **Must** |
| 3 | Employer | Post / edit job | AI call switch disabled while AI shortlist is off on the job | **Must** |
| 4 | Employer | Find Workers filter | Hide "Only KYC-verified" when `features.worker_verification_enabled` is `false` | **Must** |
| 5 | Worker | Everywhere KYC shows | Already done if the app hides KYC on `features.verification_enabled: false`. Re-test it. | **Check** |

---

## 1. AI switches on a job (employer app)

### What each switch does

| Field | When on | When off |
| --- | --- | --- |
| `ai_shortlist_enabled` | Strong matches are shortlisted automatically (karigar notified). Clear mismatches are rejected automatically, if the admin has auto-reject on. | Nothing is decided automatically. The employer shortlists and rejects by hand. |
| `ai_call_enabled` | Each auto-shortlisted karigar gets an AI screening call. | No automatic call. |

Things that do **not** change:
- Applicants are always AI-scored and sorted best match first, whatever the
  switches say.
- The employer's own "Call with AI" button on an applicant still works.
- The admin decides first. If the admin has a feature off platform-wide, the
  job switch does nothing (see `ai` below).
- The call only ever follows an auto-shortlist, so `ai_call_enabled` does
  nothing while `ai_shortlist_enabled` is off.

### Which switches the admin has on

`GET /employer/jobs/form-options` now also returns:
```json
{
  "category_skills": { ... }, "perks": [ ... ], "shifts": [ ... ],
  "ai": { "shortlist_available": true, "call_available": false }
}
```
- `shortlist_available: false`: show the AI shortlist switch disabled, with
  "Switched off by the admin right now."
- `call_available: false`: same for the AI call switch. It is also `false`
  whenever the shortlist is off platform-wide.
- On the job itself, disable the call switch while the shortlist switch is
  off, with "Needs AI shortlist on."

Keep the employer's stored value even on a disabled switch. Show it, don't
change it.

### Saving

`POST /employer/jobs` and `PUT /employer/jobs/{job}` take two optional booleans:
```json
{ "...": "the usual job fields",
  "ai_shortlist_enabled": true,
  "ai_call_enabled": false }
```
- Left out on a new job: both `true`.
- Left out on an edit: the job keeps what it had. Older builds that never send
  them don't switch anything off.
- Not a boolean: `422`.

### Reading

Every `EmployerJobResource` (job list, `GET /employer/jobs/{job}`, the create
and update responses) carries both:
```json
{ "id": 42, "title": "...", "ai_shortlist_enabled": true, "ai_call_enabled": false, "...": "..." }
```
A repost copies both switches from the old job.

### Wording (all 8 app languages are in the web's `jobForm.ai*` strings)

- Card title: **AI help**
- **AI shortlist**: "AI scores every applicant. Strong matches are shortlisted
  for you and clear mismatches are turned down."
- **AI screening call**: "An AI agent calls each auto-shortlisted karigar to
  check they are still interested and asks a few first questions. You get the
  answers."

---

## 2. Karigar verification on/off (admin)

Admin → Settings has a new switch, **Karigar verification**, under the
existing **KYC verification** master switch.

| KYC verification (master) | Karigar verification | Karigars | Employers |
| --- | --- | --- | --- |
| on | on | verify as before | verify as before |
| on | **off** | no KYC at all | verify as before |
| off | anything | no KYC at all | no KYC at all |

### Worker app

Nothing new to read. The same flag the app already uses now follows the
karigar switch:

- `GET /worker/dashboard` → `features.verification_enabled: false` and
  `stats.kyc_status: null`.
- `GET /kyc` and `POST /kyc` → `404`.

Re-test: with the flag `false`, no KYC tile, menu entry, banner or "Get
verified" prompt may show anywhere.

### Employer app

- A karigar's `verified` comes back `false` everywhere (worker cards,
  applicants, worker profile) while karigar verification is off.
- `GET /employer/dashboard` → `features.worker_verification_enabled` (new).
  When `false`, hide the **"Only KYC-verified"** toggle in the Find Workers
  filter. The server ignores `verified=1` then anyway, so it never empties
  the list.
- The employer's own verification (`features.verification_enabled` on the
  employer dashboard, `/employer/kyc`) is not affected by the karigar switch.

---

## Checklist

- [ ] Employer: "AI help" card on post / edit job with both switches, default on
- [ ] Employer: switches disabled per `form-options.ai`, with the admin note
- [ ] Employer: call switch disabled while shortlist is off
- [ ] Employer: values sent on save, read back from the job
- [ ] Employer: "Only KYC-verified" hidden when `features.worker_verification_enabled` is false
- [ ] Worker: with `features.verification_enabled: false`, no KYC anywhere
