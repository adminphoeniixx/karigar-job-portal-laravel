# Employer: AI safety check on every job post

Changes of 12 Oct 2026 for the **employer app**, the website and the admin.

## What it does

Every job **going live**, and every edit to a live job's words, is checked for
being harmful to karigars:

- asking karigars for money (registration/security fee, deposit, "pay first");
- investment / MLM / crypto schemes;
- asking for OTPs, UPI PINs, card details, or for passports;
- adult work, drugs, smuggling;
- child labour (an explicit child worker, or an age under 14);
- fake work-from-home earnings ("earn ₹5000/day from home", like/subscribe tasks);
- discrimination by religion or caste.

Two layers do the checking:

- **A built-in phrase list** (English, Hindi, Hinglish) always runs.
- **The AI model** reads the whole post when it is reachable. If the AI is
  down, only the phrase list decides, so honest jobs are never stuck.

**A job that looks harmful is not published.** It is saved as a **draft "under
review"**:

- nothing of the plan is used (the post, or the free first post);
- no karigar is alerted;
- the employer gets a push / notification saying it is being reviewed;
- an admin sees it under Admin → Job Moderation → "Waiting for review", with
  the reasons, and **Approves** it (it goes live; the employer is told) or
  **Rejects** it with a reason the employer sees.

Ordinary jobs, "Submit Aadhaar at joining", "Kids wear stitching", or a
modest joining fee set in the form, go straight through. Admin → Settings →
**"AI checks new jobs"** switches the check off.

## API

`POST /employer/jobs` / `PATCH /employer/jobs/{job}` with `status: "active"`.
When held, the answer is still `201` / `200`, but:
```json
{ "message": "Your job needs a quick check by our team before it goes live, usually within a working day. Nothing from your plan has been used.",
  "code": "job_under_review",
  "job": { "id": 151, "status": "draft",
           "moderation": { "status": "pending", "reasons": ["Asks karigars to pay money first"], "note": null } } }
```
`code` is `null` when the job went live normally. Every `EmployerJobResource`
now has **`moderation`**: `null`, or `{ status: "pending" | "rejected",
reasons: [...], note: "admin's reason" }`.

Push / notification type **`job.moderation`** with `state`: `held`,
`approved` (the job is live), `rejected` (`message` has the admin's reason).

## What to build

1. After posting, when `code == "job_under_review"`: show the `message`, not
   "Job posted".
2. In My Jobs, a job with `moderation.status`:
   - `pending`: an **Under review** badge;
   - `rejected`: **Not approved** plus `moderation.note`. Editing and posting
     again re-checks it.
3. Handle the `job.moderation` push (open the job).
