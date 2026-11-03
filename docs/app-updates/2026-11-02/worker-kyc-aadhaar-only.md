# Super Karigar **Worker app**: KYC is Aadhaar only, no PAN

**What changed on the server:** a karigar is no longer asked for a PAN card.
KYC is **Aadhaar only**. Once the Aadhaar is verified, the karigar is
verified.

- **DigiLocker:** when DigiLocker shares the Aadhaar, the karigar is
  verified **right then**. No "Submit KYC" call is needed after it.
- **Aadhaar typed by hand** (or an alternate ID sent instead) still goes to
  the team for review (`pending`), as before.

Employers are not affected: they still give PAN / GST for their business type.

## What the app must change

1. **Remove the PAN card** from the KYC screen: no PAN number field, no PAN
   photo, no PAN "I don't have this" toggle. Only the Aadhaar card stays.
   If the screen is built from `required_documents` (or `/reference` →
   `verification.worker_documents`), it already shows only Aadhaar: both are
   now `["aadhaar"]`.
2. **After DigiLocker, don't ask for anything more.** When the in-app browser
   closes, call `GET /kyc/digilocker/{reference}` as today. If `status` is
   `passed`, `kyc.status` is already `"verified"`: show the verified state
   and go back. Drop the old "now enter your PAN" step and the
   "Submit KYC (PAN only)" call.
3. Change any text that says "Aadhaar + PAN" to just "Aadhaar".

## API

| Call | What's different |
|---|---|
| `GET /kyc` | `required_documents` is `["aadhaar"]`. `kyc.documents` has only the Aadhaar entry. `kyc.masked_pan` is `null`. |
| `GET /reference` | `verification.worker_documents` is `["aadhaar"]`. |
| `GET /kyc/digilocker/{reference}` | When `status` is `passed`, `kyc` comes back with `status: "verified"` and `auto_verified: true`, even if the karigar never submitted KYC. |
| `POST /kyc` | Send `aadhaar_number` (photo `aadhaar_doc` optional), **or** `aadhaar_missing=1` + `aadhaar_alt_type`, `aadhaar_alt_number`, `aadhaar_alt_doc`, `aadhaar_reason`. After DigiLocker, `aadhaar_number` may be left out and the answer is `verified`. Any `pan_*` field is ignored (an old app build sending it keeps working). |
| `GET /worker/dashboard` | The `verification.message` for a karigar who hasn't done KYC is now "Verify your Aadhaar to apply to jobs. It takes a minute." |

### `GET /kyc/digilocker/{reference}` after a successful DigiLocker

```json
{
  "reference": "KRG-01M4Q5VX82NAFW3KB1S16HTWPQ",
  "status": "passed",
  "name": "RAMESH KUMAR",
  "masked_aadhaar": "XXXX XXXX 4321",
  "message": null,
  "kyc": {
    "status": "verified",
    "status_label": "Verified",
    "masked_pan": null,
    "masked_aadhaar": "XXXX XXXX 4321",
    "business_type": null,
    "documents": [
      {
        "type": "aadhaar",
        "label": "Aadhaar card",
        "missing": false,
        "number": "XXXX XXXX 4321",
        "has_file": false,
        "alternate": null,
        "check": { "status": "passed", "via": "digilocker", "verified": true, "name_matches": null, "message": null, "checked_at": "2026-11-02T06:10:00+00:00" }
      }
    ],
    "has_missing_documents": false,
    "auto_verified": true,
    "remarks": null,
    "reviewed_at": "2026-11-02T06:10:00+00:00",
    "submitted_at": "2026-11-02T06:10:00+00:00"
  }
}
```

The `kyc.reviewed` push (approved) also arrives at this moment. Refetch
`GET /kyc` on it, as today.

## Karigars who already sent a PAN

Their verified KYC stays verified. A PAN they sent earlier is no longer shown
in `kyc.documents`. If such a karigar is still under review and now does
DigiLocker, they are verified and the old PAN is removed.

Postman: `docs/karigar-worker-app.postman_collection.json` → "KYC (needed to
apply)". The "Submit KYC (PAN only, after DigiLocker)" request is gone.
