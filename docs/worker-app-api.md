# Karigar — Worker App API (v1)

Token-based JSON API for the worker mobile app. Auth is via **Laravel Sanctum**
personal access tokens (issued after mobile-OTP verification). The web app keeps
using Inertia/Fortify sessions — this API is additive and independent.

- **Base URL:** `https://<host>/api/v1`
- **Auth header:** `Authorization: Bearer <token>`
- **Content type:** `application/json` (file uploads use `multipart/form-data`)
- **Always send:** `Accept: application/json`

Validation errors return `422` with `{ "message": ..., "errors": { field: [msg] } }`.
Auth failures return `401`; role/permission failures `403`.

---

## 1. Auth (public)

### `POST /auth/otp/send`
Send/resend a 4-digit OTP (MSG91). In dev (no MSG91 keys) the OTP is written to
`storage/logs`.
```json
// body
{ "phone": "9876543210" }
// 200
{ "message": "OTP sent successfully", "cooldown": 30 }
```
Rate limited: 3/min per phone+IP (plus route throttle 6/min).

### `POST /auth/otp/verify`
Verify OTP → issue a token. Registers the account on first login.
```json
// body
{ "phone": "9876543210", "otp": "4821", "role": "worker", "device_name": "Pixel 8" }
// 200
{
  "token": "12|xxxxxxxx...",
  "is_new": true,
  "needs_registration": true,     // route new workers into the signup wizard
  "user": { "id": 5, "name": "Worker 3210", "phone": "9876543210", "role": "worker", "locale": "en", "avatar_url": null, "rating": { "average": 0, "count": 0 } }
}
```

### `POST /auth/logout` 🔒
Revoke the current token. → `{ "message": "Logged out." }`

### `GET /auth/me` 🔒
```json
{ "user": { ...UserResource }, "unread_notifications": 2 }
```

### `DELETE /account` 🔒
Permanently delete the account (workers have no password, so a confirmation flag
is required). Revokes all tokens.
```json
// body
{ "confirm": true }
// 200
{ "message": "Your account has been deleted." }
```

---

## 2. Reference data (public)
For registration dropdowns/chips and job filters. Cache on first launch.

### `GET /reference`
```json
{
  "states": ["Andhra Pradesh", ...],
  "skills": ["Plumbing", "Electrician", ...],
  "spoken_languages": ["Hindi", "English", "Tamil", ...],
  "education_levels": ["Below 10th", "10th Pass", "12th Pass", "ITI / Diploma", "Graduate", "Post Graduate"],
  "wage_types": ["hourly", "daily", "monthly"],
  "app_languages": [{ "code": "en", "native": "English", "english": "English" }, ...],
  "job_categories": ["Plumbing", "Electrical", ...]
}
```

### `GET /reference/cities?state=Tamil%20Nadu`
`{ "cities": ["Chennai", "Coimbatore", ...] }`

### `GET /reference/job-categories`
`{ "job_categories": [...] }`

---

## 3. Registration & Profile 🔒 (worker)

The signup wizard (location → spoken languages → education → skills → optional KYC)
is just repeated calls to **`PATCH /worker/profile`** with whatever fields the step
collected, then optionally `POST /kyc`.

### `GET /worker/profile`
Returns `WorkerProfileResource` (see below), including `completion` (0–100).

### `PUT|PATCH /worker/profile`
Any subset of these fields (JSON):
```json
{
  "name": "Rakesh Kumar",
  "email": "rakesh@example.com",          // real email for job/application updates; must be unique
  "gender": "male",                       // male | female | other
  "skills": ["Plumbing", "Tiling"],
  "experience_years": 6,
  "education": "12th Pass",               // must be one of education_levels
  "spoken_languages": ["Hindi", "Tamil"],
  "bio": "6 years experience...",
  "expected_wage": 900,
  "wage_type": "daily",                   // hourly | daily | monthly
  "city": "Chennai",
  "state": "Tamil Nadu",
  "latitude": 13.0827,
  "longitude": 80.2707,
  "travel_radius_km": 15,
  "available": true,
  "payout_upi": "rakesh@okhdfcbank"
}
```
→ returns the updated `WorkerProfileResource`.

### `POST /worker/profile/avatar` (multipart)
Field `avatar` (image, ≤2 MB). → `{ "avatar_url": "https://.../storage/avatars/x.jpg" }`

### `PATCH /worker/availability`
`{ "available": false }` → `{ "available": false }`

**WorkerProfileResource**
```json
{
  "id": 3, "name": "Rakesh Kumar", "email": "rakesh@example.com", "phone": "9876543210", "gender": "male",
  "skills": ["Plumbing"], "experience_years": 6, "education": "12th Pass",
  "spoken_languages": ["Hindi","Tamil"], "bio": "...", "expected_wage": "900.00",
  "wage_type": "daily", "city": "Chennai", "state": "Tamil Nadu",
  "latitude": 13.0827, "longitude": 80.2707, "travel_radius_km": 15,
  "available": true, "payout_upi": "rakesh@okhdfcbank",
  "avatar_url": null, "completion": 70
}
```

---

## 4. Jobs 🔒 (worker)

### `GET /jobs`
Filters (all optional): `q, state, city, category, skill, lat, lng, radius`.
Typesense-backed, paginated (15/page).
```json
{ "data": [ { ...JobResource } ], "links": {...}, "meta": {...} }
```
**JobResource:** `id, title, category, skills, city, state, location_label,
wage_min, wage_max, wage_type, wage_label ("₹800–1000 / daily"), vacancies,
created_at, created_ago, expires_at, employer{id,name}`.

### `GET /jobs/{job}`
Full detail + the worker's context.
```json
{
  "data": { ...JobDetailResource, "contact_phone": "98..." /* only if callable */ },
  "meta": {
    "employer_rating": { "average": 4.7, "count": 12 },
    "application": { "status": "pending", "status_label": "Pending", "created_ago": "2 hours ago" },
    "is_saved": false,
    "can_apply": true
  }
}
```

---

## 5. Applications 🔒 (worker)

### `GET /worker/applications?status=pending`
`status` optional (`pending|accepted|rejected|withdrawn`). Paginated
`ApplicationResource` list.

**`ApplicationResource`** now also returns `status_changed_at` and a
`tracking_steps` array for the parcel-style status tracker:
```json
"tracking_steps": [
  { "key": "applied",     "state": "done",     "at": "2026-07-17T14:30:00+05:30", "result": null },
  { "key": "review",      "state": "done",     "at": null, "result": null },
  { "key": "shortlisted", "state": "done",     "at": "2026-07-18T09:00:00+05:30", "result": null },
  { "key": "decision",    "state": "done",     "at": "2026-07-19T11:00:00+05:30", "result": "accepted" }
]
```
`state`: `done | current | upcoming | rejected | skipped`. On the `decision`
step, `result` is `accepted | rejected | withdrawn | null`. The app maps each
`key` to a localized label and renders the icon from `state`.

### `POST /jobs/{job}/apply`
```json
{ "cover_note": "Available from tomorrow", "expected_wage": 900 }
// 201
{ "message": "Application submitted.", "application": { ...ApplicationResource } }
```
Notifies the employer + sends both transactional emails (same as web).
409-style guard: re-applying returns `422` "You have already applied...".

### `DELETE /applications/{application}`
Withdraw your own application. → `{ "message": "Application withdrawn." }`

---

## 6. Saved jobs 🔒 (worker)

- `GET /worker/saved` → paginated `SavedJobResource` (each embeds a `JobResource`).
- `POST /jobs/{job}/save` → toggle. `{ "saved": true, "message": "Saved." }`

---

## 7. KYC 🔒 (worker, **optional**)

### `GET /kyc`
`{ "kyc": null }` or `{ "kyc": { "status": "pending", "masked_pan": "ABXXXXX1", "masked_aadhaar": "XXXX XXXX 9012", "remarks": null, ... } }`

### `POST /kyc` (multipart)
Fields: `pan_number` (ABCDE1234F), `aadhaar_number` (12 digits),
`pan_doc`, `aadhaar_doc` (jpg/png/pdf ≤4 MB; required on first submit).
→ `201 { "message": "KYC submitted for review.", "kyc": {...} }`. Raw numbers are
encrypted at rest and never returned.

---

## 8. Notifications 🔒 (any auth)

- `GET /notifications` → `{ "notifications": { paginated [{id,type,message,url,read,created_at,created_ago}] }, "unread": 2 }`
- `POST /notifications/{id}/read` → `{ "unread": 1 }`
- `POST /notifications/read-all` → `{ "unread": 0 }`

### Push notification device tokens

Register the device's FCM token so it receives push notifications (application status, new jobs, shortlist, admin broadcasts). Call after login and whenever FCM rotates the token; remove on logout.

#### `POST /device-tokens` 🔒

```json
{ "token": "<FCM_DEVICE_TOKEN>", "platform": "android" }
```

- `token` — **required**, the FCM registration token from the device.
- `platform` — optional, one of `android` | `ios` | `web`.

Idempotent (`updateOrCreate` on the token). → `{ "registered": true }`

#### `DELETE /device-tokens` 🔒

```json
{ "token": "<FCM_DEVICE_TOKEN>" }
```

Removes the token so the device stops receiving pushes. → `{ "removed": true }`

---

## 9. Reviews 🔒 (worker)

- `GET /worker/reviews` → paginated `ReviewResource` (reviews received) + `"summary": { "average": 4.8, "count": 23 }`.
- `POST /applications/{application}/review` — rate the employer of an **accepted** application.
  ```json
  { "rating": 5, "comment": "Great to work with" }
  // 201 { "message": "Review submitted.", "review": {...} }
  ```

---

## 10. Dashboard & Locale 🔒 (worker)

### `GET /worker/dashboard`
Everything the home screen needs:
```json
{
  "greeting": "Rakesh Kumar",
  "profile": { ...WorkerProfileResource },
  "stats": {
    "available_jobs": 128, "applications": 3, "saved_jobs": 4,
    "kyc_status": "not_submitted", "kyc_status_label": "Not submitted",
    "profile_completion": 70, "unread_notifications": 2
  },
  "latest_jobs": { "data": [ { ...JobResource } ] }
}
```

### `POST /locale`
`{ "locale": "hi" }` → `{ "locale": "hi", "supported": [...] }`

---

## Notes / setup

1. **Run migrations** (adds `personal_access_tokens` + worker-profile fields
   `gender, education, spoken_languages, travel_radius_km`):
   ```
   php artisan migrate
   ```
2. OTP length is **4 digits** (MSG91 flow), matching the web login.
3. Job search needs the Typesense server (same as the web browse page).
4. Suspended accounts are blocked at OTP verify (`403`).
5. Auth guard `sanctum` is registered in `config/auth.php`.
