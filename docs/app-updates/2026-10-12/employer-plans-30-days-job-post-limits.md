# Employer: plans run 30 days, and smaller job post limits

Changes of 12 Oct 2026 for the **employer app** and the website.

## What changed

- **Every plan runs for 30 days at a time**, not a calendar month. A plan bought
  on 12 Oct ends on 11 Nov. Job posts and database unlocks reset every 30
  days from purchase. New purchases renew on Razorpay every 30 days.
- **Job posts per 30 days:** **Basic 1**, **Standard 3**. Pro (25) and
  Enterprise (unlimited) are unchanged, and an admin can change any of them in
  Admin → Plans.
- The word "monthly" is gone from the plan screens: they say **"₹499 / 30 days"**
  and **"1 job post every 30 days"**.

## API

`GET /employer/plans`: each plan keeps `interval` (now `"30_days"`) and adds
**`interval_label`** (`"30 days"`). Show `interval_label`, never `interval`:
```json
{ "id": 1, "name": "Basic", "price": 499, "interval": "30_days", "interval_label": "30 days",
  "feature_list": ["1 job post every 30 days", "25 database unlocks every 30 days", "..."] }
```
`job_posts` is as before; `resets_at` is the end of the current 30 days:
```json
"job_posts": { "used": 1, "limit": 1, "unlimited": false, "resets_at": "2026-11-11T…" }
```
Posting a second job on Basic within the 30 days answers `422`,
`code: "post_limit_reached"`. Offer a draft or an upgrade.

Order history and invoices also carry `interval_label`.

## What to build

1. Plan cards and checkout: "₹499 / {interval_label}". Use `feature_list` as is.
2. The job-post counter: "1 of 1 used · resets {resets_at}".

## Good to know

- **Subscriptions bought before this change** keep renewing on their old
  monthly Razorpay plan until they end. Only their counting window is read as
  30 days. Anyone buying from now on gets the 30-day plan.
- **The test employer (9000000001) is on Basic and has already posted this
  period,** so it can only save drafts until its period resets. Give it a
  bigger plan in Admin to keep testing posts.
- No deploy step: the migration already ran on the shared database. The first
  checkout of each plan creates its new 30-day Razorpay plan by itself.
