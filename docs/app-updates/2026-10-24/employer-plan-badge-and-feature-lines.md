# Employer: plan badge text and feature lines written by the admin

Changes of 24 Oct 2026 for the **employer app**.

Admin → Plans can now write each plan's feature lines and badge text.
Before, the lines were always built from the plan's limits.

## `GET /employer/plans`

- **`feature_list`**: no change to how the app reads it. Show it as it
  comes. When the admin has written lines for a plan, those lines come here
  in place of the built ones ("1 job post, live for 15 days", …).
- **`badge`** (new, `string | null`): the admin's text for the highlight
  badge, for example "Best seller". It is only set when `recommended` is
  `true`. When it is `null`, show your own "Most popular".

```json
{ "id": 7, "name": "1 Month", "recommended": true, "badge": "Best seller",
  "feature_list": ["1 job post in 30 days", "Priority support"] }
```

- **`features.ai_call_credits`** (new, number): how many AI call credits the
  plan adds to the wallet with each payment (the first one and every
  renewal). The `feature_list` already says it ("20 AI calling credits"). The
  credits show in `GET /employer/ai-credits` as a `bonus` entry, "Included
  with your Growth plan". Nothing to build for this unless you want to show
  the number on its own.

## What to build

- On the badge of a `recommended` plan, show `badge ?? "Most popular"`.
- Nothing changes in the worker app.
