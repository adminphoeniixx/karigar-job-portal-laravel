# DigiLocker sign-in through API Setu (MeriPehchaan)

Aadhaar now comes straight from DigiLocker, signed in with **our own API Setu
credentials**. Decentro is no longer in the middle. The flow follows the
[Requester – Meri Pehchaan API Specification v2.3](https://cf-media.api-setu.in/resources/Requester-MeriPehchaan-APISpecification-V2_3.pdf):
OAuth 2.0 with PKCE, plus OpenID.

It switches on by itself once `DIGILOCKER_CLIENT_ID` and
`DIGILOCKER_CLIENT_SECRET` are set. The app API is unchanged, but the in-app
browser must let the callback load; see the
[worker](app-updates/2026-10-31/worker-digilocker-signin-via-apisetu.md) and
[employer](app-updates/2026-10-31/employer-digilocker-signin-via-apisetu.md)
app notes.

**Decentro is commented out** (2026-10-31): `DecentroClient::configured()`
returns `false`, so nothing is sent to Decentro, whatever keys the server has.
Its code and tests stay. To bring Decentro back, put the original line back in
`DecentroClient::configured()`; its 12 tests in `KycAutoVerificationTest`
then run again on their own.

---

## 1. Setup (done on 2026-10-31)

**API Setu** → [consume.apisetu.gov.in](https://consume.apisetu.gov.in) →
Consume APIs → **MeriPehchaan Auth** → *Add Authpartner*:

| Field | Value |
|---|---|
| Website URL / App Domain | `https://superkarigar.com` |
| Descriptive App Name | `Super Karigar` |
| Tag | Production |
| Call Back URL | `https://superkarigar.com/kyc/digilocker/callback` |
| Scope | Openid · Profile information · Issued Documents |
| Logo | SUPER कारीगर wordmark, 150×40 PNG |

Client ID: `RT7FCC410F`. The secret is only in the env, never in git or docs.

**Env** (local `.env` and easypanel → Environment, then redeploy):

```
DIGILOCKER_CLIENT_ID=RT7FCC410F
DIGILOCKER_CLIENT_SECRET=…
DIGILOCKER_REDIRECT_URI=https://superkarigar.com/kyc/digilocker/callback
```

`DIGILOCKER_REDIRECT_URI` must equal the Call Back URL on API Setu, character
for character. Left blank, it falls back to this site's own
`/kyc/digilocker/callback`. `DIGILOCKER_BASE_URL` defaults to
`https://digilocker.meripehchaan.gov.in`.

A callback on `localhost` would need a second Authpartner tagged *Local*. The
production one only sends users back to `superkarigar.com`.

---

## 2. Flow

```
app / website ──POST /kyc/digilocker──▶ server
   server: KycCheck(initiated, details.code_verifier) + authorization URL
user ──▶ digilocker.meripehchaan.gov.in/public/oauth2/1/authorize
          (client_id, redirect_uri, state=<reference>, code_challenge S256, scope=openid, purpose=kyc)
user signs in, taps Allow
DigiLocker ──▶ GET /kyc/digilocker/callback?code=…&state=<reference>
   server: POST /public/oauth2/2/token (code + client_secret + code_verifier)
           → access_token + id_token { given_name, masked_aadhaar, pan_number, … }
           GET /public/oauth2/3/xml/eaadhaar (Bearer) → e-Aadhaar XML, `hmac` header checked
   KycCheck → passed (name, last4, dob, gender, district, state, pincode, pan)
callback ──302──▶ /kyc/digilocker/return/<reference>
   website: back to /kyc with a toast · app: "go back to the app" page;
   the app closes its browser on `return_url_prefix` and reads GET /kyc/digilocker/{reference}
```

- **Kept:** name, last 4 Aadhaar digits, DOB, gender, district, state, PIN, and
  the PAN DigiLocker holds. **Never kept:** the full Aadhaar number, photo or
  street address. `details` is encrypted at rest.
- **e-Aadhaar unreadable** (HMAC mismatch, not issued): the id_token's
  `masked_aadhaar` and `given_name` are used. `details.eaadhaar_error` records why.
- **No Aadhaar at all** (`aadhaar_not_linked`): "Your DigiLocker has no
  Aadhaar linked yet…".
- **Backed out** (`?error=access_denied`): "DigiLocker did not share your
  Aadhaar…", and DigiLocker is not called.
- A failed attempt keeps DigiLocker's own error in `details.provider_key` /
  `provider_message`. Read the `kyc_checks` row to see why it failed.

## 3. PAN

A typed PAN that equals the `pan_number` DigiLocker gave is verified on the
spot, under the Aadhaar name. The karigar is then verified at once. Any other
PAN, and every GSTIN, goes to an admin in KYC Review ("Our team will check
this number."), since Decentro's registry lookup is commented out.

## 4. Code

| File | What |
|---|---|
| `app/Services/Kyc/DigilockerClient.php` | authorize URL + PKCE, token, e-Aadhaar (HMAC), id_token claims, XML reading |
| `app/Services/Kyc/KycAutoVerifier.php` | `startDigilocker` / `completeDigilocker` pick the path per attempt; `panFromDigilocker` |
| `app/Http/Controllers/KycDigilockerController.php` | `callback` (new, public) → `return` |
| `config/services.php` → `digilocker` | env above |
| `tests/Feature/DigilockerSignInTest.php` | the whole flow against faked DigiLocker answers |

Checked against the real service on 2026-10-31: the authorize URL opens
DigiLocker's sign-in page as "Super Karigar" with our logo. The first real
sign-in can only happen on `superkarigar.com`, after the deploy.
