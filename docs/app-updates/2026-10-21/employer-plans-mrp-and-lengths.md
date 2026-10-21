# Employer app: plans with MRP, plan length and job length (2026-10-21)

The job plans now follow apna's prices and shape, and the admin can change any
of it from Admin → Plans. Nothing is removed from `GET /api/v1/employer/plans`;
each plan gets five new fields.

## New job plans

| Plan | Price (before GST) | MRP | Runs for | Job posts | Each job live |
|---|---|---|---|---|---|
| Single Job | ₹699 | — | 30 days | 1 | 15 days |
| 1 Month | ₹1,949 | ₹2,097 | 30 days | 3 | 15 days |
| 3 Months (recommended) | ₹3,649 | ₹4,194 | 90 days | 6 | 15 days |
| 6 Months | ₹7,099 | ₹9,087 | 180 days | 13 | 15 days |

The old Basic / Standard / Pro / Enterprise plans are hidden (`is_active` false).
People who bought them keep them until they end.

## New fields on each item of `plans`

```json
{
  "name": "3 Months",
  "tagline": "Steady hiring through the quarter",
  "price": 3649,
  "mrp": 4194,
  "discount_percent": 13,
  "interval_label": "90 days",
  "duration_days": 90,
  "job_live_days": 15,
  "feature_list": ["6 job posts, each live for 15 days", "175 database unlocks every 90 days", "…"]
}
```

- `tagline`: a short line under the plan name. It can be `null`; show nothing then.
- `mrp` + `discount_percent`: show `mrp` struck out next to the price, with a
  "13% OFF" chip. Both are `null` when the plan has no MRP; show neither.
- `duration_days`: how long the plan runs. `interval_label` already says it
  ("90 days"), so keep showing "₹3,649 / {interval_label}".
- `job_live_days`: how long each job posted on the plan stays live. It is
  `null` on database plans.

## Jobs now end on their own

A job posted on a plan with `job_live_days` ends that many days after it goes
live. The server sets `expires_at` on the job, and the existing expired
handling applies (the job leaves search and the feed, and the employer's
"Expired" filter shows it). A date the employer picks that is earlier stays as
it is. A later date is pulled back to the plan's limit, so the job form's date
picker may come back with an earlier date than the one sent.

Nothing else changes: the checkout, coupons, GST and order history work as before.
