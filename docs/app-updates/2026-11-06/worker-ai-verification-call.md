# Worker app: AI call that explains KYC (no API change)

Since 2026-11-06 the server can ring a karigar whose KYC is **not started** or **rejected**. The
call comes from the screening-call number (+91 80 3170 3250), and an AI voice talks the karigar
through the KYC screens in Hindi (or the karigar's language). The admin starts the call, or the
server does it 24 hours after signup once the admin switch is on.

**There is no new endpoint, field or push.** The app needs no code change. But the AI reads out
the app's screen and button names, so the app has to keep these labels as they are:

| What the AI says | Where it must be in the app |
|---|---|
| "Home पर KYC वाले card पर tap कीजिए" | The KYC Status card on Home (shown while `features.verification_enabled` is true), which opens the KYC screen |
| "Profile या menu में KYC Verification" | A "KYC Verification" entry in Profile / the menu, for when they cannot find the card |
| "Verify with DigiLocker" | The DigiLocker button on the KYC screen, with this exact text |
| "Aadhaar number डालिए, Aadhaar की photo लगाइए, Submit" | The typed Aadhaar + photo + **Submit** path, for when no mobile number is linked to Aadhaar |
| "'I don't have this' वाला option" | The alternate-ID option (Voter ID, Driving licence, Passport, Ration card), with this exact text |

If one of these is renamed in the app (for example, if the button becomes "DigiLocker से verify
करें"), tell the backend so the call script says the new words.

## Turning calls off

A karigar who sets `screening_calls_opted_out: true` (`PATCH /worker/profile`) gets neither the
screening calls nor this call. Known gap: `GET /worker/profile` does not return the field yet, so
the app cannot show the switch's current state (see `docs/worker-app-pending.md` §1.1).
