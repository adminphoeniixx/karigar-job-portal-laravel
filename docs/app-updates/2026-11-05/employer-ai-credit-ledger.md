# Super Karigar **Employer app**: AI credit ledger, where it shows and what each line means (5 Nov 2026)

**No server change.** This note explains the AI call credit ledger (the
"Credit history") that came with AI call credits on 16 Oct
([employer-ai-call-credits.md](../2026-10-16/employer-ai-call-credits.md)): where the
employer sees it, which lines **add** credits, which lines **take** them, and
what each field on a line means. Full API: `docs/employer-app-api.md` **§6c**.

## The rule in one line

Every credit that is added or taken is **one ledger line**, and lines are never
edited or deleted. `credits` is signed (`+90`, `-3`), and `balance_after` is the
balance right after that line. The newest line's `balance_after` is the current
balance.

## Where the employer sees it

| Where | What it shows |
|---|---|
| **App → AI Credits screen** (menu, next to AI Interviews) | Balance card, recharge packs, then **Credit history** with tabs All / Added / Spent. Data: `GET /employer/ai-credits` (balance + totals) and `GET /employer/ai-credits/ledger?filter=all\|added\|spent&page=&per_page=` (lines) |
| **Website → sidebar "AI Credits"** (`/employer/ai-credits`) | The same screen: 4 tiles (Balance, Added, Spent, Calls), Recharge, **Credit history** with the same three tabs |
| **App → AI Interviews / View conversation** | No ledger here, but each call carries **`credits_charged`** (the website does not show it yet), the credits that call took (`null` = free). It is the same number as that call's ledger line |
| **Admin → AI Credits** (`/admin/ai-credits`) | Super Karigar's own view: **every employer's** ledger in one table, searchable by employer/phone/email and filterable by type. Admin also adds or takes credits by hand here |

Team members see the balance and history; only the account owner can recharge
(`can_buy`).

## Which line adds and which takes

### Adds credits (`direction: "credit"`, green **+**, shows under **Added**)

| `type` | `type_label` | When it is written | `description` example |
|---|---|---|---|
| `recharge` | Recharge | The employer pays for a pack (Razorpay callback or webhook, written once) | `Recharge — Growth pack` |
| `bonus` | Bonus | Right after a recharge, when the pack has bonus credits: a **separate line** so the gift is visible | `Bonus with the Growth pack` |
| `bonus` | Bonus | Each paid cycle of a plan that includes AI credits (first payment and every renewal) | `Included with your Standard plan` |
| `welcome` | Welcome credits | Once per account, the first time the credits screen is opened, **only if** the admin set welcome credits (default 0 = never) | `Welcome credits to try AI calls` |
| `admin_credit` | Added by Super Karigar | Admin added credits by hand | The reason the admin typed, e.g. `Refund for the dropped call on 21 Oct` |

### Takes credits (`direction: "debit"`, red **−**, shows under **Spent**)

| `type` | `type_label` | When it is written | `description` example |
|---|---|---|---|
| `call` | AI call | An AI screening call **ends with the karigar having talked** (status `completed`) | `AI call — Ramesh Kumar · Tailor for boutique · 2 min 14 s` |
| `admin_debit` | Deducted by Super Karigar | Admin took credits by hand | The reason the admin typed |

`refund` (label "Refund") exists in the type list but **nothing writes it
today**. A refund is done by hand as `admin_credit`. Handle the type anyway
(green +) so a future server change doesn't break the screen.

## How much a call takes

- **Per started minute** × the admin's rate (default **1 credit = 1 minute**):
  48 s = 1 credit, 2 min 14 s = 3 credits. The rate is in `credits.per_minute`.
- Only the **talk time** is charged (the agent reports it), not the ringing.
- **Free, no ledger line at all:** not picked up, busy, failed, cancelled, a
  call that ran 0 seconds, and every call while the admin has credits turned
  off (`credits.enabled: false`).
- One call is charged **once**: its line carries `screening_call_id`, and a
  repeated result callback adds nothing.

## Held credits are not a ledger line

While a call is on the line (queued, dialing, in progress) it **holds** enough
credits for its longest possible run: 6 credits by default (5 min limit + 1 min
goodbye). A hold is **not** written to the ledger and does not change
`balance`. It only shows in the summary:

- `credits.balance`: what the ledger adds up to
- `credits.held`: held by calls on the line right now
- `credits.available` = balance − held: what new calls can use

When the call ends, the hold goes away and the real `call` line (if any) is
written. So the balance can drop by 3 while `held` showed 6: that is right.
A call that lost its result callback stops holding after 30 minutes.

## One line, field by field

From `GET /employer/ai-credits/ledger` → `data[]` (real lines from the test
employer 9000000001, newest first):

```json
{
  "id": 9,
  "type": "call",
  "type_label": "AI call",
  "direction": "debit",
  "credits": -3,
  "balance_after": 196,
  "description": "AI call — Test Worker (Test) · QA TEST - Tailor for boutique (delete me) · 2 min 14 s",
  "seconds": 134,
  "screening_call_id": 84,
  "amount_paid": null,
  "invoice": null,
  "created_at": "2026-10-22T04:49:40+00:00",
  "created_label": "22 Oct 2026, 10:19 AM"
}
```

```json
{
  "id": 3,
  "type": "recharge",
  "type_label": "Recharge",
  "direction": "credit",
  "credits": 90,
  "balance_after": 190,
  "description": "Recharge — Growth pack",
  "seconds": null,
  "screening_call_id": null,
  "amount_paid": 1178.82,
  "invoice": {
    "id": 9, "number": "KRG/26-27/00005",
    "url": ".../api/v1/employer/invoices/9", "pdf_url": ".../api/v1/employer/invoices/9/pdf",
    "web_url": ".../invoices/9", "web_pdf_url": ".../invoices/9/pdf"
  },
  "created_at": "2026-10-17T04:17:50+00:00",
  "created_label": "17 Oct 2026, 9:47 AM"
}
```

| Field | Meaning / how to show it |
|---|---|
| `type` | Machine value from the tables above. Use it for the icon, not for the text |
| `type_label` | Chip text, already worded for the employer |
| `direction` | `credit` (adds) or `debit` (takes). Green / red |
| `credits` | Signed. Show `+90` / `−3` |
| `balance_after` | "Balance 196" under the amount |
| `description` | Main line, show as it comes. For a call: karigar · job · talk time |
| `seconds` | Call lines only: talk time in seconds (`null` otherwise) |
| `screening_call_id` | Call lines only: tap the line → `GET /employer/screening-calls/{id}` (View conversation, recording, transcript) |
| `amount_paid` | `recharge` lines only: ₹ paid incl. GST, "Paid ₹1,178.82" |
| `invoice` | `recharge` lines only: tap → `invoice.url` (JSON) or `invoice.pdf_url` (PDF). A plan's "Included with your plan" bonus has no invoice of its own; the plan invoice is in Plans → invoices |
| `created_label` | Time in IST, show as it comes. `created_at` is UTC |

Paging: `meta.current_page`, `meta.last_page`, `meta.per_page` (5–50, default 20),
`meta.total`. The response also has `balance`, so a pull to refresh on the
history alone keeps the balance right.

## Tabs and totals

- **All**: every line. **Added**: `credits > 0`. **Spent**: `credits < 0`.
- `GET /employer/ai-credits` → `totals`: `added` (sum of all + lines),
  `spent` (sum of all − lines, as a positive number), `calls` (number of
  charged call lines), `recharged_amount` (₹ paid for recharges incl. GST).
  On the test employer today: added 200, spent 4 over 2 calls, balance 196.
- Plan bonus credits count in `added` but **not** in `recharged_amount`, because
  the plan payment is a plan, not a recharge.

## Checklist for the app

1. Credit history on the AI Credits screen with All / Added / Spent tabs and
   paging.
2. Green + / red − by `direction`. Show `type_label`, `description`,
   `created_label`, "Balance `balance_after`".
3. Recharge line → "Paid ₹…" + open invoice. Call line → open the conversation.
4. Show `credits_charged` next to the duration on AI Interviews and View
   conversation ("2 min 14 s · 3 credits"; nothing when `null`).
5. Balance card: `held > 0` → "`held` held by calls on the line".
   `enabled: false` → "AI calls are free right now", no warnings.
6. Unknown `type` → still show it, using `type_label` and `direction`.
