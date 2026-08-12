# Screening call agent

The voice on the automated screening call. Laravel decides who to ring and what
to say; this service holds the conversation and posts the result back.

It is a separate process on purpose — LiveKit's agent framework is Python, and
it cannot run inside the Laravel app.

## How a call flows

```
Laravel                          LiveKit                    this service
───────                          ───────                    ────────────
auto-shortlist
  └─ PlaceScreeningCall
       └─ dispatch (script) ───▶ room created ────────────▶ job starts
                                                             │
                                 SIP trunk ◀─────────────────┤ dials worker
                                    │                        │
                              worker answers ───────────────▶ talks
                                                             │
  webhook ◀──────────────────────────────────────────────────┘ posts result
  └─ employer confirms the slot
```

Nothing in here decides policy. Retries, calling hours, who may be called and
what happens to the answer all live in Laravel.

## Running it locally

```bash
cd services/screening-agent
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env      # fill in the LiveKit and webhook values
python agent.py dev
```

`dev` reloads on change. Use `start` in production.

The Laravel side must have `SCREENING_PROVIDER=livekit` and matching
`LIVEKIT_*` values, or it will keep using the stub and dial nobody.

## Testing before an Indian number exists

LiveKit's free tier includes one US number, which is enough to prove the whole
pipeline: point `LIVEKIT_SIP_TRUNK_ID` at it and dial your own phone. The call
arrives as an international `+1`, so it is useless for reaching real karigars —
but every other part (dispatch, script, speech, extraction, webhook, employer
confirmation) is exactly what production will do.

When the Indian number arrives from the carrier, create a trunk against it and
change `LIVEKIT_SIP_TRUNK_ID`. No code changes.

## Choosing the voice

`SCREENING_TTS` decides whether workers stay on the call. The default runs on
LiveKit Inference with no extra account. Before going live, have each candidate
voice read a real greeting in Hindi and listen to it — an accent that sounds
foreign gets hung up on regardless of how good the transcription is.

Sarvam is available as a LiveKit plugin if the Indic voices win that test; it
needs its own API key, and only the `AgentSession` line changes.

## Not done yet

- Voicemail detection. A machine that picks up currently gets talked to.
- Per-call cost reporting.
- The extraction pass is unverified against a real conversation — the first
  live calls should be listened to with the transcript side by side.
