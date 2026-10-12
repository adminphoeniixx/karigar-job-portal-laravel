# Super Karigar **Employer app**: changes of 12 Oct 2026

Business verification is now checked automatically through Decentro. Full
reference: [`docs/kyc-verification-decentro.md`](../../kyc-verification-decentro.md)
§3 · `docs/employer-app-api.md` §8. Postman:
`docs/karigar-employer-app.postman_collection.json` → "Business Verification (KYC)" (every request has saved
example responses).

## What changed on the server

- **PAN and GSTIN are looked up in government records on submit.** An unknown
  number, or a GSTIN that is not **Active**, is `422` on `pan_number` / `gstin`
  with a message to show under the field.
- **Instant approval.** When every document checks out and the names agree with
  the legal name given, `POST /employer/kyc` answers "Your business is verified.
  You can post jobs now." with `kyc.status: "verified"`, `auto_verified: true`
  and `verification.can_post_jobs: true`. Otherwise it is `pending` for admin
  review, as before.
- **Aadhaar through DigiLocker** for *individual* and *proprietorship*
  employers: `POST /employer/kyc/digilocker` → `{ reference, authorization_url,
  return_url_prefix, expires_in_minutes }`, then
  `GET /employer/kyc/digilocker/{reference}` → `{ status, name, masked_aadhaar, message, kyc }`.
- **Photos are optional** for PAN, GST and Aadhaar. An alternate ID still needs
  its photo.
- `GET /employer/kyc` adds `digilocker` and `auto_verification_enabled`. Each
  `kyc.documents[]` entry has a `check` (`status`, `via`, `verified`,
  `name_matches`, `message`).

## What to build

1. Show the `pan_number` / `gstin` 422 messages under their fields.
2. After submit, if `verification.can_post_jobs` is true, go straight to
   "You're verified, post your job" instead of "Under review".
3. Aadhaar card (individual / proprietorship): **Verify with DigiLocker**, same
   in-app-browser flow as the worker app. Once `digilocker.verified`, leave
   `aadhaar_number` out.
4. Mark the photo pickers *(optional)*.
5. On a pending KYC, show each document's `check`: "Checked with government
   records" when `verified`, otherwise "Our team will check this".
