# Worker: a job leaves the feed once the karigar applies

Changes of 27 Oct 2026 for the **worker app**.

Before, a job the karigar had applied to stayed in the feed and also showed
under My Applications. Now the feed leaves out every job the karigar has
applied to, whatever the application's status (pending, accepted, rejected
or withdrawn). Those jobs are still under My Applications.

## Where it applies

- **`GET /jobs`** opened plain (the karigar's own feed, no search or filter),
  and the same feed sent back by the feed-location endpoints.
- **`GET /worker/dashboard`**: `latest_jobs` ("Latest jobs near you").

A search or filter (`q`, `city`, `state`, `category`, `skill`) is unchanged.
It can still return a job the karigar applied to. Its job page says so in
`meta.application`.

## What the app should do

Nothing has to change. After a successful apply, refresh the feed or drop
the card from the list you hold, so the job does not sit there until the
next refresh.

If the karigar has applied to every job near them, the feed comes back
empty (`data: []`), the same as when there are no jobs.
