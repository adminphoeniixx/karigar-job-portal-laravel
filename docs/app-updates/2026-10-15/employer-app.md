# Super Karigar **Employer app**: changes of 15 Oct 2026

**Show what the AI said on the call.** After an AI screening call, the employer
app showed nothing of it. On 15 Oct a call to a Kanpur electrician ran for
3 minutes, and the karigar said he has 5 years of experience, can start
tomorrow and wants ₹25,000. All of it was saved on the server, but the
applicant card showed none of it. The server now sends everything the card
and a conversation screen need.

Full reference: `docs/employer-app-api.md` §6 (ApplicantResource) and §6b (AI
screening calls). Postman: `docs/karigar-employer-app.postman_collection.json`
→ "AI Screening Calls" → **One call with its conversation**.

## What changed on the server

| Where | New | What to do with it |
|---|---|---|
| ApplicantResource (applicant list, Shortlisted, single applicant, dashboard `recent_applicants`) | `screening_call`: the newest AI call, or `null` | Show it on the applicant card (below) |
| ApplicantResource `ai` | `recommendation_label`: "Strong match", "Good match", "Maybe", "Weak match" | Show this instead of `recommendation`. The card now prints **"strong_match"**, which is the code |
| ScreeningCallResource (everywhere) | `has_conversation` | Show **View conversation** only when it is `true` |
| ScreeningCallResource with `?with_transcript=1` | `conversation`: the transcript split into chat turns | Use it for the conversation screen, not `transcript` |
| **New** `GET /employer/screening-calls/{call}` | One call, always with `conversation` | Opens the conversation screen from the call id alone |
| AI Interviews list rows | `has_conversation` | Same button there |

```json
"screening_call": {
  "id": 79, "status": "completed", "status_label": "Completed",
  "outcome": "interested", "outcome_label": "Interested",
  "summary": "Atul Sachan answered and is still interested. He has 5 years of experience…",
  "has_conversation": true, "created_ago": "10 minutes ago"
}
```

```json
GET /employer/screening-calls/79
{ "call": { "id": 79, "outcome_label": "Interested", "summary": "...",
    "has_conversation": true,
    "conversation": [
      { "speaker": "agent",   "text": "नमस्ते। मैं Super Karigar से बोल रही हूँ। क्या मेरी बात atul sachan से हो रही है?" },
      { "speaker": "karigar", "text": "हम्म। यस।" },
      { "speaker": "agent",   "text": "Electrician का काम आप कितने साल से कर रहे हैं?" },
      { "speaker": "karigar", "text": "यस। पांच साल से।" }
    ] } }
```

## What to build in the app

1. **Applicant card, under "AI screening calls":** when `screening_call` is
   not `null`, show `outcome_label` as a chip and `summary` as text. While a
   call is still on its way (`outcome` is `null`), show `status_label`
   instead ("Dialing", "Scheduled"…).
2. **View conversation** button on the card when
   `screening_call.has_conversation` is `true`, and on each AI Interviews row
   when `has_conversation` is `true`. It opens a screen that calls
   `GET /employer/screening-calls/{id}` and shows `conversation` as a chat:
   `agent` turns on the left (label "AI assistant"), `karigar` turns on the
   right (the karigar's name). Put `summary` at the top.
3. **AI match chip:** show `ai.recommendation_label` ("AI match 82% · Strong
   match"), not `ai.recommendation`.

The text is mostly Hindi in Devanagari with some English words in it, so use a
font that renders Devanagari.

Nothing is removed: `transcript` and every old field are still there.

The website got the same thing on the same day: a **View conversation**
button on the Applicants page and on AI Interviews.
