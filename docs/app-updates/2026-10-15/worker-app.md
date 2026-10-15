# Super Karigar **Worker app**: changes of 15 Oct 2026

## 1. Home "Latest jobs near you" shows nothing: read `latest_jobs` as an array

The home screen says "No jobs available near you yet" while the server is
sending jobs. On 15 Oct, for a karigar in Kanpur, `GET /worker/dashboard`
returned 5 jobs in `latest_jobs` and the app showed none. The same jobs
appear in the **Jobs** tab (`GET /jobs`), so only the home screen's reading
of the response is wrong.

The fault is in our API doc. It showed `latest_jobs` wrapped in `data`:

```json
"latest_jobs": { "data": [ { ...JobResource } ] }
```

The server has always sent a **plain array**:

```json
"latest_jobs": [ { "id": 84, "title": "...", "distance_km": 1.6, ... }, ... ]
```

**Fix in the app:** read `response.latest_jobs` directly as a list of
`JobResource`, not `response.latest_jobs.data`. Each item is the same
`JobResource` as in `GET /jobs`, with `distance_km` included. An empty array
means there really are no jobs (or the karigar is not available for work).
`docs/worker-app-api.md` and the Postman collection's "Dashboard" request are corrected.

Nothing changed on the server for this.

## 2. The search box finds jobs by city and state

`GET /jobs?q=kanpur` used to match only the job's title, description, skills
and category, so a job like "Electrician Helper" in Kanpur did not come up.
`q` now also matches the job's `city` and `state`. The app does not need to
change; the same search returns more jobs.
