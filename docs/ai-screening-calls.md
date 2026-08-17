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

**Do not buy the number from LiveKit.** LiveKit Phone Numbers are *inbound
only* — "support for outbound calls is coming soon" — and US only, so one
cannot place a screening call. The dashboard sells them anyway and the outbound
trunk form then asks for a carrier address, which is the giveaway. Whatever
number the trunk dials from has to come from a SIP provider: Twilio, Telnyx,
Plivo, Wavix, or an Indian carrier for production.

**Before the Indian number arrives**, any provider's number is enough to
exercise the whole pipeline against your own phone. Everything except the
caller ID behaves exactly as production will. Check the provider's
international permissions if the test number is not Indian — calling +91 is
usually off by default.

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
  "proposed_mode": "site | phone | video",
  "summary": "Two sentences for the employer.",
  "transcript": "...",
  "duration_seconds": 74
}
```

A slot in the past is treated as a mis-transcription and dropped. A repeat
callback for a call already finished is ignored, so provider retries are safe.

## Testing without a phone line

Every carrier wants money or a KYC review before it will let you dial out, and
that wait says nothing about whether the script works. A rehearsal runs the
real script against your own microphone instead:

```bash
php artisan screening:rehearse <application-id>

cd services/screening-agent
SCREENING_TEST_SCRIPT=rehearsal.json python agent.py console
```

The command builds the script the way a real call would — same greeting, same
language from the worker's profile, same extraction schema — creates a real
`screening_calls` row, and writes the agent's job metadata to a file. Because
the file carries no `dial` block, the agent skips SIP entirely and talks to
whoever is already in the room, which in console mode is you.

Hang up and the result posts to the webhook exactly as a real call's would, so
the outcome and the proposed slot appear on the employer's applicants page.

What this proves: the greeting, the conversation, the language, the extraction,
the webhook, and the employer's confirmation flow. What it cannot prove: that a
phone rings, that a no-answer retries, and how the voice sounds after a mobile
network has squeezed it. Those need a carrier.

## Self-hosting LiveKit

We run on LiveKit **Cloud**. Self-hosting works — the stack is in
`deployment/livekit/` and it has been run end to end, not just written.

**Verified on 2026-08-17.** `docker compose -f
deployment/livekit/docker-compose.livekit.yml up -d redis livekit`, then the
agent worker pointed at `ws://localhost:7880`. The server came up, the worker
registered, a dispatch was accepted, the agent joined the room over **UDP**, and
Sarvam synthesised the Hindi greeting — no 401 anywhere in the speech path.
`livekit/livekit-server` and `livekit/sip` are open source and run fine on our
own VPS next to the app.

**The one thing that does not come with it: LiveKit Inference.** The speech and
LLM gateway is a separate cloud host (`agent-gateway.livekit.cloud`) and it will
not serve a job running on someone else's server — the same credentials that
work from a standalone script come back **401** once the request carries a
self-hosted room and job id.

That used to be the blocker. It is not any more, because we no longer use
Inference: `SCREENING_SPEECH=plugins` calls each vendor directly with our own
key, and the LLM is the DigitalOcean Llama the rest of the app already runs on.
The same setting works on Cloud and self-hosted, so the two deployments differ
only in `LIVEKIT_URL`.

One residual 401 is expected and harmless: LiveKit's **cloud turn detector** is
also gateway-hosted, and the agent logs
`cloud turn detector failed (401); falling back to local mini model`. The local
model ships in the image and does the same job.

**The UDP range must sit below the kernel's ephemeral range**
(`net.ipv4.ip_local_port_range`, 32768-60999 by default). LiveKit's own default
of 50000-60000 sits *inside* it, so an unrelated outgoing socket can already
hold a port when the server starts and the bind fails with "address already in
use" — intermittently, which is the worst way to find out. `livekit.yaml` uses
20000-20200: below the ephemeral range, and narrow enough that a VPS firewall
will actually let you open it.

**So the trade is:** save the LiveKit Cloud bill, take on a UDP-capable host,
TLS on the signalling port, and SIP ports the carrier can reach. No extra vendor
accounts — we already bring our own speech and LLM keys either way. At today's
volume the free Cloud tier covers five concurrent calls, so this is still a
"when we scale" move rather than an urgent one — but it is now a config change,
not a project.

The phone number is unaffected either way — it comes from an Indian carrier, not
from LiveKit.

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

The web app has the same two actions on the applicants screen:

| Method | Route |
| --- | --- |
| `POST` | `/employer/applications/{application}/screening-call` |
| `POST` | `/employer/screening-calls/{call}/confirm` |

Each applicant card carries a `screening` block — the latest call, its outcome
and summary, and whether another call is allowed. It is `null` when no provider
is configured, so the button never appears on an install that cannot dial.

`proposed_mode` is normalised onto the app's own vocabulary (`site`, `phone`,
`video`) before it is stored — a model told to answer "site" still says
"in_person" now and then, and that string would reach the applicant's record.

## Known gaps

- The agent collects a preferred time; it cannot see the employer's calendar, so
  a human always confirms. Letting the agent book directly would need employers
  to publish availability per job.
- No cost tracking per call.
- Voicemail detection: an answering machine currently gets talked to.
- The agent's extraction pass has never run against a real conversation. Listen
  to the first live calls with the transcript side by side before trusting it.
