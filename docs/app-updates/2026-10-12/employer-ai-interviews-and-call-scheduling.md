# Employer: AI Interviews list, and booking an AI call for a time

Changes of 12 Oct 2026 for the **employer app** and the website.

## What changed

1. **The AI call button opens a popup:** **Call now**, or **pick a date and
   time**. The AI rings the applicant then. Calls only go out between
   **10 AM and 7 PM IST**; a time outside that moves to the next opening.
2. **A booked call can be cancelled** until it rings.
3. **"AI Interviews"**: one list of every AI screening call across all
   jobs. **Upcoming** (booked, or ringing now), **Done**, **All**. Each row
   shows the candidate, job, time, status, what the AI found out, the slot the
   karigar offered (to confirm), and the interview once confirmed.
4. Calls asked for outside calling hours (including auto-shortlist calls and
   no-answer retries) are now **booked for the morning**. Before, on the
   server's queue they could be dropped.

## API

### Ring now, or book: `POST /employer/applicants/{application}/screening-calls`
```json
{}                                   // ring now (202, as before)
{ "at": "2026-10-13T11:30" }         // book; no timezone = IST. Within 15 days.
```
Booked → `201`:
```json
{ "message": "AI call booked for 13 Oct, 11:30 AM.",
  "calling_at": "2026-10-13T06:00:00+00:00",
  "call": { "id": 91, "status": "scheduled", "scheduled_at": "…", "scheduled_label": "13 Oct 2026, 11:30 AM",
            "source": "manual", "can_cancel": true, … } }
```
`422` codes as before, plus `call_scheduled` (a call is already booked).

### Cancel a booked call: `DELETE /employer/screening-calls/{call}`
→ `{ "message": "AI call cancelled.", "call": { …, "status": "cancelled" } }`.
`422 not_scheduled` once it has rung.

### The list: `GET /employer/ai-interviews?filter=upcoming|done|all&page=`
```json
{ "interviews": { "data": [ {
      "id": 91, "status": "scheduled", "status_label": "Scheduled",
      "source": "manual",                       // manual | auto | retry | invite (AI database pick)
      "attempt": 1,
      "scheduled_label": "13 Oct 2026, 11:30 AM", // when it will ring (IST)
      "called_label": "12 Oct 2026, 5:54 PM",     // when it rang
      "outcome": "interested", "outcome_label": "Interested",
      "summary": "The worker is interested and offered…",
      "failure_reason": null,
      "proposed_interview_label": "14 Oct 2026, 10:00 AM",
      "awaiting_confirmation": true,            // confirm with POST /employer/screening-calls/{id}/confirm
      "can_cancel": true,
      "candidate": { "application_id": 55, "worker_id": 1334, "name": "Ramesh", "phone": "98…", "location_label": "Sanganer, Jaipur" },
      "job": { "id": 143, "title": "Plumber…" },
      "interview": { "at_label": "14 Oct 2026, 10:00 AM", "mode": "site" } | null
  } ], "links": {…}, "meta": {…} },
  "filter": "upcoming",
  "counts": { "upcoming": 1, "done": 66, "all": 67 } }
```
Every screening call in `GET /employer/applicants/{id}/screening-calls` also
carries `scheduled_at`, `scheduled_label`, `source`, `can_cancel`.

## What to build

1. The "Call & schedule" popup: Call now / Pick date and time (note the
   10 AM–7 PM rule). Send `at` for the second.
2. "AI Interviews" in the menu: the three tabs with counts, the rows above,
   **Cancel** on booked rows, and **Confirm** when `awaiting_confirmation`.
3. On an applicant with a booked call: "Booked for 13 Oct, 11:30 AM · Cancel".

## Deploy

The server's **scheduler must run**. `screening:place-due` dials booked calls
every minute. In easypanel the app container needs `WITH_SCHEDULER=true`
(supervisor starts `supercronic`); check `storage/logs/scheduler.log`.
Without it, booked calls never ring.
