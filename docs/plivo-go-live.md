# Going live on the Plivo line — runbook

**The number is +91 80 3170 3250** (`+918031703250`), a Bengaluru DID on our
Plivo account. Everything except the carrier leg is already configured and has
been run end to end: the self-hosted LiveKit stack, the agent, Inworld speech
with the cloned Hindi voice, the Sarvam LLM, the webhook, and the employer's
confirmation screen. What is missing is a trunk.

This is the whole of what is left, in order. Background and the *why* behind
each choice are in [ai-screening-calls.md](ai-screening-calls.md); this file is
just the sequence.

## 0. What has to be in hand first

Four values from the Plivo console. Nothing here can start without them:

| Value | Where it comes from |
| --- | --- |
| SIP username | Voice → Zentrunk → Credentials |
| SIP password | shown once, when the credential is created |
| Termination SIP domain | Voice → Zentrunk → Trunks → the outbound trunk (`xxxxx.zt.plivo.com`) |
| Secure Trunking on or off | the same trunk page |

Plivo's own rules on the credential: username 5–20 alphanumeric characters,
password 5–20 with at least one of `~!@#$%^&*()_+`.

We do **not** need the Plivo Auth ID or Auth Token. Nothing in this codebase
calls Plivo's REST API — LiveKit dials the trunk over SIP and that is the only
integration point.

## 1. Bring up the SIP bridge

The stack has been running without it: with no trunk there was nothing for it
to dial, so `sip` was left down on purpose.

```bash
docker compose -f deployment/livekit/docker-compose.livekit.yml up -d sip
docker compose -f deployment/livekit/docker-compose.livekit.yml ps
docker compose -f deployment/livekit/docker-compose.livekit.yml logs -f sip
```

It should reach Redis and LiveKit and then sit idle. A restart loop here is
almost always `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` missing from the compose
environment, not anything to do with Plivo.

## 2. Open the audio ports

**10000–10200/udp** has to be open on the VPS firewall. This is the one that
bites: signalling succeeds, the call connects, and then nobody can hear
anything — because RTP is UDP and an HTTP proxy will not forward it.

The stack runs on `network_mode: host` now, so docker no longer opens anything
on its own — it used to write its own iptables chain and quietly bypass ufw.
Every port the stack listens on has to be allowed explicitly:

```bash
sudo ufw allow 7880/tcp           # LiveKit signalling — TLS in front of it
sudo ufw allow 7881/tcp           # RTC over TCP, the fallback when UDP is blocked
sudo ufw allow 20000:20200/udp    # LiveKit media
sudo ufw allow 10000:10200/udp    # SIP media, to and from Plivo
sudo ufw status numbered          # confirm before placing a call
```

5060 stays closed: we only dial out. Open it the day we accept inbound calls.

The range used to be 10000–20000, and that is worth knowing about because it
did not merely waste ports: docker published each one by spawning a userland
proxy process, so `up sip` sat there spawning ten thousand of them and never
started the container — on the machine it was tried on it wedged the daemon's
container-creation path until it was restarted. Host networking removes those
processes entirely, and that is also why the voice stopped breaking up; the
narrow range stays anyway, since 200 ports is 100 concurrent calls.

On easypanel the provider's own firewall is separate from ufw and has to be
opened there too.

`5060` is for inbound calls, which we do not take. Leaving it closed is fine.

## 3. Point the LiveKit CLI at our own server

`lk` talks to whatever server the environment names, and the default is
LiveKit Cloud. A trunk created on Cloud is a trunk on a project that never
places our calls — and it fails silently, because the create succeeds.

```bash
export LIVEKIT_URL=ws://localhost:7880      # our server, NOT livekit.cloud
export LIVEKIT_API_KEY=...                  # same pair the stack runs on
export LIVEKIT_API_SECRET=...
```

Install: <https://docs.livekit.io/reference/developer-tools/livekit-cli/#setup>

## 4. Create the trunk

Everything the trunk needs is in `.env` — `PLIVO_SIP_USERNAME`,
`PLIVO_SIP_PASSWORD`, `PLIVO_TERMINATION_DOMAIN`, `PLIVO_SIP_TRANSPORT` and
`SCREENING_FROM_NUMBER`. The script builds the trunk definition from them:

```bash
./deployment/livekit/create-plivo-trunk.sh
# → SIPTrunkID: ST_xxxxxxxx
```

Reading the password from `.env` rather than typing it after `--auth-pass`
keeps it out of the shell history and out of the process list, and it means no
file in the repo carries it. The script also refuses to run if `LIVEKIT_URL`
points at LiveKit Cloud, because that mistake *succeeds* — a trunk is created,
on a project that never places our calls.

If Plivo's **Secure Trunking** is off, set `PLIVO_SIP_TRANSPORT=SIP_TRANSPORT_TCP`.
A TLS transport against a trunk that is not doing TLS fails at connect time
with nothing useful in the log.

Keep that id. Confirm it landed on the right server:

```bash
lk sip outbound list
```

## 5. Set the environment

Three keys in the live app's environment (easypanel → the app service), then
redeploy:

```dotenv
SCREENING_FROM_NUMBER=+918031703250
LIVEKIT_SIP_TRUNK_ID=ST_xxxxxxxx
SCREENING_PROVIDER=livekit
```

`SCREENING_PROVIDER` is the switch that matters. While it is `stub` the
feature records everything and dials nobody; the moment it is `livekit`, a
shortlist can put a call through to a real worker's phone. Leave it on `stub`
until step 6 has passed.

The agent service needs nothing changed — it is already on Inworld speech, the
cloned voice and the Sarvam LLM.

**The queue has to be running.** `PlaceScreeningCall` is queued, so with no
worker the call is simply never placed and the UI shows no error at all.

## 6. First real call — to your own phone

Do this before any worker's number is reachable.

1. Set the test worker's **profile** phone to your own number — not their
   login phone, which is their identity for OTP. `ScreeningService::phoneFor()`
   prefers `worker_profiles.phone` and falls back to `users.phone`, so the
   profile is the one to change and the account still logs in. Test accounts
   are employer `9000000001`, worker `9000000002`, OTP `1234`.
2. Switch **`ai_screening_call_enabled`** on in Admin → Settings. It is off by
   default and it gates the call regardless of what the environment says. Do it
   **on the server**: a setting saved from a local machine is cached per
   environment and never reaches live.
3. Apply to a job as that worker.
4. As the employer, open the applicant and place the screening call.
5. Flip `SCREENING_PROVIDER` to `livekit` only for this test.

What to check, in this order — each one fails differently:

- **The phone rings.** If not, the trunk is wrong: check `lk sip outbound list`
  and the Plivo console's call logs, which will show the rejected attempt.
- **You hear the greeting.** Silence with a connected call is the classic
  symptom of a wrong voice id or a missing `INWORLD_API_KEY` — neither errors,
  both just produce nothing.
- **It is in Hindi**, and the employer's name is spoken in the greeting.
- **The result appears** on the employer's applicants page with the proposed
  slot. That is the webhook round-tripping.
- **Caller ID.** It will show as an unknown Bengaluru landline until the
  Truecaller Business registration goes through.

Calls are held outside 10:00–19:00 IST (`config/screening.php`), so a test in
the evening will queue rather than dial. That is the TRAI window, not a bug.

## 7. Backing out

`SCREENING_PROVIDER=stub` and redeploy. Every call stops immediately; nothing
else has to be undone, and the trunk can stay.

## Still outstanding, and not blocked by any of the above

- **Rotate the SIP password** once the line is proven. It was passed over
  chat to get us started, which is fine for a credential that is about to be
  replaced and not fine for one that stays. Rotating it is a new credential in
  Plivo and a re-run of step 4 with the new password — the trunk id does not
  change, so nothing in the app's environment has to move.
- **Truecaller Business** registration for the number. Needs company documents
  and a business email. It is the single biggest lever on pick-up rate in
  India, and until it is done every worker sees an unknown landline.
- **Plivo's written answer on DLT and DND** for our exact case: an outbound AI
  voice agent, job screening, calling workers who applied to the job. Their
  general guidance is that DLT covers SMS and voice needs only KYC, but TRAI
  has been tightening the rules on AI-placed calls. Get it in writing and keep
  the reply.
