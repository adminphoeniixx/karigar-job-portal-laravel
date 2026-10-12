# Employer: AI shortlist from the Worker Database, with auto-scheduled calls

Changes of 12 Oct 2026 for the **employer app** and the website.

## What it does

On a job, **"AI shortlist from database"**:

1. The AI looks through the Worker Database for this job. It considers
   karigars in the job's trade, available for work, same city first, who have
   not applied, been invited or been rejected by this employer.
2. It scores them. Many are ranked quickly, and the best few go to the AI
   model. Only those scoring **60/100 or more** are picked (up to the number
   asked, max 10).
3. Each pick gets an **invite** (push + email, as a normal invite).
4. When a picked karigar **accepts (applies)**, they are **shortlisted
   automatically**. If the employer chose it, an **AI screening call is
   booked**: at the employer's time, or the next calling hour (10 AM–7 PM IST).
   It shows in AI Interviews with source `invite`.

Nobody is rung out of the blue. Only karigars who accepted the invite get a
call, and **no database unlock is spent**. An applicant's number shows anyway.

## API

### `POST /employer/jobs/{job}/ai-shortlist` (10 per hour)
```json
{ "count": 5,                 // 1–10, default 5
  "auto_call": true,          // book an AI call when they accept (default true)
  "call_at": "2026-10-13T11:00",  // optional; IST when no timezone; empty = next calling hour
  "message": "Need weavers from Monday" }   // optional, goes in the invite
```
→ `201`
```json
{ "message": "Invited 3 karigars picked by AI.",
  "picked": [ { "worker_id": 2443, "name": "Hema Behera", "location_label": "Sanganer, Jaipur",
                "score": 84, "summary": "Matches 2 of 2 required skills, same city, 25 years experience." } ] }
```
`200` with `picked: []` and "No good matches in the database for this job
right now." when nobody scores 60+. `422 job_not_hiring` for a job that is not
live. It can take 10–30 s while the AI scores; show a spinner.

## What to build

1. On the job / applicants screen: **AI shortlist from database** → a sheet
   with *How many* (3 / 5 / 10), *Book an AI call when they accept*
   (on by default) and an optional date+time.
2. Show the picked list from the answer (name, area, score, AI summary).
3. Nothing else: accepted karigars appear as shortlisted applicants, and their
   calls appear in AI Interviews.
