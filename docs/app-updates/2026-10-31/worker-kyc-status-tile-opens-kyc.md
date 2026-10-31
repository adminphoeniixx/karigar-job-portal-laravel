# Super Karigar **Worker app**: the home "KYC Status" card opens the KYC screen

**What was wrong:** the home screen shows a **KYC Status** card with no value
under it, and tapping it does nothing.

- **The blank value:** the admin has switched verification off, so
  `GET /worker/dashboard` sends `stats.kyc_status: null` and
  `features.verification_enabled: false`. The app still draws the card with
  nothing in it.
- **The dead tap:** the card is not wired to a screen. While verification is
  off, `GET /kyc` returns `404` anyway.

**The server side is already there:** `GET /kyc`, `POST /kyc`,
`POST /kyc/digilocker` and `GET /kyc/digilocker/{reference}`. Full spec:
[`worker-app-verification.md`](../../worker-app-verification.md) and
[`worker-app-api.md` § 7](../../worker-app-api.md).

## What the app should do

### 1. The card (home, `GET /worker/dashboard`)

| `features.verification_enabled` | Card |
|---|---|
| `false` | **Hide the card.** `stats.kyc_status` is `null`; KYC is switched off |
| `true` | Show `stats.kyc_status_label`; tap → KYC screen |

`stats.kyc_status` → what to show:

| `kyc_status` | `kyc_status_label` | Card text / colour |
|---|---|---|
| `not_submitted` | Not submitted | "Verify now" (accent) |
| `pending` | Pending review | "Under review" (amber) |
| `rejected` | Rejected | "Fix & resubmit" (red) |
| `verified` | Verified | "Verified ✓" (green) |

### 2. The KYC screen (`GET /kyc`)

Read `kyc.status` (or `null` = never submitted):

- **`null` / `rejected`:** show the form. For `rejected`, put `kyc.remarks` (the
  admin's reason) on top.
  - **Aadhaar:** if `digilocker.available` is `true`, offer a
    **"Verify Aadhaar with DigiLocker"** button first:
    `POST /kyc/digilocker` → open `authorization_url` in an in-app browser →
    close it once the URL starts with `return_url_prefix` →
    `GET /kyc/digilocker/{reference}`. When `status` is `passed`, show
    `masked_aadhaar` + `name` as done and leave `aadhaar_number` out of the
    submit. Otherwise (or if `available` is `false`) type the Aadhaar number.
  - **PAN:** type the number. A karigar without one taps "I don't have this"
    (`pan_missing=1` + alternate ID, see the spec).
  - Submit → `POST /kyc` (multipart). `201`; `kyc.status` is `verified` at once
    when PAN + DigiLocker Aadhaar pass and the names agree, `pending` otherwise.
- **`pending`:** "Under review" with `masked_aadhaar` / `masked_pan`; no form.
- **`verified`:** "Verified ✓" with the masked numbers; no form.

After a submit or a DigiLocker attempt, reload `GET /worker/dashboard` so the
card updates.

### 3. Verifying is optional unless the admin makes it a rule

`verification.required` (in both `GET /kyc` and the dashboard) says whether
applying needs a verified KYC:

- `false`: the karigar can verify **if they choose to**, for the verified
  badge. Apply works either way (`verification.can_apply` is `true`). Show
  KYC as an offer, not a block.
- `true`: unverified karigars get `422 verification_required` on apply; show
  `verification.message` and send them to the KYC screen.

## Change in the response: `404` now says why

While verification is switched off, every `/kyc*` route still answers `404`,
but with a body instead of an empty `message`:

```json
{ "message": "Verification is switched off right now.", "code": "verification_disabled" }
```

If the app gets `code: verification_disabled`, close the KYC screen and hide
the card. This only happens if the admin switches it off while the app is open.
