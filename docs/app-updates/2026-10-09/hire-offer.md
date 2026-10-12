# Super Karigar: **Hiring a karigar (offer)**, both apps, 9 Oct 2026

What the employer fills in when hiring, and what the karigar gets. Today the
employer app's hire step is a "Send offer letter" button with nothing inside
it, so the karigar only got "Application Accepted". The server can carry a
proper offer, and now sends it to the karigar.

Full reference: `docs/employer-app-api.md` §6 (`PATCH …/status`),
`docs/worker-app-api.md` §5 and §8. Postman: "Hire / reject".

---

## The flow in one look

```
Employer app                         Server                         Worker app
────────────                         ──────                         ──────────
Applicant → [Hire]
  ┌ Hire sheet ─────────────┐
  │ Monthly salary  ₹23,400 │
  │ Joining date  12 Oct    │  PATCH /employer/applicants/{id}/status
  │ Message (optional)      │ ───────────────────────────────────▶ status = accepted
  │ [ Send offer ]          │                                     offer saved
  └─────────────────────────┘                                          │
                                                                       ├─▶ Push:  "You're hired! 🎉"
                                                                       ├─▶ In-app notification (same text)
                                                                       ├─▶ Email "application_accepted"
                                                                       └─▶ GET /worker/applications → offer card
```

---

## A. Employer app: the Hire sheet

Replace the empty "Send offer letter" step with this sheet:

```
┌───────────────────────────────────────────┐
│ Hire atul sachan                           │
│ For: Electrician · Handmade Jewellery      │
│                                            │
│ Monthly salary *                           │
│ ₹ [ 23,400        ] / month                │  ← prefill: job's wage_max, else
│                                            │     the applicant's expected_wage
│ Joining date *                             │
│ [ 12 Oct 2026  📅 ]                         │  ← default: tomorrow; no past dates
│                                            │
│ Message to karigar (optional)              │
│ [ Report to the site by 9 AM with your  ]  │  ← max 1000 characters
│ [ tools. Ask for Ramesh at gate 2.      ]  │
│                                            │
│ The karigar gets this offer by app         │
│ notification and email, with your job      │
│ address and contact number.                │
│                                            │
│ [            Send offer            ]       │
└───────────────────────────────────────────┘
```

| Field | Send as | Rule |
|---|---|---|
| Monthly salary | `offered_wage` (number, rupees per **month**) | Required in the app UI. Server: optional, 0 – 1,00,00,000 |
| Joining date | `start_date` (`YYYY-MM-DD`) | Required in the app UI; don't allow a past date |
| Message | `message` (string) | Optional, max 1000 characters |

The server accepts a hire without these fields, so older builds don't break.
The app should still ask for salary and joining date: without them the karigar
only sees "You're hired" with nothing to act on.

### Request

```http
PATCH /api/v1/employer/applicants/{application}/status
Content-Type: application/json
X-App: employer
```
```json
{
  "status": "accepted",
  "offered_wage": 23400,
  "start_date": "2026-10-12",
  "message": "Report to the site by 9 AM with your tools. Ask for Ramesh at gate 2."
}
```

### Response `200`

```jsonc
{
  "message": "Applicant Accepted.",
  "applicant": {
    "id": 14,
    "status": "accepted",
    "stage": "hired",
    "offer": {
      "wage": "23400.00",
      "start_date": "2026-10-12",
      "message": "Report to the site by 9 AM with your tools. Ask for Ramesh at gate 2."
    },
    "worker": { "name": "atul sachan", "phone": "9876543210", ... }
  }
}
```

`422` comes back with `errors` per field (e.g. `start_date` not a date), the
normal Laravel shape.

### After hiring (employer app)

- Move the card to the **Hired** tab, with the pill "Hired".
- Show an **Offer sent** box on the applicant: salary, joining date and
  message, all from `applicant.offer`.
- Keep the Call / WhatsApp / Chat buttons. The applicant's number is always
  shown now (see `employer-app.md` from today).
- Reject = same endpoint with `{ "status": "rejected" }`. No offer fields.

---

## B. What the karigar gets

### 1. Push notification

| | Text |
|---|---|
| Title | `You're hired! 🎉` |
| Body | `Sri Sai Constructions hired you for "Electrician". ₹23,400 / month · Joining 12 Oct 2026` |
| Data | `{ "type": "application.accepted", "application_id": "14", "url": "/worker/applications" }` |

If the employer left out salary or date, that part is dropped:
`Sri Sai Constructions hired you for "Electrician".` The company name falls
back to the employer's name.

On tap, open **My Applications → this application** (`application_id`).

### 2. In-app notification

`GET /api/v1/notifications` rows now also have `title` and `application_id`:

```json
{
  "id": "9b1c…", "type": "application.accepted",
  "title": "You're hired! 🎉",
  "message": "Sri Sai Constructions hired you for \"Electrician\". ₹23,400 / month · Joining 12 Oct 2026",
  "application_id": 14, "url": "/worker/applications",
  "read": false, "created_ago": "1 minute ago"
}
```

A rejection reads `Application Rejected` / `Your application for "Electrician"
was Rejected.` Older rows have `title: null`. Fall back to the message.

### 3. Worker app: the offer card

`GET /api/v1/worker/applications`: an accepted application carries `offer`:

```jsonc
"offer": {
  "wage": "23400.00",
  "wage_label": "₹23,400 / month",          // null if the employer gave none
  "start_date": "2026-10-12",
  "start_date_label": "12 Oct 2026",        // null if none
  "message": "Report to the site by 9 AM with your tools. Ask for Ramesh at gate 2.",
  "employer_name": "Sri Sai Constructions",
  "address": "12 Anna Salai, Chennai, Tamil Nadu",   // where to report
  "contact_name": "Ramesh",
  "contact_phone": "9000000777"             // whom to call
}
```

- `offer` is `null` unless `status` is `accepted`.
- `contact_phone` is the job's call contact (set on the job form) when the job
  has one, else the employer's own number. The karigar can call the employer
  from the moment they are hired.
- Hide any row whose value is `null`.

Show it at the top of the application, above the tracker:

```
┌───────────────────────────────────────────┐
│ 🎉 You're hired!                            │
│ Sri Sai Constructions · Electrician        │
│                                            │
│ 💰 Salary        ₹23,400 / month            │
│ 📅 Joining       12 Oct 2026                │
│ 📍 Report at     12 Anna Salai, Chennai     │
│ 💬 "Report to the site by 9 AM with your   │
│     tools. Ask for Ramesh at gate 2."      │
│                                            │
│ [ 📞 Call Ramesh ]   [ 💬 Chat ]             │
└───────────────────────────────────────────┘
```

- **Call** dials `contact_phone`.
- **Chat** opens the chat with the employer (as today).
- After the job, the existing **Rate employer** button (`can_review`) stays.

### 4. Email

The worker also gets the `application_accepted` email ("Good news! You were
accepted for …") if they have a real email address. Right now that email does
**not** list the salary, date or message. It only asks them to open the app.
Nothing to build in the app for this.

---

## C. What changed on the server today

| Change | Effect |
|---|---|
| Push and in-app text on hire | "You're hired! 🎉" with the employer, the job, salary and joining date (was "Application Accepted") |
| Worker `offer` object | Shows whenever the application is accepted (before: only when salary or date was given, so a message-only offer was lost). Adds `wage_label`, `start_date_label`, `employer_name`, `address`, `contact_name`, `contact_phone` |
| `GET /notifications` rows | Add `title` and `application_id` |
| Employer side | No change. `PATCH …/status` already took `offered_wage`, `start_date` and `message`. The app just wasn't sending them |

## D. Checklist

**Employer app**
- [ ] Hire sheet with salary (prefilled), joining date and message
- [ ] Send `offered_wage`, `start_date` and `message` with `status: accepted`
- [ ] Show "Offer sent" details from `applicant.offer` on hired applicants

**Worker app**
- [ ] Offer card on an accepted application (`offer`), with Call + Chat
- [ ] Tap on the `application.accepted` push opens that application
- [ ] Notifications list: show `title` when present
