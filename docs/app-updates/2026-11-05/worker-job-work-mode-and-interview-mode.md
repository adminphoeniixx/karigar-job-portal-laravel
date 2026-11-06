# Super Karigar **Worker app**: "Work from home" jobs, and how the interview is held (5 Nov 2026)

Employers now say on each job **where the work is done** and **how the
interview is held**. Show both to the karigar. Every job posted before today is
on-site with an in-person interview.

Full reference: `docs/worker-app-api.md`, JobResource and `GET /jobs/{job}`.

## Job cards (feed, search, home "Latest jobs", saved jobs)

JobResource has two new fields:

- `work_mode`: `on_site` | `remote` | `both`
- `work_mode_label`: "On-site" | "Work from home" | "On-site or from home"

When `work_mode` is `remote` or `both`, show `work_mode_label` as a chip next
to the location, e.g. "📍 Jaipur, Rajasthan · Work from home". Show nothing
extra for `on_site`, which is the usual case.

## Job detail — `GET /jobs/{job}`

`data` now also has:

```json
"work_mode": "both", "work_mode_label": "On-site or from home",
"interview_mode": "remote", "interview_mode_label": "Interview on phone / video"
```

Show both labels as chips with the shift and experience chips, e.g.
"🏠 On-site or from home" and "🎥 Interview on phone / video"
("🤝 Interview in person" when `interview_mode` is `on_site`).

## The AI screening call

Nothing to build. The karigar may hear new questions on the call: on a
work-from-home job, whether they can work from home and have the space and the
things it needs; on a job with an in-person interview, whether they can come for
it.
