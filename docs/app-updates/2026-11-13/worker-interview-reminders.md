# Worker app: interview-day reminder, and the AI call asks when you can interview

Two changes on 2026-11-13. No new endpoint; one new push type to route.

## 1. Push on the morning of a booked interview

From 07:30 IST on the day of a booked interview the karigar gets a push,
`type: "application.interview_reminder"`, with `application_id` and
`url: "/worker/applications"`. Route it to the application, same as
`application.interview`. The body says the time and, for an in-person
interview, the address: "Interview for "Plumber" today at 4:00 PM at 12, Anna
Nagar 2nd Street, Chennai. Please reach a little early." For a phone interview:
"… the employer will call you. Keep your phone close." An email goes too
(template `interview_reminder`), with the place and who to ask for.

The same comes through `GET /notifications` as:

```json
{ "type": "application.interview_reminder", "application_id": 61, "job_listing_id": 163,
  "job_title": "Plumber for apartment project", "interview_at": "2026-11-17T16:00:00+00:00",
  "interview_mode": "site", "message": "Interview for …", "url": "/worker/applications" }
```

(`interview_at` is the Indian wall-clock time the employer booked, as everywhere else.)

## 2. The AI may ring on the morning too

If the employer keeps it on, the AI rings the karigar before the interview
(from the usual screening number) to ask whether they are coming, and if not,
which day and time would suit instead. It is under a minute. The karigar's
`screening_calls_opted_out` stops this call like every other AI call. The
karigar's answer goes to the employer, who reschedules from their side; the
karigar then gets the usual `application.interview` push for the new time.

## 3. The screening call asks when they could interview

On the AI screening call after applying, the karigar is now asked which day
and time suits them for the interview (and, on a job that offers both, whether
they would rather come in person or take a phone/video call). It is a
preference, not a booking: the employer confirms, and the karigar gets the
`application.interview` push when that happens — the same push as before.
