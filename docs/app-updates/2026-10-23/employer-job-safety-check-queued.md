# Employer: the job safety check now runs in the background

Changes of 23 Oct 2026 for the **employer app**, the website and the admin.
Builds on the [12 Oct safety check](../2026-10-12/employer-job-safety-check.md).

## What changed

Until now the AI read a job **inside** the "Post job" request, and a job that
passed went live with no trace that it had been checked. Now:

1. **Posting returns at once.** A job going live (or a live job whose words
   change) is saved as a **draft marked `checking`**, and the check runs on the
   server's queue. No karigar sees it and nobody is alerted yet.
2. **Within a minute or two the check decides**:
   - **passed**: the job goes live, karigars are alerted, the plan's free post
     is spent, and the employer gets a push: *"Your job is live"*;
   - **held**: same as before. Draft "under review", a push, and now an
     **email** too. Admins get a bell notification and an email.
3. **A pass is on record.** `moderation.status` stays `approved` on the live
   job, with how it was checked and when, so the app can show
   **"AI checked ✓"**.
4. **If the AI is down** the check is retried (after 30 s, then 2 min). Only
   on the last try does the phrase list decide alone; that job shows as
   **basic check** (`method: "phrases"`).
5. **While a job is `checking` it cannot be edited or closed** (409).
6. **Plan limits count a job being checked**, so an employer cannot post past
   the limit (or use the free post twice) while checks are running.

An **edit to a live job's text** (title, description, category, skills,
perks, worker fee) takes the job off the site for the minute the check takes.
When it passes, the job is live again and karigars are **not** alerted a
second time. Changing only the wage, address, dates and so on is not checked
and stays live.

With Admin → Settings → "AI checks new jobs" **off**, jobs go live
immediately as before and `moderation` is `null`.

## API

### `POST /employer/jobs`, `PATCH /employer/jobs/{job}` with `status: "active"`

The usual answer is now **`code: "job_checking"`**:
```json
{ "message": "We are running a quick safety check on your job. It goes live in a minute or two, and we will let you know.",
  "code": "job_checking",
  "job": { "id": 212, "status": "draft",
           "moderation": { "status": "checking", "reasons": [], "note": null,
                           "method": null, "by_admin": false, "checked_at": null } } }
```
`code` can be:

| `code` | Meaning |
|---|---|
| `job_checking` | Check running. Show `message`; the job is not live yet. |
| `job_under_review` | Held (rare at this point now; it usually arrives later by push). |
| `null` | Saved normally: a draft, a change that needs no check, or the check is off. |

### `PATCH /employer/jobs/{job}` and `POST /employer/jobs/{job}/close` while checking

```json
HTTP 409
{ "message": "This job is being checked right now. Please try again in a minute.", "code": "job_checking" }
```

### `moderation` on every job (`EmployerJobResource`)

Now also filled in for jobs that **passed**, not just held ones:
```json
"moderation": {
  "status": "checking | pending | approved | rejected",
  "reasons": ["Asks karigars to pay money first"],   // empty when approved
  "note": "admin's reason when rejected",
  "method": "ai | phrases | null",                   // phrases = AI was down, basic check only; null = not decided / check could not run
  "by_admin": false,                                 // true when an admin approved or rejected it
  "checked_at": "2026-10-23T09:41:12+00:00"
}
```
`null` = never checked (check switched off, or jobs from before 12 Oct).

A job that is `checking` has `status: "draft"`, so it appears under the
**Drafts** tab of `GET /employer/jobs?status=draft`.

### Push / notification `job.moderation`

`state` can now be:

| `state` | Title | When |
|---|---|---|
| `passed` | Your job is live | New job passed the check (**new**) |
| `updated` | Your changes are live | Edit to a live job passed (**new**) |
| `held` | Your job is being reviewed | Held for an admin (now also emailed) |
| `approved` | Your job is live | Admin approved a held job |
| `rejected` | Your job was not approved | Admin rejected it (`message` has the reason; also emailed) |

`data.url` is `/employer/jobs/{id}/applicants` for live states and
`/employer/jobs/{id}/edit` for held / rejected.

## What to build

1. After posting, on `code == "job_checking"`, show `message` (not "Job
   posted") and go to My Jobs.
2. In My Jobs, for `moderation.status`:
   - `checking`: a **"Checking…"** badge with a spinner. Hide Edit and Close.
     Refresh the list every ~4 s while any job is checking (the website
     does), or refresh when the `job.moderation` push arrives.
   - `approved` on a live job: a small **"AI checked ✓"** line; `method ==
     "phrases"` → **"Basic check"** instead.
   - `pending` / `rejected`: as before.
3. Handle 409 `job_checking` on edit / close: show the `message`.
4. Handle the new `passed` and `updated` push states: open the job.

## Server (for whoever deploys)

- New queue **`moderation`** with its own 2 workers (`[program:queue-moderation]`
  in `deployment/octane/FrankenPHP/supervisord.frankenphp.conf`). The image
  picks this up on redeploy. **Without that worker, jobs stay on "Checking…"**.
- `jobs:recheck-stuck` runs every 10 minutes from the scheduler and queues
  again any check stuck for over 15 minutes.
- Migration: `job_listings.moderation_method`.
- 3 new email templates (`job_under_review`, `job_not_approved`,
  `job_review_needed`). Already seeded on the shared database.
