# Super Karigar **Employer app**: AI call credits (16 Oct 2026)

**AI screening calls are now paid for with prepaid credits.** The employer
recharges a pack (₹ price + GST → credits, sometimes with bonus credits), and
every AI call takes credits for the minutes it ran. The admin sets the rate and
the packs; by default **1 credit = 1 minute**.

- Charged **per started minute** (2 min 10 s = 3 credits), and **only when the
  worker picked up**. No answer, busy and failed calls are free.
- No credits → no AI calls. Shortlisted applicants are not rung, and the
  employer gets a push to recharge.
- Switching AI calls **on for a job** needs a minimum balance (default 10).
- Every credit added or spent is one line in the **credit history** (ledger),
  with the balance after it. Each recharge gets a GST invoice by email.

Full reference: `docs/employer-app-api.md` **§6c** (new), plus §5 and §6b.
Postman: `docs/karigar-employer-app.postman_collection.json` → **AI Call Credits**.

## Screens to build

### 1. AI Credits screen (new) — `GET /employer/ai-credits`

Put it in the menu next to **AI Interviews**, and link to it from everywhere
below that says "Recharge".

- **Balance card:** `credits.balance` big, "about `credits.minutes` min of
  calling" under it. If `credits.held > 0`: "`held` held by calls on the line
  right now". If `credits.low`: an amber warning.
- **Packs:** one card per `packs[]`: `name`, `total_credits` credits (show
  "`credits` + `bonus_credits` bonus" when there is a bonus), "about `minutes`
  min", `price` and "+ `gst_amount` GST = `price_with_gst`". Highlight
  `popular`. The Buy button is enabled only when `can_buy` is true (team members
  see "Only the account owner can recharge").
- **Rules:** show the `rules[]` lines as they come, under the packs.
- **Credit history:** tabs All / Added / Spent →
  `GET /employer/ai-credits/ledger?filter=…`. Per row: `type_label` chip,
  `created_label`, `description`, `credits` (green +, red −), "Balance
  `balance_after`". Recharge rows have `amount_paid` and an `invoice` to open.
  A `call` row's `screening_call_id` can open the conversation
  (`GET /employer/screening-calls/{id}`).

If `credits.enabled` is `false`, the admin has made AI calls free: show
"AI calls are free right now" and hide the warnings.

### 2. Recharge flow (Razorpay one-time order, not a subscription)

1. `POST /employer/ai-credits/packs/{pack}/checkout` (body `email` only when
   `billing_email` is null, as on plan checkout).
2. Open the Razorpay SDK with `key: razorpay_key`, **`order_id`:
   `razorpay_order_id`**, `amount: amount_paise`, currency INR.
3. On success, `POST /employer/ai-credits/callback` with `razorpay_payment_id`,
   `razorpay_order_id`, `razorpay_signature`.
4. Show `message` ("100 AI call credits added.") and refresh the screen from
   the returned `credits`.

If the app is killed between steps 2 and 3, nothing is lost: the Razorpay
webhook credits the purchase. Just re-fetch the screen on reopen.

### 3. Post / edit job form

`GET /employer/jobs/form-options` → `ai.credits`:

```json
"ai": { "shortlist_available": true, "call_available": true,
        "credits": { "enabled": true, "balance": 4, "min_for_job": 10, "can_enable": false } }
```

- `can_enable: false` → start the **AI screening call** switch **off** and
  disabled, with a line under it: "AI call credits: 4 · needs at least 10 to
  switch AI calls on" and a **Recharge credits** button to the AI Credits screen.
- `can_enable: true` → switch on by default, line "AI call credits: 48".
- Editing a live job whose AI calls were already on: leave the switch as it
  is. The server does not refuse it.

If the employer still sends `ai_call_enabled: true` without the credits, saving
a live job answers:

```json
// 422
{ "message": "AI calling needs at least 10 AI call credits. Recharge credits first, or switch AI calls off for this job.",
  "code": "ai_credits_required",
  "ai_credits": { ...same as GET /employer/ai-credits → credits } }
```

Drafts are always saved. **Older app builds** that do not send
`ai_call_enabled` get the job posted with AI calls **switched off** when credits
are short, not refused.

### 4. Calling an applicant

- New blocker code **`no_credits`** on the applicant's call button
  (`blocked_because`, and `422 code` from `POST …/screening-calls`). Show
  "Not enough AI call credits" with a **Recharge** button.
- The `message` on that 422 is now the readable reason for every code (it used
  to be the same generic sentence each time).
- A booked call whose time comes with no credits ends **Cancelled** with
  `failure_reason: "no_credits"` in AI Interviews.
- Every call (ScreeningCallResource, AI Interviews rows) now has
  **`credits_charged`**: the credits it took, `null` when it was free. Show
  it next to the duration, e.g. "2 min 14 s · 3 credits".

### 5. Push notification (new)

`type: "ai_credits.low"`, data `balance`, `empty` ("1" = not enough for even
one call, calls are on hold), `url: "/employer/ai-credits"`. Open the AI
Credits screen on tap. It is sent once when the balance drops below the admin's
alert level, and at most twice a day while calls are held back.

## Invoices

Recharge invoices are in the same series as plan invoices (KRG/26-27/…), and
they show up in `GET /employer/plans` → `invoices` too. Invoice data has two new
fields: `invoice.kind` (`plan` | `ai_credits`) and `invoice.item` (`title`,
`detail`). Show `item.title` / `item.detail` as the line item instead of
building "<plan> plan — subscription" yourself, because that is wrong for a
recharge. For a recharge, `invoice.credits` is the number of credits bought.
