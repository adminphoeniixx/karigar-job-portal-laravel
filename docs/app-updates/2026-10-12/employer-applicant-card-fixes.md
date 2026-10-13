# Employer: applicant card fixes (Home → Recent applicants)

Fixes of 12 Oct 2026 for the **employer app**. **No server change.** The API
already sends everything below. These are display bugs in the current build,
seen on Home → Recent applicants and repeated on every applicant card (Job →
Applicants, Shortlisted, applicant profile).

## 1. Remove the "Contact unlocked" pill from applicants (Must)

**Seen:** an applicant card shows a green **"🔓 Contact unlocked"** strip.

**Why it's wrong:** unlocking is **only for the Worker Database** (Find
Karigars). Since 9 Oct an applicant's number always shows with no unlock (see
`2026-10-09/employer-app.md`, point 1). The server still sends
`contact_unlocked: true` on every applicant, but only so that older builds keep
showing the number. The app draws the pill from that flag, so it shows on
**every** applicant.

**Do:**
- On applicant cards and the applicant profile, **don't read
  `contact_unlocked`**, and never show a "Contact unlocked" pill or an "Unlock"
  button.
- Show **Call / WhatsApp / Chat** straight from `worker.phone`.
- Keep the unlock pill and button **only** in Find Karigars / Worker Database
  (`GET /employer/workers`), where `contact_unlocked` and `can_unlock` still
  mean something.

```
Before                                   After
┌──────────────────────────────┐         ┌──────────────────────────────┐
│ atul sachan ✓       Rejected │         │ atul sachan ✓       Rejected │
│ Electrical · 3 yrs · ★ 0.0   │         │ Electrical · 3 yrs · ★ 0.0   │
│ 49.6 km —                    │         │ 49.6 km                      │
│ [skills…]                    │         │ [skills…]                    │
│ 🔓 Contact unlocked          │   →     │ 📞 Call   💬 WhatsApp   Chat │
│ ☆ Shortlist   Hire       ✕  │         │                              │
└──────────────────────────────┘         └──────────────────────────────┘
```

## 2. A rejected applicant still shows Shortlist / Hire / ✕ (Must)

**Seen:** the card says **Rejected**, but still shows **Shortlist**, **Hire**
and the reject **✕**.

**Do:** choose the buttons by `stage`:

| `stage` | Buttons on the card |
|---|---|
| `pending` | Shortlist · Hire · ✕ (reject) |
| `shortlisted`, `interview` | Shortlisted ★ (tap to remove) · Hire · ✕ |
| `hired` | none (show the "Hired" badge) |
| `rejected` | none (show the "Rejected" badge); Call / WhatsApp / Chat stay |

## 3. The "—" after the distance (Should)

**Seen:** `49.6 km —`. A dash with nothing after it.

**Why:** this karigar never filled their area or city, so
`worker.location_label` is `null`. The data is missing; the server is not
wrong.

**Do:** build the line from what is present, and drop the separator when
either side is `null`:

| `worker.distance_label` | `worker.location_label` | Show |
|---|---|---|
| `"1.5 km"` | `"Sanganer, Jaipur"` | `1.5 km · Sanganer, Jaipur` |
| `"49.6 km"` | `null` | `49.6 km` |
| `null` | `"Jaipur"` | `Jaipur` |
| `null` | `null` | hide the line |

When `worker.distance_approx` is `true`, the label already reads `~4 km`
(only the city is known); show it as sent.

## Fields used (from `ApplicantResource`, unchanged)

```json
{ "id": 812, "status": "rejected", "stage": "rejected", "shortlisted": false,
  "contact_unlocked": true,            // ignore on applicants
  "worker": { "name": "atul sachan", "verified": true, "phone": "98…",
              "distance_label": "49.6 km", "distance_approx": false,
              "location_label": null } }
```

The same applies to `recent_applicants` on `GET /employer/dashboard`, the job's
applicants list, Shortlisted, and `GET /employer/applicants/{id}`.
