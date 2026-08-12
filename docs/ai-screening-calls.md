# AI screening calls

After the AI shortlists an applicant, the platform rings the worker on their
real phone and an agent asks two things: *are you still interested*, and *when
could you come in*. What comes back is a **proposal** — the employer confirms it
before anything lands on the applicant's record.

The employer never sees the worker's number. The platform places the call from
its own virtual number; contact details stay behind the unlock paywall.

## The flow

```
Worker applies
   ↓
ScoreApplication  (existing)  → ai_score
   ↓ score ≥ threshold, auto-shortlist on
Auto-shortlist    (existing)
   ↓ ai_screening_call_enabled on
PlaceScreeningCall job
   ↓ inside calling hours
ScreeningService::start() → VoiceAgent::place()
   ↓ worker picks up, agent talks
Provider POSTs to /api/v1/webhooks/screening-call
   ↓
ScreeningService::apply() → records outcome + proposed slot
   ↓ notifies employer (ScreeningCallCompleted)
Employer confirms in the app
   ↓
ScreeningService::confirm() → sets interview_at, notifies the worker
```

Nothing calls anyone until an admin switches it on. Both flags are off by
default and are separate on purpose: an auto-shortlist is reversible, an
unwanted robocall to a real worker is not.

| Admin → Settings key | What it does |
| --- | --- |
| `ai_auto_shortlist_enabled` | AI may shortlist strong matches |
| `ai_screening_call_enabled` | A shortlist may trigger a call |

A worker can also refuse calls for themselves: `screening_calls_opted_out` on
their profile is a standing "do not call me" that TRAI's rules on automated
voice calls require. It lives on the profile rather than the application, so it
survives them applying to something new, and it blocks the call regardless of
what the admin flags say.

## Setting it up

### 1. LiveKit project, and a number

The stack runs on LiveKit: it supplies the agent runtime, the speech models
(via LiveKit Inference — no separate STT/TTS accounts), the hosting, and the
SIP bridge that reaches a real phone.

What LiveKit does **not** supply is an Indian number. Its own numbers are US
only, so `LIVEKIT_SIP_TRUNK_ID` points at a trunk configured against an Indian
carrier — Exotel, Plivo or Ozonetel — which holds the +91 number and the DLT
registration. Changing carrier is a trunk id, not a code change.

One number for the whole platform, not one per employer. The employer's name
cannot appear on a phone screen; it is spoken in the greeting instead.

**Before the Indian number arrives**, LiveKit's free tier includes one US
number, which is enough to exercise the entire pipeline against your own phone.
Everything except the caller ID behaves exactly as production will.

### 2. Truecaller Business

Register that number with Truecaller Business so it shows as *Super Karigar*
with a verified badge rather than an unknown number. This is the single biggest
lever on pick-up rate in India.

### 3. DLT registration

Automated voice calls to Indian numbers fall under TRAI/DLT rules. Registration
takes weeks — start it before the code is ready, not after. The greeting already
discloses that the call is automated, and `config/screening.php` restricts
dialling to daytime hours, but neither substitutes for registration.

### 4. Environment

```dotenv
SCREENING_PROVIDER=livekit          # 'stub' (default) dials nobody
SCREENING_FROM_NUMBER=+9180xxxxxxx  # the virtual number
SCREENING_BRAND="Super Karigar"
SCREENING_LANGUAGE=hi
SCREENING_WEBHOOK_SECRET=<long random string>

LIVEKIT_URL=wss://your-project.livekit.cloud
LIVEKIT_API_KEY=
LIVEKIT_API_SECRET=
LIVEKIT_SIP_TRUNK_ID=ST_xxxxxxxx    # trunk against the carrier's number
LIVEKIT_AGENT_NAME=screening-agent  # must match the agent service
```

The agent service in `services/screening-agent/` needs the same LiveKit values
plus the webhook URL and secret. It is a separate process — see its README.

Leaving `SCREENING_PROVIDER` unset is safe: the stub agent records everything
and dials nobody, so deploying before the account exists cannot start calling
workers.

### 5. Webhook

Point the provider's result callback at:

```
POST https://superkarigar.com/api/v1/webhooks/screening-call
X-Screening-Signature: <SCREENING_WEBHOOK_SECRET>
```

The endpoint refuses every request when no secret is configured — it is
unauthenticated by necessity, and the secret is the only thing stopping someone
forging transcripts and booking interviews.

Expected body:

```json
{
  "call_id": "the provider's own call id",
  "status": "completed | no_answer | busy | failed",
  "outcome": "interested | not_interested | callback_later | already_placed | unclear",
  "proposed_interview_at": "2026-08-14T11:00:00+05:30",
  "proposed_mode": "phone | video | in_person",
  "summary": "Two sentences for the employer.",
  "transcript": "...",
  "duration_seconds": 74
}
```

A slot in the past is treated as a mis-transcription and dropped. A repeat
callback for a call already finished is ignored, so provider retries are safe.

## Swapping the provider

One file. Implement `App\Services\Screening\VoiceAgent` — `place()` starts the
call and returns an id the webhook can be matched back to, `parseWebhook()` maps
the payload onto `ScreeningResult` — then register it in `AppServiceProvider`:

```php
$this->app->singleton(VoiceAgent::class, fn (): VoiceAgent => match (config('screening.provider')) {
    'livekit' => new LiveKitVoiceAgent,
    default => new StubVoiceAgent,
});
```

`LiveKitVoiceAgent` dispatches our agent into a room with the script as
metadata; the agent then dials the worker itself and waits for them to answer,
so nobody ever picks up to silence. `StubVoiceAgent` remains the default and the
reference implementation.

Nothing else changes: `CallScript`, retries, calling hours, the webhook and the
interview booking are all provider-agnostic.

## What the agent says

`App\Services\Screening\CallScript` builds three things per call:

- **greeting** — names the employer and discloses the call is automated
- **instructions** — the agent's rules for the rest of the conversation
- **extraction schema** — the fields it must return

The language comes from the worker's profile (`spoken_languages`), falling back
to `SCREENING_LANGUAGE`. Hindi, English, Tamil, Telugu, Marathi, Bengali,
Gujarati, Kannada, Malayalam, Punjabi and Odia are recognised.

The instructions forbid the agent from promising the job, quoting a salary,
asking for documents or money, or sharing the employer's contact details. Edit
`CallScript::instructions()` to tune the conversation — that is the file to
change, not the voice.

## Retries and calling hours

- Only a no-answer, busy or failed call is retried — never one the worker took.
- `SCREENING_MAX_ATTEMPTS` (default 3), `SCREENING_RETRY_AFTER` minutes apart.
- Calls only go out between `SCREENING_WINDOW_START` and `..._END` in IST. A
  call that comes due at midnight waits for morning.
- The hold is bounded (`PlaceScreeningCall::MAX_HOLDS`) so a queue driver that
  ignores delays cannot loop.

Holding calls requires a real queue driver. On `sync` the delay is ignored and
the call is dropped rather than dialled at the wrong hour.

## API

| Method | Route | Who |
| --- | --- | --- |
| `GET` | `/api/v1/employer/applicants/{application}/screening-calls` | employer |
| `POST` | `/api/v1/employer/applicants/{application}/screening-calls` | employer |
| `POST` | `/api/v1/employer/screening-calls/{call}/confirm` | employer |
| `POST` | `/api/v1/webhooks/screening-call` | provider |

`confirm` accepts optional `interview_at` and `mode` so the employer can move
the slot the worker suggested rather than accepting it as-is.

## Known gaps

- The agent collects a preferred time; it cannot see the employer's calendar, so
  a human always confirms. Letting the agent book directly would need employers
  to publish availability per job.
- No cost tracking per call.
- Voicemail detection: an answering machine currently gets talked to.
- The agent's extraction pass has never run against a real conversation. Listen
  to the first live calls with the transcript side by side before trusting it.
