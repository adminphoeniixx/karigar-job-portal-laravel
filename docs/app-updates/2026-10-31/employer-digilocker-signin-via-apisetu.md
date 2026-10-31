# Super Karigar **Employer app**: DigiLocker now opens DigiLocker itself (no Decentro)

**What changed on the server:** "Verify Aadhaar with DigiLocker" in business
verification now signs the employer in to DigiLocker directly (MeriPehchaan,
our own API Setu credentials) instead of going through Decentro's pages.
Server-side details:
[`kyc-digilocker-meripehchaan.md`](../../kyc-digilocker-meripehchaan.md).

**API: no change.** Same calls, same fields:

- `POST /employer/kyc/digilocker`
  → `{ reference, authorization_url, return_url_prefix, expires_in_minutes }`
- `GET /employer/kyc/digilocker/{reference}` → `{ status, name, masked_aadhaar, message, kyc }`

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
   `GET /employer/kyc/digilocker/{reference}`, as today.

If the app closes on any `superkarigar.com` URL instead of on
`return_url_prefix`, it closes at step 2 and the Aadhaar is never fetched.

## Messages the employer can now get (`status: error`)

| When | `message` |
|---|---|
| Backed out / didn't tap Allow | DigiLocker did not share your Aadhaar. Try again and tap "Allow" at the end, or type your Aadhaar number instead. |
| DigiLocker account has no Aadhaar | Your DigiLocker has no Aadhaar linked yet. Link it in the DigiLocker app, or type your Aadhaar number instead. |
| DigiLocker down | DigiLocker did not answer. Try again in a minute. |

Show `message` as it comes and offer **Try again** + **Type Aadhaar number**.

## What is approved at once, and what waits for the team

No app change: `POST /employer/kyc` answers as before, with `kyc.status`
`verified` or `pending`.

| Business type | Approved at once when | Otherwise |
|---|---|---|
| `individual` | the PAN typed is the PAN in their DigiLocker **and** `legal_name` matches the Aadhaar name | `pending`, the team checks it |
| `proprietorship` | never: GSTIN numbers are checked by the team for now | `pending` |
| companies, firms, others | never: the business PAN is not the owner's DigiLocker PAN | `pending` |

While the status is `pending`, keep showing "Under review". The employer gets
the `kyc.reviewed` push once the team decides.
