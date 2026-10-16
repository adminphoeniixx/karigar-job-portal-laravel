# Super Karigar **Employer app**: changes of 16 Oct 2026

**Shortlisting by hand now starts the AI call too.** Until now the AI screening
call only rang applicants the AI had shortlisted on its own. When the employer
tapped **Shortlist**, the worker was notified but nobody rang them. Now a hand
shortlist rings the worker as well. It follows the same switches as the auto
call: the admin's "AI screening call" setting and the job's `ai_call_enabled`.

- **Inside calling hours (10:00–19:00 IST):** the AI rings the worker at once.
- **Outside them:** the call is booked for 10:00 the next morning and shows in
  AI Interviews as an upcoming call.
- **No call** when calls are off, the job's AI call is off, or the worker can't
  be rung (opted out, not available, no number, already screened, call already
  booked). The shortlist itself still goes through.

## What changed on the server

| Where | Change | What to do with it |
|---|---|---|
| `POST /employer/applicants/{application}/shortlist` | `message` says what happened to the call (below) | Show `message` in the toast as it comes. Don't hard-code your own text |
| Same response, `applicant` | Now always has `screening_call` (the new call, or `null`) | Refresh the card from it, so it shows "Dialing" or "Scheduled" right away |
| ScreeningCallResource / AI Interviews `source` | New value `shortlist` | Label it **"After your shortlist"**. Values are now `manual`, `auto`, `retry`, `invite`, `shortlist` |

Un-shortlisting is unchanged, and it doesn't cancel a call that is already
booked. To cancel one, the employer uses the call's cancel action
(`DELETE /employer/screening-calls/{call}`) as before.

### `message` values

| Case | `message` |
|---|---|
| Call is dialling now | `Applicant shortlisted — the AI is calling them now.` |
| Call booked for later | `Applicant shortlisted — AI call booked for 17 Oct, 10:00 AM.` |
| No call | `Applicant shortlisted — the worker has been notified.` |

```json
{
  "message": "Applicant shortlisted — AI call booked for 17 Oct, 10:00 AM.",
  "applicant": {
    "id": 412,
    "screening_call": {
      "id": 88, "status": "scheduled", "status_label": "Scheduled",
      "outcome": null, "summary": null, "has_conversation": false,
      "created_ago": "1 second ago"
    }
  }
}
```
