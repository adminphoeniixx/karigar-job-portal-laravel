# Super Karigar **Employer app**: an invoice for every payment, and the invoice email

Server changes from 7 Oct 2026. Full reference: `docs/employer-app-api.md` §13 ·
Postman: `docs/karigar-employer-app.postman_collection.json`.

## What changed on the server

- **Every payment gets its own tax invoice.** Before, only the first payment of
  a plan had one; the monthly renewals Razorpay charged had none. Now each
  renewal gets an invoice too, and it is emailed with the PDF.
- **New invoice numbers:** `KRG/26-27/00001`, one series per financial year
  (April–March), with no gaps. Invoices issued earlier keep their old numbers
  (`KRG-2026-00030`).
- **Invoice email needs a real address.** Phone-OTP employers have none, so
  they never got the invoice email. The web checkout now asks for one first.

## What the app must change

| # | Change | Endpoint | Priority |
|---|---|---|---|
| 1 | `{id}` in the invoice URLs is now an **invoice id**, not a subscription id. Take it from `invoices[].id` / use `invoices[].url` as given; never build it from a subscription id. | `GET /employer/invoices/{invoice}` (+ `/pdf`) | **Must** |
| 2 | When `billing_email` on `GET /employer/plans` is `null`, ask "Email for your GST invoice" on the plan sheet and send it as `email` with subscribe. | `POST /employer/plans/{plan}/subscribe` | **Must** |
| 3 | Invoice list: there can be several invoices per plan now. Show "Renewal" when `cycle > 1`. | `GET /employer/plans` → `invoices[]` | Should |
| 4 | Profile: show/edit `email` (it now comes back on the profile and is saved by `PUT /employer/profile`). | `GET/PUT /employer/profile` | Should |

### 1. Invoice ids
```jsonc
// GET /employer/plans → invoices[]
{ "id": 7, "invoice_number": "KRG/26-27/00002", "plan": "Basic",
  "cycle": 2,                     // new: 1 = first payment, 2+ = renewals
  "total": 588.82, "date": "07 Nov 2026",
  "url": "https://…/api/v1/employer/invoices/7",
  "web_url": "https://…/invoices/7" }
```
`GET /employer/invoices/{invoice}` returns the same shape as before, plus
`invoice.cycle`. `payment_ref` is now the Razorpay **payment** id when known.

### 2. Invoice email at checkout
```jsonc
// GET /employer/plans
"billing_email": null            // or "accounts@firm.in"

// POST /employer/plans/{plan}/subscribe   (all optional)
{ "coupon": "FIRST20", "email": "accounts@firm.in" }
```
`email` is saved as the account's email. `422` with an `errors.email` message
when it is invalid or already used by another account. It stays optional on the
API so older app builds keep working, but without it a phone-OTP employer gets
no invoice email (the invoice is still in the app).

## Test checklist
- [ ] Invoice list opens each invoice by its own id (old and new numbers)
- [ ] Plan sheet asks for an email when `billing_email` is null; checkout works with and without it
- [ ] A taken email shows the server's message
- [ ] Renewal invoices show as "Renewal"
- [ ] Profile shows and saves `email`
