# Super Karigar **Employer app**: order history + "already purchased" plans

7 Oct 2026. **Employer app only.** Base URL `{{base_url}}/api/v1`, Bearer token.
Postman: "Plans & Worker Database → Order history" in the employer collection.

| What | Endpoint | Status |
|---|---|---|
| Order history (every checkout, paid or not, with invoices) | `GET /employer/orders` | **New** |
| Plan list: disable a plan already bought | `GET /employer/plans` | **4 new fields** on each plan |
| Buying the same running plan again | `POST /employer/plans/{plan}/subscribe` | **New `422`** `code: "already_subscribed"` |

The website shows the same thing on Subscription → **Order history**.

---

## 1. `GET /employer/orders` (new)

One **order** = one plan checkout the employer started. The first payment and
every monthly renewal on it are its **payments**, each with its own tax invoice.
Newest order first. Team members see the owner's orders, like the rest of billing.

```json
{ "orders": [
  { "id": 30,
    "order_number": "ORD-00030",
    "plan": "Basic",
    "plan_type": "job",                    // job | database
    "interval": "monthly",
    "ordered_at": "2026-10-06T14:54:00+00:00",
    "ordered_label": "06 Oct 2026, 08:24 PM",   // India time, ready to show
    "amount": 588.82,                      // first payment, after coupon, with GST
    "discount": 0,
    "coupon": null,                        // coupon code, if one was used
    "payment_status": "paid",              // paid | pending | not_completed | renewal_failed
    "payment_label": "Paid",
    "plan_status": "active",               // active | expired | cancelled | completed | none
    "plan_label": "Active",
    "starts_at": "2026-10-06T14:54:00+00:00",
    "ends_at": "2026-11-06T14:54:00+00:00",
    "period_label": "06 Oct 2026 – 06 Nov 2026",
    "total_paid": 588.82,                  // all payments on this order added up
    "payments": [
      { "invoice_id": 41,
        "invoice_number": "KRG/26-27/00001",
        "cycle": 1,                        // 1 = first payment, 2+ = renewals
        "renewal": false,
        "amount": 588.82,
        "paid_at": "2026-10-06T14:54:00+00:00",
        "paid_label": "06 Oct 2026",
        "period_label": "06 Oct 2026 – 06 Nov 2026",
        "invoice_url": "{{base_url}}/api/v1/employer/invoices/41",       // JSON, Bearer token
        "pdf_url": "{{base_url}}/api/v1/employer/invoices/41/pdf",       // PDF download, Bearer token
        "web_url": "https://superkarigar.com/invoices/41",               // web page (needs web login)
        "web_pdf_url": "https://superkarigar.com/invoices/41/pdf" }
    ] },
  { "id": 28, "order_number": "ORD-00028", "plan": "Standard", "plan_type": "job",
    "amount": 1178.82, "payment_status": "not_completed", "payment_label": "Payment not completed",
    "plan_status": "none", "plan_label": "—", "starts_at": null, "ends_at": null,
    "period_label": null, "total_paid": 0, "payments": [] }
] }
```

### What each `payment_status` means and what to show

| `payment_status` | Meaning | Badge | Text under the order |
|---|---|---|---|
| `paid` | At least one payment went through | green "Paid" | the payments list |
| `pending` | Checkout opened in the last 30 minutes, not paid yet | amber "Awaiting payment" | "Waiting for the payment to finish." |
| `not_completed` | Checkout opened 30+ minutes ago and never paid (closed, or the payment failed) | red "Payment not completed" | "No plan started. If money was deducted, Razorpay refunds it in 5–7 days." |
| `renewal_failed` | Was paid, but Razorpay could not take the renewal and put the plan on hold | red "Renewal payment failed" | "Check your card / UPI mandate, or buy a plan again." |

`plan_status` is only meaningful for paid orders (`none` otherwise): `active`
(running now), `expired` (period over), `cancelled` (replaced by a newer plan of
the same type, or cancelled), `completed` (all billing cycles done). Show it as
a second badge next to "Paid".

The `*_label` fields are English, ready to show. Use the status codes if the
app translates them itself.

### Invoice download
Each entry in `payments` is one tax invoice:
- **Download**: `GET pdf_url` with the Bearer token returns the PDF (`application/pdf`). Save it, or open it with the share sheet.
- **View in the app**: `GET invoice_url` returns the invoice as JSON (same as before, see `employer-app-api.md` → `GET /employer/invoices/{invoice}`).
- `web_url` / `web_pdf_url` need a web login, so use them only for "open in browser".

### Suggested screen
Billing → **Order history** list, one card per order:
`Basic · Job plan  [Paid] [Active]`
`ORD-00030 · 06 Oct 2026, 08:24 PM · 06 Oct – 06 Nov 2026            ₹588.82`
then a row per payment: `First payment · 06 Oct 2026 · KRG/26-27/00001 · ₹588.82 · [View] [PDF]`.

---

## 2. `GET /employer/plans`: disable a plan already bought

Each item in `plans` now also has:

```json
{ "id": 2, "name": "Basic", "is_current": true, "purchasable": true,
  "already_purchased": true,      // this exact plan is running now
  "can_purchase": false,          // show the Buy button only when true
  "active_until": "2026-11-06T14:54:00+00:00",
  "purchase_note": "You already have this plan, active till 06 Nov 2026." }
```

- `can_purchase: false` → show the button **disabled**. If `already_purchased`
  is true, label it "Already purchased" and show `purchase_note` (or
  "Active till {active_until}") under it.
- Other plans stay buyable (`can_purchase: true`): an employer on Basic can
  still upgrade to Pro, and a job plan and a database plan can run together.
- `can_purchase` is also false when payments are not configured (`purchasable: false`).

## 3. `POST /employer/plans/{plan}/subscribe`: new 422

Buying the plan that is already running now returns:
```json
422 { "message": "You already have this plan, active till 06 Nov 2026.", "code": "already_subscribed" }
```
Show the message. Old app builds that do not disable the button get this instead
of being charged twice.

---

### Test checklist
- [ ] Order history shows paid orders with every payment and its invoice
- [ ] PDF downloads with the Bearer token and opens
- [ ] An unfinished checkout shows "Payment not completed" (or "Awaiting payment" in its first 30 minutes)
- [ ] The running plan's Buy button is disabled with "Already purchased · Active till …"
- [ ] Other plans can still be bought; `already_subscribed` 422 shows its message
