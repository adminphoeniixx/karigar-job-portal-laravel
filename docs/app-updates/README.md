# App updates, by date

Each folder is one day's server changes that the mobile apps need. Hand the
developer the folder for the dates since their last build.

- `employer-app.md`: changes for the employer app
- `worker-app.md`: changes for the worker app

A folder only has the file for an app that changed that day. A change for both
apps can get its own file in the folder, linked from both.

| Date | Employer app | Worker app |
|---|---|---|
| [2026-10-12](2026-10-12/) | [Business verification checked automatically (PAN/GST, DigiLocker), instant approval](2026-10-12/employer-app.md) | [KYC needed to apply; DigiLocker Aadhaar, instant approval](2026-10-12/worker-app.md) |
| [2026-10-09](2026-10-09/) | [Applicants' numbers show without an unlock; unlocks are for the Worker Database only](2026-10-09/employer-app.md) · [Hire sheet: send the offer](2026-10-09/hire-offer.md) | [Hire offer card, "You're hired" push](2026-10-09/hire-offer.md) |
| [2026-10-07](2026-10-07/) | [Invoice per payment, invoice email, worker email `null`, app update + maintenance](2026-10-07/employer-app.md) · [Order history + already-purchased plans](2026-10-07/employer-app-orders.md) | [App update + maintenance; Terms & Help endpoints](2026-10-07/worker-app.md) · both apps: [4 APIs](2026-10-07/app-update-maintenance-legal-support-apis.md) |

Older handover notes, from before this folder existed, are the
`employer-app-*.md` and `worker-app-*.md` files in `docs/`.
Full API references: `docs/employer-app-api.md`, `docs/worker-app-api.md`.
