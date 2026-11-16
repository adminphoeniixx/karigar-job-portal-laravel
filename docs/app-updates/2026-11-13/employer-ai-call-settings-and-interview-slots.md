# Employer app: AI call settings, interview slot from the AI call, interview-day reminder

Three changes on 2026-11-13. Two need app work (a settings screen and one more
job option); the third is pushes the app should route.

## 1. AI Call Settings screen (new)

The employer decides when the AI rings their applicants. Account-wide (team
members see and edit the owner's). Until the employer opens the screen,
nothing changes: calls go out on shortlist, at once, inside the platform's
hours, every day, no cap.

### `GET /employer/ai-call-settings` 🔒

```json
{ "settings": { "trigger": "shortlist", "delay_minutes": 0,
                "window_start": null, "window_end": null,
                "days": [1,2,3,4,5,6,7], "who": "all", "min_score": 60,
                "daily_limit": 0, "reminder_call": true },
  "options": {
    "triggers": [ { "value": "shortlist", "label": "After shortlisting", "description": "…" },
                  { "value": "apply", "label": "As soon as they apply", "description": "…" } ],
    "delays":   [ { "value": 0, "label": "Right away" }, { "value": 30, "label": "After 30 minutes" },
                  { "value": 60, "label": "After 1 hour" }, { "value": 240, "label": "After 4 hours" },
                  { "value": 1440, "label": "Next day, when calling hours open" } ],
    "who":      [ { "value": "all", "label": "Everyone", "description": "…" },
                  { "value": "score", "label": "Only strong AI matches", "description": "…" },
                  { "value": "manual", "label": "Only the ones I shortlist myself", "description": "…" } ],
    "days":     [ { "value": 1, "label": "Mon" }, … { "value": 7, "label": "Sun" } ],
    "window":   { "start": "10:00", "end": "19:00", "timezone": "Asia/Kolkata" } },
  "calls_on": true }
```

Build the screen from `options` (labels come from the server, so the words
match the web). `calls_on: false` → show a note that AI calls are switched off
on the platform; the settings still save.

| Field | Meaning |
|---|---|
| `trigger` | `shortlist`: call when the employer or the AI shortlists. `apply`: call every new applicant on applying. |
| `delay_minutes` | Wait this long after the trigger. `1440` = the next day, when the hours open (not 24 h later). |
| `window_start` / `window_end` | `"HH:MM"` IST, or `null` for the platform's hours. Must sit **inside** `options.window` — narrower, never wider. |
| `days` | ISO weekdays that calls may go out on (1 = Mon … 7 = Sun). At least one. A call due on an off day moves to the next on day. |
| `who` | Who gets an **automatic** call. `score`: only applicants whose AI match score ≥ `min_score` (on `trigger: apply` the call waits for the score). `manual`: no automatic calls at all — only a manual shortlist or the Call button rings. |
| `min_score` | 0–100, used with `who: score`. |
| `daily_limit` | Automatic calls per job per day; `0` = no cap. Overflow is booked for the next day. |
| `reminder_call` | AI reminder call on the morning of a booked interview (§3). |

The **Call** button on an applicant always rings now (inside the hours and
days); the delay, `who` and the daily cap are for automatic calls only.

### `PUT /employer/ai-call-settings` 🔒

Body: every field above. Returns the same shape as GET plus `message`. 422
with `errors` for a window outside the platform's hours or an empty `days`.

## 2. Job: interview "either way"

`interview_mode` on `POST/PATCH /employer/jobs` takes a third value, `both`:
the karigar chooses on the AI call between coming in person and a phone/video
call. `GET /employer/jobs/form-options` lists it:

```json
"interview_modes": [ { "value": "on_site", "label": "Interview in person" },
                     { "value": "remote",  "label": "Interview on phone / video" },
                     { "value": "both",    "label": "Interview in person or on phone / video" } ]
```

`job.interview_mode_label` carries the new label. Nothing else changes.

## 3. The AI call now asks when they can interview

The screening call asks the karigar which day and time suits them for the
interview (in person: after "can you come?"; phone/video: for the call; `both`:
after they pick). That lands on the screening call as before —
`proposed_interview_at` + `proposed_mode` (`site | phone | video`) with
`awaiting_confirmation: true` — and the employer confirms it with
`POST /employer/screening-calls/{call}/confirm`. Before today these fields were
always `null`; now they are filled whenever the karigar named a time. The app
already shows and confirms them (§6b of the API reference); no change needed.

## 4. Interview day: reminder + AI reminder call

On the morning of a booked interview (from 07:30 IST):

- Push to the employer, `type: "application.interview_reminder"`, with
  `application_id` and `url: "/employer/jobs/{job}/applicants"` — "Interview
  with Ramesh for "Plumber" today at 4:00 PM." Route it to the applicants
  screen.
- If `reminder_call` is on (default) and AI calls are on with credits, the AI
  rings the karigar at the first callable moment at least 45 minutes before the
  interview, and asks whether they are coming. The employer then gets a push
  `type: "interview.reminder_call"` with `outcome` and `outcome_label`:

| `outcome` | Meaning |
|---|---|
| `will_come` | Confirmed, coming |
| `reschedule` | Cannot make it; asked for another time — `proposed_interview_at` carries it. Reschedule with `POST /employer/applicants/{id}/interview`. |
| `not_coming` | Will not come |
| `callback_later` / `unclear` | Could not talk / no clear answer |
| *(none, `status` no_answer/busy/failed)* | The karigar could not be reached at all; the message suggests the employer call them |

- The same lands on the applicant: `GET /employer/jobs/{job}/applicants` and
  `GET /employer/applicants/{id}` carry `interview_reminder_call` (null until
  the morning of the interview):

```json
"interview_reminder_call": { "id": 3, "status": "completed", "status_label": "Completed",
  "outcome": "reschedule", "outcome_label": "Asked for another time",
  "summary": "Ramesh cannot come today; asked for tomorrow 11 AM.",
  "proposed_interview_at": "2026-11-18T11:00:00+00:00", "proposed_interview_label": "18 Nov 2026, 11:00 AM",
  "scheduled_label": "17 Nov, 10:00 AM", "called_label": "17 Nov, 10:01 AM", "credits_charged": 1 }
```

Show it under the booked interview on the applicant card. Reminder calls take
AI call credits per started minute like any call (ledger line "Interview
reminder call — …"). A missed reminder call is tried once more after 30
minutes while there is still time.
