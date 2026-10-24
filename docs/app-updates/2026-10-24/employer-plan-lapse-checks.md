# Employer: what stops when a job plan ends

Changes of 24 Oct 2026 for the **employer app** and the website.

When an employer's job plan runs out, their live jobs are paused. Karigars
stop seeing them in search, the feed, saved jobs and on the job page, and
they can't apply. That part already worked. Three gaps let a lapsed
employer act as if the job were still live. They are closed now.

## 1. Posting or reposting after the plan ended → 422 `subscription_required`

An employer who bought a plan straight away never used their free first
post. Once that plan ended, the server used to hand them the free post. The
job said "posted", karigars got a push and an email, and nobody could open
it, because the account's jobs are paused.

Now `POST /employer/jobs` (going live), `PUT /employer/jobs/{job}` (draft →
live) and repost return the same error as an account with no plan:

```json
{ "message": "Your plan has ended. Renew it to post jobs.", "code": "subscription_required" }
```

Saving a draft still works.

## 2. Inviting a karigar to a paused job → 422 `job_not_hiring`

`POST /employer/jobs/{job}/invite` now refuses when the job is not taking
applications (the plan lapsed, or the job is closed or expired). Before, the
karigar got the invite push and email, and the job page then said "not found".

```json
{ "message": "This job is not taking applications, so nobody can be invited to it.", "code": "job_not_hiring" }
```

This is the same code that AI shortlist (`POST /employer/jobs/{job}/ai-shortlist`)
already returns.

## 3. AI screening calls stop with the plan

A call that was booked, or a retry, is cancelled once the plan ends. The
karigar is no longer called about a job they can't take. The call shows
`status: "cancelled"`, `failure_reason: "job_not_hiring"`. Its label is
"Your plan has ended, so this job is paused. Renew it to call applicants."

## What to build

- On `subscription_required` from posting or repost, show the message and a
  **Renew plan** button that opens Plans (`GET /employer/plans`). This is
  the same handling as an account with no plan.
- On `job_not_hiring` from invite, show the message. You can also hide the
  Invite button when `hiring_paused` is `true` on `GET /employer/jobs`.
- Nothing changes in the worker app.
