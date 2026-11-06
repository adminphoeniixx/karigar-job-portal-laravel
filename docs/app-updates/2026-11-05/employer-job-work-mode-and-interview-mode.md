# Super Karigar **Employer app**: where the work is done, and how the interview is held (5 Nov 2026)

The job form has two new questions, and the AI screening call now asks the
karigar about them.

1. **Where will the karigar work?** `work_mode`
   - `on_site`: comes to the employer's workshop or site
   - `remote`: works from their own home ("Work from home")
   - `both`: either, the karigar chooses
2. **How will you take the interview?** `interview_mode`
   - `on_site`: the karigar comes to the employer
   - `remote`: on a phone or video call

Full reference: `docs/employer-app-api.md` §5 (job body, EmployerJobResource,
form-options). Postman: **Jobs → Post a job** body has both fields.

## Post / edit job form

- Add a **"Work & interview"** card above Location, with the two questions as
  radio cards. Take the options and their labels from
  `GET /employer/jobs/form-options` → `work_modes[]` and `interview_modes[]`
  (`{ value, label }`), e.g.:
  ```json
  "work_modes": [ { "value": "on_site", "label": "On-site" },
                  { "value": "remote", "label": "Work from home" },
                  { "value": "both", "label": "On-site or from home" } ],
  "interview_modes": [ { "value": "on_site", "label": "Interview in person" },
                       { "value": "remote", "label": "Interview on phone / video" } ]
  ```
- Defaults on a new job: `on_site` / `on_site`. When the employer picks
  `remote` work and has not touched the interview question yet, switch the
  interview to `remote` too (the website does the same).
- Send both in `POST /employer/jobs` and `PUT|PATCH /employer/jobs/{job}`:
  ```json
  { ..., "work_mode": "both", "interview_mode": "remote", ... }
  ```
- Under the two questions, when `ai.call_available` is true, show a small note:
  "The AI call asks the karigar about this: whether they can come to the
  location, or whether they have the space and tools to work from home."
- On edit, read `work_mode` / `interview_mode` from the job
  (`GET /employer/jobs/{job}`).

**Older builds keep working:** a job saved without the fields is `on_site`
with an `on_site` interview (a `remote` job without `interview_mode` gets a
`remote` interview), and an edit that leaves them out keeps what the job had.
Every job posted before today is `on_site` / `on_site`.

Validation (422): `work_mode` must be `on_site`, `remote` or `both`;
`interview_mode` must be `on_site` or `remote`.

## Job detail / My Jobs

EmployerJobResource now has `work_mode`, `work_mode_label`, `interview_mode`,
`interview_mode_label`. Show the two labels on the job detail screen, e.g.
"On-site or from home · Interview on phone / video".

## What the AI call now asks

Nothing to build for this. It only helps to know what the employer will read in
the call summary.

| Job | The AI call asks |
|---|---|
| `on_site` | "This work is in Jaipur. Is it OK for you to come there?" (as before) |
| `remote` | "This work is done from home. Can you do it from home?" then "Do you have the space and the things needed for this work at home?" |
| `both` | "This work can be done by coming to Jaipur, or from home. Which would you prefer?" and only if they say home, the space-and-things question |
| interview `on_site` | "If selected, you will have to come to the employer's place for the interview. Can you come?" (no date is fixed; the employer fixes it) |
| interview `remote` | Not asked. In the goodbye, the karigar is told the interview will be on a phone or video call, so no travel |

The answers show up in the call `summary` the employer already sees on the
applicant card and on View conversation.

## Confirm interview

When the employer confirms an AI call's interview without picking a mode, the
interview gets the job's mode: `site` for an `on_site` interview, `phone` for a
`remote` one. (Before, it was always `phone`.) The confirm sheet can start from
the same: preselect `site` or `phone` from the job's `interview_mode`.
