# AI verification calls

An AI call to a karigar or employer whose KYC is not done. It explains, step by step, how to verify.
It uses the same phone line, LiveKit agent and webhook as the AI screening calls, so the agent
server does not need a redeploy. Nobody's AI credits are charged.

## Who gets a call

- **KYC not started** (never sent): the agent explains the whole process.
- **KYC rejected**: the agent first explains the reviewer's reason in plain words, then the steps.
- Nobody whose KYC is **waiting for review**, nobody already **verified**, and nobody for whom
  verification is switched off (master switch, or the karigar switch for karigars).
- Employers: only the account **owner** (team members cannot verify the business).
- Karigars who turned AI calls off (`screening_calls_opted_out`) are never rung.

## When

- **Admin → AI Verification Calls → "AI call"**: any account in the list, any time. Outside
  10 AM–7 PM IST the call is booked for the next morning.
- **Automatic** (Admin → Settings → "AI verification calls", default **off**): accounts that sign
  up **after the switch is turned on** and are still not verified 24 hours later get one call. An
  account whose KYC is rejected is called 24 hours after the rejection, once per rejection. Accounts
  made before the switch went on (including the seeded demo accounts with made-up numbers) are never
  rung automatically. Turning the switch off and on again restarts that clock.
- A missed or busy call is tried again after 90 minutes, up to 3 tries (`screening.max_attempts`,
  `screening.retry_after_minutes`).
- Just before dialling, the account is checked again. If it has verified or sent its KYC since the
  call was booked, the call is cancelled (`nothing_to_explain`).

`verification-calls:run` (every five minutes, in the scheduler) books the automatic calls and dials
every booked call whose time has come. **Production needs the scheduler running.**

## What the agent says

`App\Services\Verification\VerificationCallScript`. Greeting (recorded-call notice + "am I speaking
to X?"), the reason for the call, why verifying helps (the actual rule: required to apply or to
post jobs, or just the verified badge), then the steps **one per reply**, checking after each.

- **Karigar:** app → KYC card → Verify with DigiLocker → Aadhaar number → OTP → Allow → verified
  at once. Fallbacks: Aadhaar typed in with a photo (no mobile linked to Aadhaar), or an alternate ID
  through "I don't have this".
- **Employer:** KYC → business type (asked on the call if not saved) → documents for that type
  (`KycRequirements`) → number + photo → Submit → the team checks. An individual can use DigiLocker
  and a matching PAN to be approved at once. Alternates for missing GST, PAN or Aadhaar.
- Never asks for, or lets them read out, an Aadhaar number, PAN, GST number, OTP or PIN, and tells
  them we never ask for an OTP on the phone. It says verification is free and promises no review time.

Outcomes: `will_verify`, `needs_help`, `not_interested`, `callback_later`, `unclear`, plus an
English summary and the transcript. Both are shown under "Calls made". The recording is not kept.

## Code

- `verification_calls` table, `App\Models\VerificationCall`
- `App\Services\Verification\VerificationCalls`: who, when, dialling, results, the sweep
- `VoiceAgent::placeScript()`: dials with any script. The room is `screening-kyc-<id>-…`, so the
  existing webhook accepts it, and `ScreeningWebhookController` sends it to `VerificationCalls::apply()`.
- `Admin\VerificationCallController` + `resources/js/pages/admin/VerificationCalls.vue`
- Tests: `tests/Feature/VerificationCallTest.php`
