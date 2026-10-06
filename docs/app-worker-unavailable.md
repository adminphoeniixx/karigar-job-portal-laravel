# Both apps: karigar "Available for work" off means left alone

When a karigar switches **Available for work** off on the home screen
(`PATCH /worker/availability` with `{ "available": false }`), the server now
leaves them completely alone until they switch it back on.

| | While available | While **not** available |
| --- | --- | --- |
| New job alerts (push, email, in-app) | yes | **none** |
| Employer invites | yes | **refused** (`422`), nothing reaches the karigar |
| Shortlist / interview / hire / reject / chat | push + email + in-app | **in-app list only**, no push, no email |
| AI screening call | yes | **never rung** |
| Admin broadcast push to all / a city / a category | yes | **skipped** (one sent to this karigar by name still arrives) |
| Job feed and search (`GET /jobs`) | jobs | **empty**, with `unavailable: true` |
| Home `latest_jobs` | 5 jobs | **empty** |
| Shown in employer's Find Workers | yes | no (as before) |

Jobs they already applied to stay reachable: `GET /jobs/{job}`, My
applications and saved jobs work as before.

No server change is needed when they switch it back on. Everything resumes.
Alerts missed meanwhile are not re-sent.

Ships with the next server deploy.

---

## Worker app

| # | Screen | What to build | Priority |
| --- | --- | --- | --- |
| 1 | Jobs tab | When `GET /jobs` returns `"unavailable": true`, hide the list, filters and search. Show the `message` with a button **"I'm available for work"** that calls `PATCH /worker/availability` `{ "available": true }` and then reloads the list. | **Must** |
| 2 | Home | When the profile's `available` is `false`, replace "Latest jobs near you" with the same message and button (not "No jobs available near you yet"). | **Must** |
| 3 | Home toggle | Under the switch, say what off means: "No job alerts or jobs while off". | Nice |

`GET /jobs` while not available:
```json
{
  "data": [],
  "links": { "...": "..." },
  "meta": { "total": 0, "...": "..." },
  "unavailable": true,
  "message": "You are not available for work. Switch it on to see jobs."
}
```

Suggested wording:
- Title: **You're not available for work** (Hindi: "आप अभी काम के लिए उपलब्ध नहीं हैं")
- Body: "While "Available for work" is off you get no job alerts and see no jobs. Switch it on whenever you're ready."
- Button: **I'm available for work** (Hindi: "मैं काम के लिए उपलब्ध हूँ")

All 8 languages are in the web's `jobs.unavailableTitle` / `unavailableBody` /
`unavailableCta` strings.

---

## Employer app

| # | Screen | What to build | Priority |
| --- | --- | --- | --- |
| 1 | Invite to job | `POST /employer/jobs/{job}/invite` can now return `422` with `"code": "worker_unavailable"`. Show the `message` as a toast. | **Must** |
| 2 | Applicant → Call with AI | The call can be blocked with reason "This worker is not available for work right now." Show it like the other blocked reasons. | **Must** |

---

## Checklist

- [ ] Worker: Jobs tab shows the "not available" state with the switch-on button
- [ ] Worker: Home shows the same instead of "No jobs available near you yet"
- [ ] Worker: after switching on, the feed reloads with jobs
- [ ] Employer: invite `422 worker_unavailable` shown as a toast
- [ ] Employer: AI call blocked reason shown
