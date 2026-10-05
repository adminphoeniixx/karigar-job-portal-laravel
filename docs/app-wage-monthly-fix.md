# Both apps: wages left on "per day"

Every wage on the server is **per month**: what a job pays, what a karigar
expects and what an employer offers. The worker database holds only monthly
figures (lowest expected wage on live is ₹10,400). A few app screens still say
"per day", and they still send day-sized numbers.

The worst case is the employer app's **Find Workers → Filter workers** sheet.
It shows "Expected wage (₹/day)" with "Min 600 / Max 1200". An employer who
types 600 and 1200 gets **no karigars back**: the server compares those numbers
with monthly wages, and nobody expects ₹1,200 a month.

No server change is needed. These are app changes only.

---

## What to change

| # | App | Screen | Now | Change to | Priority |
| --- | --- | --- | --- | --- | --- |
| 1 | Employer | Find Workers → Filter sheet | "Expected wage (₹/day)", hints "Min 600" / "Max 1200" | "Expected wage (₹/month)", hints "Min 15000" / "Max 30000" | **Must** |
| 2 | Employer | Worker cards (Find Workers, applicants, worker profile) | "₹900/day" | `expected_wage` with "/month", e.g. "₹23,400/month" | **Must** |
| 3 | Employer | Hire sheet → Offered wage | "₹ 900 / day" | "₹ … / month", prefill from the karigar's `expected_wage` | **Must** |
| 4 | Employer | Post / edit job → wage "Per" picker | day / hour / month / contract | Remove the picker, label the fields "per month", send `wage_type: "monthly"` | **Must** |
| 5 | Worker | Profile / sign-up → Expected wage | "₹ 800 /day" | "₹ … /month", hint "20000" | **Must** |
| 6 | Worker | My applications cards | "₹900/day" | show the job's `wage_label` as it is | **Must** |
| 7 | Both | Any number shown as a wage | plain "23400" | Indian grouping: "₹23,400" | Nice |

Items 4 to 6 were already in `worker-app-feed-wage-verified.md`. They are
listed again because the latest builds still show "per day".

---

## API details

### Find Workers filter (item 1)

`GET /api/v1/employer/workers?wage_min=15000&wage_max=30000`

- `wage_min` / `wage_max` are **rupees per month**. The server matches them
  against each karigar's monthly `expected_wage`.
- Do not convert anything in the app. Send exactly what the employer typed in
  the monthly fields.
- `wage_max` must be ≥ `wage_min`, or you get `422`.
- `sort=wage_low` sorts by monthly expected wage, lowest first.

### Showing a karigar's wage (item 2)

Every worker row and profile carries:
```json
{ "expected_wage": "23400.00", "wage_type": "monthly" }
```
Show it as `₹23,400/month`. `wage_type` is always `monthly` (or `null` when the
karigar left the wage blank: then show "—").

### Hire sheet (item 3)

`PATCH /api/v1/employer/applicants/{application}/status`
```json
{ "status": "accepted", "offered_wage": 23400, "start_date": "2026-10-10", "message": "Report by 9 AM" }
```
`offered_wage` is **per month**. It comes back as `applicant.offer.wage` (and
to the worker as `application.offer.wage`). Show it with "/month" on both
sides.

### Job wages (items 4 and 6)

`wage_min` / `wage_max` on a job are per month. Show `wage_label` from the job
resource as it is (e.g. "₹20,800 – ₹26,000 / monthly").

### Worker's own expected wage (item 5)

`PUT /api/v1/worker/profile` with `{ "expected_wage": 20000, "wage_type": "monthly" }`.

Older builds that still send `wage_type: "daily"` or `"hourly"` keep working on
job posting and the worker profile: the server multiplies by 26 (or 208) and
stores the monthly amount. **The Find Workers filter and the Hire sheet have no
such conversion**, so those two screens must be fixed in the app.

---

## Checklist

- [ ] Employer: filter sheet says "₹/month", hints 15000 / 30000, sends the typed numbers as `wage_min` / `wage_max`
- [ ] Employer: worker cards and profile show "₹23,400/month"
- [ ] Employer: Hire sheet says "/ month", prefilled from `expected_wage`
- [ ] Employer: post / edit job has no day/hour/contract picker
- [ ] Worker: profile and sign-up ask expected wage per month
- [ ] Worker: application cards show the job's `wage_label`
- [ ] Both: no "/day" or "/hour" text left anywhere

The HTML prototypes in `app-mockup/` (employer-app.html, worker-app.html) are
updated to match.
