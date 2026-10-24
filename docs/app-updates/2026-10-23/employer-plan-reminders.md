# Employer: plan renewal and expiry reminders

Changes of 23 Oct 2026 for the **employer app** and the website.

Until now an employer heard nothing when their plan was about to renew, ran
out, or a renewal payment failed. Their jobs just disappeared from karigars.
Now the server sends a **push, an in-app notification and an email** at
each of these points. Each one is sent once per billing cycle.

| `state` | Title (push) | When |
|---|---|---|
| `renewing` | Your plan renews on 26 Oct 2026 | 3 days before a plan that Razorpay will charge again by itself. The message has the amount, GST included. |
| `ending` | Your plan ends on 26 Oct 2026 | 3 days before a plan that will **not** renew (its 12 cycles are used up, or it has no Razorpay subscription). |
| `payment_failed` | Plan renewal payment failed | Razorpay could not charge the renewal (it tries again for a few days). |
| `ended` | Your plan has ended | The plan ran out without renewing, or Razorpay gave up on the payment. Not sent when the employer already bought another plan of the same kind. |

A job plan's messages say how many live jobs are now hidden from karigars (or
will be). A Worker Database plan's messages say database access has stopped
(or will stop).

## Push / notification `plan.reminder`

```json
{ "type": "plan.reminder",
  "state": "renewing | ending | payment_failed | ended",
  "subscription_id": "41",
  "plan_type": "job | database",
  "url": "/subscription" }            // "/subscription#database" for a database plan
```
FCM `data` values are strings. In `GET /notifications` the same fields come
with `title` and `message`, and `subscription_id` is a number there.

## What to build

1. On a `plan.reminder` tap, open the **Plans** screen (`GET /employer/plans`).
   For `plan_type: "database"`, open it on the database plans.
2. List these notifications like any other in the notifications screen.
   They already carry `title` and `message`.

Nothing else changes in the API. Plan status, `ends_at` and checkout work as
before.

## Server (for whoever deploys)

- Migration: `subscriptions.reminded_for`, `payment_failed_for`,
  `lapse_notified_for`.
- `plans:remind` runs **every hour** from the scheduler, so the container needs
  `WITH_SCHEDULER` on. `php artisan plans:remind --dry-run` lists who would be
  told without sending anything.
- The Razorpay webhook now also acts on **`subscription.pending`**. Tick it
  in Razorpay → Webhooks next to `subscription.halted`, `cancelled` and
  `completed`.
- 4 new email templates (`plan_renewing`, `plan_ending`,
  `plan_payment_failed`, `plan_ended`). Already seeded on the shared
  database, editable in Admin → Email templates.
