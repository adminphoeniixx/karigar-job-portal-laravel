# Employer: a cancelled plan runs to the end of the days paid for

Changes of 26 Oct 2026 for the **employer app**.

Before, a plan stopped the moment Razorpay reported it cancelled. That
happens when the employer turns off the UPI AutoPay mandate in their UPI
app, often right after paying. They lost the 30 days they had just paid for.
Now a cancelled plan keeps working until its `ends_at`. It only stops
renewing.

The same goes for a plan whose 12 Razorpay cycles have all been charged
(`completed`). The last cycle runs to its end.

## `GET /employer/plans`

- **`current` / `current_database`** can now have `status: "cancelled"` or
  `"completed"` while the plan is still on. Treat a non-null `current` as
  "has a plan", the way the app does today, whatever its `status` is. When the
  status is `cancelled` or `completed`, you can show "Ends on `ends_at`, will
  not renew" instead of a renewal date.

```json
"current": { "id": 41, "plan": "Basic", "status": "cancelled",
             "starts_at": "2026-10-20T10:00:00+00:00", "ends_at": "2026-11-19T10:00:00+00:00" }
```

- `is_current`, `already_purchased` and `purchase_note` work as before. The
  running plan still cannot be bought a second time before it ends.

## Switching plans

Buying a new plan of the same kind still replaces the old one straight
away. The old one is now stopped at Razorpay right then, not at the end of
its cycle. That includes a plan whose renewal payment failed: Razorpay
retries those for a few days, and a retry that went through would have
charged for both plans.

## Nothing to build

No new fields. Check that a `status` other than `active` does not hide the
current plan.
