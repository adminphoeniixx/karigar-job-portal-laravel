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

---

# Faster "Jobs near" location change

Changing where the feed looks used to take three calls one after another:
search, save, reload. Now:

- **`PUT /worker/feed-location` and `DELETE /worker/feed-location` send the
  feed back in `jobs`**, the same thing `GET /jobs` returns (first page,
  nearest first, `links.next` pointing at `GET /jobs`). Show it straight away
  and skip the extra `GET /jobs`. For `DELETE`, send the phone's position as
  `?lat=…&lng=…`. Both take an optional `all`.
- **A city name in `GET /places` is answered at once** from our own list
  ("jaip" → Jaipur, "Udaipur, Tri" → Udaipur in Tripura). Localities, PIN codes
  and Hindi text still go to OpenStreetMap.
- Every API call does less work on the server: settings are read once per
  request, and the token's last-used time is written at most once a minute.

What to build: see "Making the change feel instant" in
[`docs/worker-app-feed-location.md`](../../worker-app-feed-location.md). In
short: one kept-alive HTTP client, update the "Jobs near …" bar the moment it
is tapped, render `jobs` from the answer, and show the last feed from the
device while a fresh one loads.

---

# Karigar's area ("2 km · Sanganer")

Employers now see how far a karigar is and in which area. That needs the
karigar's area. Today almost everyone is only pinned at their city's centre.

- `PATCH /worker/profile` takes `area` (locality, ≤120) and an optional
  `location_source` (`gps` | `area` | `city`) saying where a new
  `latitude`/`longitude` came from. The profile answer includes `area` and
  `location_source`. Rules: `docs/worker-app-api.md` "Where the karigar is".
- `GET /places` results now carry `area` too, so a place picked from the
  search can be saved with it.

## What to build

1. Profile / sign-up location step: an **Area / Mohalla** field, with search
   (`GET /places`) and a **Use my location** button.
   - Use my location: send `latitude` + `longitude` + `location_source: "gps"`.
     The server fills `area`.
   - A picked place: send its `latitude`, `longitude` and `area` with
     `location_source: "area"`.
   - Typed text only: send `area`.
2. Ask for it once on the home screen ("Add your area so employers nearby find
   you") while `location_source` is `city` or empty.
3. Send `latitude`/`longitude` only when the karigar sets their location, not
   with every profile save.
