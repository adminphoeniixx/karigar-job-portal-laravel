# Super Karigar **Worker app**: "Available Jobs" on home now matches the website

**What was wrong:** the home screen's **Available Jobs** card
(`stats.available_jobs` from `GET /worker/dashboard`) counted **every open
job on the whole portal**: every category, every city, jobs the karigar had
already applied to included. The website's dashboard counts only the jobs
in the karigar's own feed. So the same karigar saw **140 in the app and 72
on the web**.

**What it is now:** `stats.available_jobs` is the size of the karigar's own
feed, the same list `GET /jobs` (no search) and `latest_jobs` come from:

- jobs in the karigar's skills/categories (a karigar with no skills sees every job),
- only jobs taking applications (active, employer hiring),
- **minus jobs the karigar already applied to** (those are under Applied),
- `0` while the karigar is **not available for work** (the feed is empty then too).

Location only changes the order, not the count, so the number is the same
with or without `lat`/`lng`.

Example (test karigar "atul sachan", 9 skills, 14 applications):

| | Before | Now |
|---|---|---|
| App home "Available Jobs" | 140 | **72** |
| Website dashboard "Available Jobs" | 72 | 72 |

**App change needed:** none. The key and type are the same (`stats.available_jobs`,
integer); only the value changed. If the card opens the Jobs tab, the count
now matches the list the karigar lands on.
