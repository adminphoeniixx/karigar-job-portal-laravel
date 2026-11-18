# Worker app: no "Why don't you have it?" on KYC

QA round of 2026-11-18 (the employer app asked for this; the worker app gets
the same rule).

When a karigar marks Aadhaar "I don't have this" (`aadhaar_missing: true` on
`POST /kyc`), no reason is asked any more. Remove the "Why don't you have it?"
box. `aadhaar_reason` is still accepted from older builds but is never
required.

Nothing else changes for karigars. The Aadhaar photo and the alternate ID's
number stay **optional**. (For employers they are now required; see
`employer-kyc-photo-required-no-reason.md`.) `GET /reference` says so:

```json
"verification": {
  "photo_required": { "employer": true, "worker": false },
  "alternate_number_required": { "employer": true, "worker": false },
  …
}
```
