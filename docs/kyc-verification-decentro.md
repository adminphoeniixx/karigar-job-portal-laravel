# KYC verification with Decentro

Super Karigar now verifies identity documents automatically through
[Decentro](https://docs.decentro.tech/docs/kyc-and-onboarding-overview-and-guide).
When every document checks out against government records, the user is
verified on the spot. Anything else goes to an admin in **KYC Review**, as
before.

Two rules hang off a verified KYC:

| Who | Without a verified KYC | Admin switch (Admin → Settings) |
|---|---|---|
| **Karigar** | **Cannot apply to any job.** They can still browse, save jobs and call employers who list a number. | **Karigars must be verified to apply** (new, default **on**) |
| **Employer** | Cannot publish a job. Drafts still save. | **Employers must be verified to post jobs** (existing, default on) |

Both rules switch off by themselves while **KYC verification**, or for karigars
**Karigar verification**, is off.

---

## 1. What gets checked, and how

| Document | How it is checked | Decentro API |
|---|---|---|
| **PAN** | The number is looked up in the Income Tax record, which gives the holder's name and status. | `POST /kyc/public_registry/validate`, `document_type: PAN` |
| **GSTIN** | The number is looked up in the GST record, which gives the legal name, trade name and Active/Cancelled status. | `POST /kyc/public_registry/validate`, `document_type: GSTIN` |
| **Aadhaar** | **DigiLocker.** The user signs in to DigiLocker with their Aadhaar OTP and shares their eAadhaar. We get the verified name, DOB, district, state, PIN and the **last 4 digits** only. | `POST /v2/kyc/digilocker/initiate_session`, then `POST /v2/kyc/digilocker/eaadhaar` |
| Aadhaar typed in by hand | **Not checked.** Decentro cannot verify an Aadhaar number on its own, so it goes to admin review. | — |
| Alternate ID ("I don't have this") | **Not checked.** Admin review, as before. | — |

Which documents a user is asked for has not changed (`App\Support\KycRequirements`):

- **Karigar:** Aadhaar + PAN
- **Employer, individual:** Aadhaar + PAN
- **Employer, sole proprietorship:** Aadhaar + PAN + GST
- **Employer, firm, LLP or company:** PAN + GST (the business's own)

**Photos are now optional** for Aadhaar, PAN and GST: the number is what gets
checked. The photo for an **alternate ID** is still required, because an admin
has nothing else to go on.

### When is a user verified instantly?

All of these must hold:

1. Every document asked for was **checked and passed**: PAN valid, GSTIN
   **Active**, Aadhaar from DigiLocker.
2. No document was swapped for an alternate ID.
3. **The names agree:**

   | Who | Names compared |
   |---|---|
   | Karigar | Aadhaar name ↔ PAN name |
   | Employer, individual | Aadhaar ↔ PAN, and PAN ↔ "Full name (as on PAN)" |
   | Employer, proprietorship | Aadhaar ↔ PAN, and GST legal or trade name ↔ business name |
   | Employer, firm or company | PAN ↔ legal business name, and GST legal or trade name ↔ legal business name |

   Matching is forgiving, because registries spell names differently
   (`App\Services\Kyc\NameMatcher`):
   - Titles and company suffixes are ignored (Mr, Shri, M/s, Pvt, Ltd, Private, Limited, LLP…).
   - Every word of the shorter name must appear in the longer one, as the whole
     word, as its initial, or one letter off. So "R K SHARMA" matches "RAMESH
     KUMAR SHARMA", and "MOHAMMAD" matches "MOHAMMED".
   - At least one whole word must match, so two sets of initials never pass.

Then the KYC is saved as **verified**, with `auto_verified = true` and no
reviewer. The user gets the usual "You are verified" notification and push, and
a karigar's profile is re-indexed for the verified badge.

### What happens otherwise

| Situation | Result |
|---|---|
| The registry does not know the PAN or GSTIN | **Refused immediately** with a 422 on `pan_number` / `gstin` ("This PAN was not found in Income Tax records. Check the number."). Nothing is saved. |
| The GSTIN is Cancelled or Suspended, or the PAN is not valid | **Refused immediately** with a 422 ("This GSTIN is not active (status: Cancelled)."). |
| Names don't match | Saved as **pending**. Admin sees "name mismatch" and the name on record. |
| Aadhaar typed by hand, or an alternate ID | Saved as **pending**. |
| Decentro down, timed out, or returned an error | Saved as **pending**. Admin sees "Not checked: …". |
| Auto-verify switched off, or no Decentro keys | Saved as **pending**. Nothing is called, exactly as before. |

**A pending submission can still become verified on its own.** If a karigar
typed their Aadhaar by hand and later completes DigiLocker, the DigiLocker
Aadhaar replaces the typed one and the submission is decided again. If the PAN
had passed and the names agree, they are verified there and then.

---

## 2. The DigiLocker flow

```
App / website                Super Karigar server                   Decentro / DigiLocker
─────────────                ────────────────────                   ─────────────────────
POST …/kyc/digilocker  ───▶  create kyc_checks row (initiated)
                             initiate_session(redirect_url =  ───▶
                             /kyc/digilocker/return/{reference})
                        ◀─── authorization_url                ◀───  authorizationUrl
open authorization_url ─────────────────────────────────────────▶  user signs in with
(in-app browser)                                                    Aadhaar OTP, consents
                                                                    ◀── redirects back
GET /kyc/digilocker/return/{reference}  ───▶ eaadhaar()        ───▶
                                        ◀─── name, DOB, last 4  ◀───
                             row → passed; a pending submission is re-decided
app closes the browser,
GET …/kyc/digilocker/{reference} → status
```

- The return URL is **public**: the in-app browser has no session. The
  `reference` in the URL (`KRG-<ulid>`, unguessable) identifies the attempt.
- A session lives **60 minutes**. After that the return page reports
  "expired" and the user starts again.
- On the **website**, a signed-in user coming back is redirected to `/kyc` with
  a toast. Anyone else (the in-app browser) sees a small "Aadhaar verified. Go
  back to the app." page (`resources/views/kyc/digilocker-return.blade.php`).
- **DigiLocker cannot be tested from `localhost`.** The return URL is built
  from the address the request came in on (not `APP_URL`), and Decentro's
  firewall answers `403 Forbidden` to a `localhost` one. On the server, behind
  the easypanel proxy, it is the public `https` address and works. PAN and
  GSTIN checks work from anywhere.

---

## 3. API for the apps

All routes `404` while verification is switched off for that user (the
existing `kyc.enabled` middleware). Base: `/api/v1`, Sanctum token.

**Postman:** both collections have these requests, with saved example
responses recorded from the real code:
- `docs/karigar-worker-app.postman_collection.json` → **KYC (needed to apply)**:
  Get KYC status, Start DigiLocker (saves `kyc_reference`), DigiLocker result,
  Submit KYC (PAN only, after DigiLocker), Submit KYC. "Applications → Apply to
  job" has the `422 verification_required` example.
- `docs/karigar-employer-app.postman_collection.json` → **Business Verification
  (KYC)**: Get verification status, Submit business verification (company),
  Start DigiLocker, DigiLocker result, Submit business verification
  (individual, after DigiLocker).

The numbers in the request bodies are placeholders. The real registry answers
`422 "not found"` to them, so use real numbers to see a check pass.

### Karigar app

#### `POST /kyc/digilocker` (rate limit 10/hour)
```json
// 201
{
  "reference": "KRG-01K7…",
  "authorization_url": "https://in.decentro.tech/digilocker/digilocker-redirect?state=…",
  "return_url_prefix": "https://superkarigar.com/kyc/digilocker/return/",
  "expires_in_minutes": 60
}
// 422 when auto-verify is off or Decentro failed
{ "message": "DigiLocker verification is not available right now. Enter your Aadhaar number instead.", "code": "digilocker_unavailable" }   // or "digilocker_failed"
```
Open `authorization_url` in an in-app browser (Custom Tab / SFSafariViewController
or a WebView). **When the browser's URL starts with `return_url_prefix`, close it**
and call the status endpoint.

#### `GET /kyc/digilocker/{reference}`
```json
{
  "reference": "KRG-01K7…",
  "status": "passed",                 // initiated | passed | invalid | error
  "name": "RAMESH KUMAR",             // only when passed
  "masked_aadhaar": "XXXX XXXX 4321",
  "message": null,                    // why, when invalid/error
  "kyc": { …KycResource… } | null     // a pending KYC may have just turned verified
}
```

#### `GET /kyc` (new fields)
```json
{
  "kyc": { …, "auto_verified": true, "documents": [ { …, "check": {…} | null } ] },
  "required_documents": ["aadhaar", "pan"],
  "digilocker": { "available": true, "verified": true, "name": "RAMESH KUMAR", "masked_aadhaar": "XXXX XXXX 4321", "verified_at": "…" },
  "auto_verification_enabled": true,
  "verification": { "required": true, "status": "verified", "can_apply": true, "message": null }
}
```

#### `POST /kyc` (multipart; rate limit 20/hour)
Unchanged fields, except:
- **`aadhaar_number` may be left out once `digilocker.verified` is true.** Send
  only `pan_number`.
- `{doc}_doc` photos are **optional**.
- A PAN the registry does not know returns **422** on `pan_number`. Show it
  under the field.

```json
// 201, instantly verified
{ "message": "Your KYC is verified. You can apply to jobs now.", "kyc": { "status": "verified", "auto_verified": true, … },
  "verification": { "required": true, "status": "verified", "can_apply": true, "message": null } }
// 201, needs a human
{ "message": "KYC submitted for review.", "kyc": { "status": "pending", … }, "verification": { …, "can_apply": false, "message": "Your KYC is under review. …" } }
```

#### Each document's `check`
```json
"check": {
  "status": "passed",            // passed | invalid | error
  "via": "registry",             // registry (PAN/GST) | digilocker (Aadhaar)
  "verified": true,              // passed AND the name agrees
  "name_matches": true,          // false = mismatch (admin will look); null = not compared
  "message": null,
  "checked_at": "2026-10-12T09:30:00+00:00"
}
```
`check` is `null` when the document was not checked: typed-in Aadhaar,
alternate ID, or auto-verify off.

#### Applying: `POST /jobs/{job}/apply`
```json
// 422 while the karigar's KYC is not verified (and the admin rule is on)
{
  "message": "Complete your KYC (Aadhaar + PAN) to apply to jobs. It takes two minutes.",
  "code": "verification_required",
  "verification": { "required": true, "status": "not_submitted", "can_apply": false, "message": "…" }
}
```
`status` can be `not_submitted`, `pending` ("…under review…") or `rejected`
("…not approved. Fix your documents…"). Show `message` and a **Complete KYC**
button that opens the KYC screen.

Know before the tap, so you can swap **Apply** for **Complete KYC**:
- `GET /worker/dashboard` → `verification` (same shape), plus
  `features.verification_required_to_apply` and `features.auto_verification_enabled`.
- `GET /jobs/{job}` → `meta.verification` (same shape). `meta.can_apply` keeps
  its old meaning (the job is open and you haven't applied).

### Employer app

Same DigiLocker pair, on the employer path (used by **individual** and
**proprietorship** employers, who are asked for Aadhaar):

- `POST /employer/kyc/digilocker` → same response as the karigar one
- `GET /employer/kyc/digilocker/{reference}` → same response

`GET /employer/kyc` adds `digilocker` and `auto_verification_enabled`.
`POST /employer/kyc`:
- answers `"Your business is verified. You can post jobs now."` with
  `verification.can_post_jobs: true` when everything checked out;
- answers 422 on `pan_number` / `gstin` for a number the registry does not know,
  or a GSTIN that is not Active.

A team member verifies the account owner, as before.

---

## 4. Website

- **`/kyc`**: the Aadhaar card offers **Verify with DigiLocker** (instant), with
  the number field below it as a fallback for admin review. Once DigiLocker is
  done, the card shows the verified name and masked Aadhaar, and no number is
  asked for. Photo fields are marked *(optional)*. The status card shows, per
  document, "Checked with government records", "Verified through DigiLocker" or
  why it wasn't. A pending submission gets a **Skip the wait: Verify with
  DigiLocker** box.
- **Job pages** (`/jobs/{id}` and `/worker/jobs/{id}`): an unverified karigar
  sees the reason and **Complete KYC to apply** instead of **Apply now**.
  Applying anyway redirects to `/kyc` with the reason.
- **Admin → KYC Review**: an **Auto-verified** badge, and per document what the
  registry said: match or name mismatch, the name on record, the GST trade name,
  the registry status, or why it could not be checked.
- **Admin → Settings**: two new switches, **Karigars must be verified to apply**
  and **Auto-verify with Decentro** (with a warning when the keys are missing).

---

## 5. Data

**`kyc_checks`**: one row per Decentro lookup, and the DigiLocker session itself.

| Column | |
|---|---|
| `user_id` | the account (an employer's owner, not the team member) |
| `document` | `aadhaar`, `pan`, `gst` |
| `method` | `registry` or `digilocker` |
| `reference` | ours, sent as `reference_id`, unique |
| `provider_txn_id` | Decentro's `decentroTxnId`, for support tickets |
| `number_hash` | sha256 of the number. A passed check of the same number is **reused for 30 days**, so a resubmission isn't billed twice. |
| `status` | `initiated`, `passed`, `invalid`, `error` |
| `name`, `details` | encrypted: name on record, GST trade name/status/address, Aadhaar last 4 / DOB / district / state / PIN |
| `message` | why it failed |

**`kyc_documents`** gains:
- `checks`: encrypted per-document snapshot of what was found at submission time
- `auto_verified`: approved by the checks rather than an admin. An admin
  approving or rejecting by hand sets it back to `false`.
- `aadhaar_last4`: DigiLocker only gives the last 4 digits, so `aadhaar_number`
  stays empty for a DigiLocker Aadhaar.

The eAadhaar photo and XML are **not** fetched or stored.

---

## 6. Code map

| File | Role |
|---|---|
| `app/Services/Kyc/DecentroClient.php` | HTTP calls. Never throws on provider failure; returns `DecentroResult` (ok / not_found / error). |
| `app/Services/Kyc/KycAutoVerifier.php` | Registry checks, DigiLocker start/complete, the verify-or-pending decision, name pairs |
| `app/Services/Kyc/NameMatcher.php` | Name comparison |
| `app/Services/KycSubmission.php` | The one save path (web + both apps). Runs the checks first, then saves, then decides. |
| `app/Support/WorkerVerification.php` | "Must be verified to apply": `required()`, `blockMessage()`, `summary()` |
| `app/Http/Controllers/KycDigilockerController.php` | Web start + the public return page |
| `app/Http/Controllers/Api/KycDigilockerController.php` | App start + status (both roles) |
| `database/migrations/2026_10_12_000001_create_kyc_checks_table.php` | Schema (already run on the shared DB) |
| `tests/Feature/KycAutoVerificationTest.php` | 24 tests, with Decentro faked |

---

## 7. Configuration and deploy

The server reads its environment from **easypanel → the app → Environment**:
`.env` is in `.dockerignore`, so the local file never reaches the container.
Add these there:

```env
DECENTRO_BASE_URL=https://in.staging.decentro.tech   # production: https://in.decentro.tech
DECENTRO_CLIENT_ID=…
DECENTRO_CLIENT_SECRET=…
DECENTRO_KYC_MODULE_SECRET=…
# optional
DECENTRO_TIMEOUT=25
WORKER_VERIFICATION_REQUIRED=true   # default for the admin switch until it is saved
```

- With **no** `DECENTRO_CLIENT_ID`, nothing is called and every submission
  waits for an admin. The site works exactly as before.
- **Staging vs production keys.** The keys we have are **staging**
  (`in.staging.decentro.tech`). Staging answered PAN `AAFCP6967R` and GSTIN
  `06AAFCP6967R1ZF` with real records, but go-live needs Decentro's
  **production** keys and `DECENTRO_BASE_URL=https://in.decentro.tech`.
- **Redeploy after changing the environment.** The container runs
  `php artisan optimize` on start, so config is cached and a new variable is
  only read on the next deploy or restart.
- `DECENTRO_KYC_MODULE_SECRET` is optional (the PAN, GSTIN and DigiLocker calls
  work without it). The Payments module secret is not used.
- **Before deploy, decide on "Karigars must be verified to apply".** It
  defaults to **on**, so the moment this goes live, **every karigar without a
  verified KYC stops being able to apply** until they verify. That is what was
  asked for. For a grace period, switch it off in Admin → Settings **on the
  live site** (settings are cached per server, so one saved locally never
  reaches live), or deploy with `WORKER_VERIFICATION_REQUIRED=false`, which
  only sets the default until an admin saves the switch.
- Every PAN, GSTIN and DigiLocker call is **billed by Decentro**. Calls are
  rate-limited per user (submit 20/hour, DigiLocker 10/hour), and a passed
  number is reused for 30 days.

Migration: `php artisan migrate` (already run on the shared database).

---

## 8. Testing

```bash
php artisan test --compact tests/Feature/KycAutoVerificationTest.php
```

Covers: a company verified instantly with no photos; unknown PAN/GSTIN refused;
cancelled GSTIN refused; name mismatch left pending; Decentro down left
pending; auto-verify off calls nothing; karigar DigiLocker + PAN verified;
DigiLocker completing a waiting submission; PAN/Aadhaar name mismatch; web
DigiLocker round trip; apply blocked / allowed / rule off; app flags; name
matching cases.

Manual check against staging (from any machine, no DigiLocker):

```bash
curl -X POST https://in.staging.decentro.tech/kyc/public_registry/validate \
  -H 'Content-Type: application/json' -H "client_id: $DECENTRO_CLIENT_ID" -H "client_secret: $DECENTRO_CLIENT_SECRET" \
  -d '{"reference_id":"test-1","document_type":"PAN","id_number":"AAFCP6967R","consent":"Y","consent_purpose":"Employer business verification for job posting"}'
```

`consent_purpose` must be **20 to 50 characters**. Decentro rejects anything
longer with "Purpose cannot exceed 50 characters."
