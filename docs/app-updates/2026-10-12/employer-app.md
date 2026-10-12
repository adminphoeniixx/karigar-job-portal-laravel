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

---

# Worker Database: reject for free, distance with area, "unlocked by"

Three asks on karigar cards, server side done. Reference:
`docs/employer-app-api.md` §6 (applicants), §5 (matches), §7 (Find Workers).

## What changed on the server

1. **Reject without unlocking.** `POST /employer/workers/{worker}/reject`
   ("Not interested") costs nothing: no unlock is spent and the number stays
   hidden. The karigar leaves this employer's searches and job matches, so the
   next karigar moves up into the plan's reach. They wait in a **Rejected** tab:
   `GET /employer/workers?rejected=1`. `DELETE /employer/workers/{worker}/reject`
   brings them back. `GET /employer/workers` adds `rejected_count` for the tab
   badge. Every row and the profile carry `rejected`.
2. **How far, and where.** Karigar rows (Find Workers list and profile, job
   matches, every applicant) now carry:
   - `distance_label`: `"1.5 km"`, `"800 m"`, or `"~4 km"` when the karigar
     only gave a city (`distance_approx: true`). It is `null` when unknown,
     or when an approximate distance is under 1 km.
   - `location_label`: `"Sanganer, Jaipur"`, or `"Jaipur"` while the area is
     unknown. Also sent: `area` and `distance_km`.

   Find Workers measures from the point the app sends (else the employer's pin
   or city). Matches and applicants measure from the job's site.
3. **Unlocked by.** `unlocked_by_count` = how many **other** employers unlocked
   this karigar in the Worker Database. Show it when it is above 0.

Today almost every karigar is pinned at their city's centre, so most cards
will read "~4 km · Jaipur" until karigars add their area (worker app, same
date). Exact distances appear as they do.

## What to build

1. Card line: 📍 `distance_label` · `location_label`. Mark an approximate
   distance subtly ("~" is already in the label).
2. Card / profile: "Unlocked by N other HRs" when `unlocked_by_count > 0`.
3. A 👎 **Not interested** button on Find Workers cards and the profile. On 200,
   remove the card. Add **All / Rejected (N)** tabs; a rejected card has
   **Undo**.
4. Applicant cards: the same distance line.
