# Super Karigar **Employer app**: the business-verification `404` now says why

**What changed:** while the admin has verification switched off, every
`/employer/kyc*` route still answers `404`, but now with a body instead of an
empty `message`:

```json
{ "message": "Verification is switched off right now.", "code": "verification_disabled" }
```

**App change needed:**

- `GET /employer/dashboard` already sends `features.verification_enabled`.
  While it is `false`, hide the business-verification entry points.
- If a `/employer/kyc*` call gets `code: verification_disabled`, close the
  verification screen and hide those entry points. This only happens if the
  admin switches verification off while the app is open.
