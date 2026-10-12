# Super Karigar **Worker app**: changes of 12 Oct 2026

KYC is now checked automatically, and **a karigar needs a verified KYC to
apply to jobs**. Full reference: [`docs/kyc-verification-decentro.md`](../../kyc-verification-decentro.md)
§3 · `docs/worker-app-api.md` §5, §7. Postman:
`docs/karigar-worker-app.postman_collection.json` → "KYC (needed to apply)" (every request has saved
example responses).

## What changed on the server

- **Applying needs a verified KYC.** `POST /jobs/{job}/apply` answers `422`
  with `code: "verification_required"`, a `message` and
  `verification: { required, status, can_apply, message }` while the karigar
  is not verified. An admin switch controls this rule, and it is on by default.
- **Know before the tap.** `GET /worker/dashboard` → `verification` (same
  shape) and `features.verification_required_to_apply`,
  `features.auto_verification_enabled`. `GET /jobs/{job}` →
  `meta.verification`. `meta.can_apply` keeps its old meaning.
- **Aadhaar through DigiLocker.** `POST /kyc/digilocker` gives an
  `authorization_url` and a `return_url_prefix`; `GET /kyc/digilocker/{reference}`
  gives the outcome.
- **PAN is checked against the Income Tax record on submit.** An unknown PAN is
  `422` on `pan_number`.
- **Instant approval.** When the DigiLocker Aadhaar and the PAN both check out
  and the names agree, `POST /kyc` already returns `kyc.status: "verified"`
  (`auto_verified: true`).
- **Photos are optional** for Aadhaar and PAN. An alternate ID still needs its
  photo.
- `GET /kyc` adds `digilocker`, `auto_verification_enabled`, `verification`,
  and a `check` on each document.

## What to build

1. **Job screen and apply.** When `verification.can_apply` is `false`, replace
   **Apply** with the `message` and a **Complete KYC** button that opens the KYC
   screen. Also handle `422 verification_required` from apply the same way
   (older cached job data).
2. **KYC screen, Aadhaar card.** If `digilocker.available`, show **Verify with
   DigiLocker** as the main action:
   - call `POST /kyc/digilocker`, open `authorization_url` in a Custom Tab /
     SFSafariViewController / WebView;
   - when the browser URL starts with `return_url_prefix`, close it and call
     `GET /kyc/digilocker/{reference}`;
   - `passed`: show "Aadhaar verified: {name} · {masked_aadhaar}" and hide the
     Aadhaar number and photo inputs; `invalid` / `error`: show `message`, keep
     the manual fields;
   - keep "Enter Aadhaar number instead" as a fallback (admin review).
3. **Submit.** Once DigiLocker passed, send only `pan_number`. Show a
   `pan_number` 422 under the field. On `kyc.status == "verified"`, show the
   success state and let the karigar go back to the job they wanted.
4. **Pending state.** If `kyc.status == "pending"` and `digilocker.verified` is
   false, offer **Skip the wait: Verify with DigiLocker**. Finishing it can turn
   the pending KYC into verified (read `kyc` in the status response).
5. Mark the photo pickers *(optional)*.
