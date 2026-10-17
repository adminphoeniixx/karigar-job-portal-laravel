# Super Karigar **Employer app**: AI call recording (16 Oct 2026)

**Every AI screening call is now recorded.** Once the call ends, the employer
can listen to it on the **View conversation** screen, as well as read it.

## What changed in the API

`ScreeningCallResource` has two new fields (everywhere it appears: the applicant
card's `screening_call`, `GET /employer/applicants/{application}/screening-calls`,
`GET /employer/screening-calls/{call}` and the AI Interviews list):

```json
"has_recording": true,
"recording_url": "https://superkarigar.com/api/v1/screening-calls/7/recording?expires=1760000000&signature=…"
```

- `recording_url` is `null` until the recording is in. It arrives a few seconds
  after the call's result. Calls that were not picked up never get one.
- It is a **signed link: no `Authorization` header needed.** Pass it straight to
  the audio player.
- It **expires after 2 hours** (then 403). Don't store it; re-fetch the call when
  the screen opens.
- Format: MP3, mono, both voices mixed, about 1 MB for a 3-minute call. Range
  requests are supported, so seeking works and iOS plays it.

## Screen to build

On **View conversation** (`GET /employer/screening-calls/{call}`), above the
summary and the chat bubbles, when `recording_url` is not null:

- A **"Call recording"** play bar: play/pause, a seek slider, current time /
  length (`duration_seconds` is the talk time, good for the length label
  before the file loads).
- Stop playback when the screen closes.

Full reference: `docs/employer-app-api.md` → **ScreeningCallResource** →
"Call recording".
