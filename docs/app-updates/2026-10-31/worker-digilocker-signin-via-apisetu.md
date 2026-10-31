# Super Karigar **Worker app**: DigiLocker now opens DigiLocker itself (no Decentro)

**What changed on the server:** "Verify Aadhaar with DigiLocker" now signs the
karigar in to DigiLocker directly (MeriPehchaan, our own API Setu credentials)
instead of going through Decentro's pages. Server-side details:
[`kyc-digilocker-meripehchaan.md`](../../kyc-digilocker-meripehchaan.md).

**API: no change.** Same calls, same fields:

- `POST /kyc/digilocker`
  → `{ reference, authorization_url, return_url_prefix, expires_in_minutes }`
- `GET /kyc/digilocker/{reference}` → `{ status, name, masked_aadhaar, message, kyc }`

## What the in-app browser must allow

The browser now passes through **two** of our URLs. Only the second one means
"done":

1. `authorization_url` → `https://digilocker.meripehchaan.gov.in/…` (DigiLocker's
   sign-in, OTP, consent screen). Let it load.
2. After **Allow**: `https://superkarigar.com/kyc/digilocker/callback?code=…&state=…`.
   **Let this one load.** This is where the server fetches the Aadhaar. Do not
   close the browser here and do not block it.
3. The callback redirects to `https://superkarigar.com/kyc/digilocker/return/<reference>`,
   which starts with `return_url_prefix`. **Close the browser here**, then call
   `GET /kyc/digilocker/{reference}`, as today.

If the app closes on any `superkarigar.com` URL instead of on
`return_url_prefix`, it closes at step 2 and the Aadhaar is never fetched.

## Messages the karigar can now get (`status: error`)

| When | `message` |
|---|---|
| Backed out / didn't tap Allow | DigiLocker did not share your Aadhaar. Try again and tap "Allow" at the end, or type your Aadhaar number instead. |
| DigiLocker account has no Aadhaar | Your DigiLocker has no Aadhaar linked yet. Link it in the DigiLocker app, or type your Aadhaar number instead. |
| DigiLocker down | DigiLocker did not answer. Try again in a minute. |

Show `message` as it comes and offer **Try again** + **Type Aadhaar number**.

## Bonus: instant PAN

When the PAN the karigar types matches the PAN in their DigiLocker,
`POST /kyc` comes back `kyc.status: "verified"` straight away. Any other PAN
waits for the team (`kyc.status: "pending"`). No app change: it is the same
response as before.
