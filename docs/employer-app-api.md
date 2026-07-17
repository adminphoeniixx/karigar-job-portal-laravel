# Karigar — Employer App API (v1)

Token-based JSON API for the **employer** mobile app. Same stack as the worker
API — **Laravel Sanctum** personal access tokens issued after mobile-OTP
verification. Additive and independent of the web (Inertia/Fortify) app.

- **Base URL:** `https://<host>/api/v1`
- **Auth header:** `Authorization: Bearer <token>`
- **Content type:** `application/json` (file uploads use `multipart/form-data`)
- **Always send:** `Accept: application/json`

Validation errors return `422` with `{ "message": ..., "errors": { field: [msg] } }`.
Auth failures `401`; role/permission failures `403`. 🔒 = requires a token.
All `employer/*` routes require the token's user to have the **employer** role.

Jobs, applicants, contact-unlock quota and reviews are scoped to the
**employer account** (owner + team members see the same data), exactly like the web.

---

## 1. Auth (public/shared)

Same endpoints as the worker app — pass `role: "employer"` when verifying.

### `POST /auth/otp/send`
```json
// body → 200
{ "phone": "9876543210" }
{ "message": "OTP sent successfully", "cooldown": 30 }
```

### `POST /auth/otp/verify`
Verify OTP → issue a token. Registers the account (and an empty employer profile)
on first login.
```json
// body
{ "phone": "9876543210", "otp": "4821", "role": "employer", "device_name": "iPhone 15" }
// 200
{
  "token": "12|xxxxxxxx...",
  "is_new": true,
  "needs_registration": true,   // route new employers into the "Set up your business" wizard
  "user": { "id": 8, "name": "Employer 3210", "phone": "9876543210", "role": "employer",
            "locale": "en", "avatar_url": null, "company_name": null,
            "rating": { "average": 0, "count": 0 } }
}
```

### `POST /auth/logout` 🔒 → `{ "message": "Logged out." }`
### `GET /auth/me` 🔒 → `{ "user": { ...UserResource }, "unread_notifications": 3 }`
### `DELETE /account` 🔒 — body `{ "confirm": true }`, revokes all tokens.
### `POST /locale` 🔒 — body `{ "locale": "hi" }`, updates app language.

---

## 2. Reference data (public)
`GET /reference` now also returns the employer-app dropdowns:
```json
{
  "states": [...], "skills": [...], "job_categories": [...],
  "wage_types": ["hourly", "daily", "monthly"],
  "shifts": ["day", "night", "rotational"],
  "perks": ["Food", "Accommodation", "Travel allowance", "Bonus", "Overtime pay", "Weekly off"],
  "contact_modes": ["apply", "call", "both"],
  "industries": ["Construction & Real Estate", "Interiors & Furnishing", ...],
  "company_sizes": ["1–10", "11–50", "51–200", "200+"],
  "app_languages": [...]
}
```
`GET /reference/cities?state=Tamil%20Nadu` → `{ "cities": [...] }`

---

## 3. Dashboard (home screen) 🔒

### `GET /employer/dashboard`
Everything the home screen needs in one call.
```json
{
  "greeting": "Sri Sai Constructions",
  "profile": { ...EmployerProfileResource },
  "stats": {
    "active_jobs": 4, "total_applicants": 37, "shortlisted": 9, "hired": 5,
    "unread_notifications": 3, "verified": true, "profile_completion": 88
  },
  "active_jobs": [ { ...EmployerJobResource } ],       // up to 5 active jobs
  "recent_applicants": [ { ...ApplicantResource } ]    // up to 5 newest
}
```

---

## 4. Business profile 🔒

### `GET /employer/profile` → `EmployerProfileResource`
```json
{
  "id": 3, "name": "Anil Sharma", "company_name": "Sri Sai Constructions",
  "gstin": "22ABCDE1234F1Z5", "phone": "9876543210", "about": "...",
  "address": "...", "city": "Chennai", "state": "Tamil Nadu",
  "location_label": "Chennai, Tamil Nadu", "latitude": 13.08, "longitude": 80.27,
  "logo_url": "https://.../storage/logos/x.jpg", "verified": true,
  "rating": { "average": 4.6, "count": 18 }, "completion": 88
}
```

### `PUT|PATCH /employer/profile`
Create/update the business profile — also used to finish the registration wizard.
`name` is the contact person (saved on the user); everything else on the profile.
```json
// body (all optional)
{ "name": "Anil Sharma", "company_name": "Sri Sai Constructions",
  "phone": "9876543210", "about": "...", "address": "...",
  "city": "Chennai", "state": "Tamil Nadu", "latitude": 13.08, "longitude": 80.27,
  "gstin": "22ABCDE1234F1Z5" }
// 200 → EmployerProfileResource
```

### `POST /employer/profile/logo` — `multipart/form-data`, field `logo` (image ≤2 MB)
→ `{ "logo_url": "https://.../storage/logos/x.jpg" }`

---

## 5. Jobs 🔒

**EmployerJobResource** includes the full editable field set plus
`status`, `status_label`, `wage_label`, `location_label` and
`stats: { applicants, shortlisted, hired }`.

### `GET /employer/jobs?status=active|closed|draft&q=<search>`
Paginated (15/page), newest first. `status`/`q` optional (the My Jobs tabs).

### `GET /employer/jobs/{job}` → `EmployerJobResource`

### `POST /employer/jobs`
Post a job (or save a draft with `status: "draft"`). Notifies matching workers
when `status: "active"`. Returns `422` if the plan's job-post limit is reached.
```json
// body
{
  "title": "Plumber for Apartment Project",
  "description": "12-floor residential project...",
  "category": "Plumbing", "skills": ["Plumbing", "Pipe Fitting"],
  "wage_min": 800, "wage_max": 1000, "wage_type": "daily",
  "city": "Chennai", "state": "Tamil Nadu", "latitude": 13.08, "longitude": 80.27,
  "vacancies": 3, "shift": "day", "perks": ["Food", "Accommodation"],
  "contact_mode": "both", "contact_phone": "9876543210",
  "requires_worker_fee": false, "status": "active"
}
// 201 → { "message": "Job posted.", "job": { ...EmployerJobResource } }
```
Rules: `title` req; `description` req ≤5000; `vacancies` req ≥1;
`wage_type` in hourly/daily/monthly; `wage_max` ≥ `wage_min`;
`shift` in day/night/rotational; `contact_mode` in apply/call/both
(`contact_phone` required for call/both); `status` in draft/active/closed;
`perks.*` from the reference `perks` list.

### `PUT|PATCH /employer/jobs/{job}` — same body → `{ "message", "job" }`
### `POST /employer/jobs/{job}/close` → sets status `closed`, `{ "message", "job" }`
### `DELETE /employer/jobs/{job}` → `{ "message": "Job deleted." }`

---

## 6. Applicants 🔒

**ApplicantResource** carries the application (`status`, `stage`, `shortlisted`,
`cover_note`, `expected_wage`, `contact_unlocked`) and an embedded `worker`
summary. Worker `phone`/`email` are `null` until the contact is unlocked.
`stage` is one of `pending | shortlisted | hired | rejected`.

### `GET /employer/jobs/{job}/applicants?stage=all|pending|shortlisted|hired|rejected`
Paginated (20/page). Response `additional`:
```json
{
  "data": [ { ...ApplicantResource } ],
  "counts": { "all": 12, "pending": 8, "shortlisted": 3, "hired": 1, "rejected": 0 },
  "links": {...}, "meta": {...}
}
```

### `GET /employer/applicants/{application}` → single `ApplicantResource`

### `PATCH /employer/applicants/{application}/status`
Hire or reject — notifies the worker (in-app + email).
```json
// body → 200
{ "status": "accepted" }   // or "rejected"
{ "message": "Applicant Accepted.", "applicant": { ...ApplicantResource } }
```

### `POST /employer/applicants/{application}/shortlist`
Toggle shortlist. Shortlisting notifies the worker.
→ `{ "message", "applicant": { ...ApplicantResource } }`

### `POST /employer/applicants/{application}/unlock`
Reveal the worker's contact, consuming one plan unlock. `422` if the plan's
unlock limit is reached.
→ `{ "message": "Contact unlocked.", "applicant": { ...ApplicantResource } }`

---

## 7. Find Workers 🔒
Typesense-powered directory. Rows beyond the plan's contact quota are returned
`locked: true` with `phone: null`.

### `GET /employer/workers?q=&state=&city=&skill=&page=`
```json
{
  "workers": { "data": [ { "id", "user_id", "name", "avatar_url", "bio",
      "skills": [...], "city", "state", "experience_years", "expected_wage",
      "wage_type", "available", "verified", "rating", "phone", "locked" } ],
    "links": {...}, "meta": {...} },
  "filters": { "q": null, ... },
  "access": { "quota": 25, "accessible": 6, "total": 6, "has_plan": true }
}
```

### `GET /employer/workers/{worker}`
`{worker}` is a **worker profile id**. Contact revealed only if this employer
has unlocked the worker through any application.
```json
{
  "worker": { "id", "user_id", "name", "avatar_url", "bio", "skills": [...],
    "spoken_languages": [...], "city", "state", "experience_years", "education",
    "expected_wage", "wage_type", "available", "verified",
    "phone": null, "email": null, "contact_unlocked": false },
  "rating": { "average": 4.8, "count": 23 },
  "reviews": [ { ...ReviewResource } ]
}
```

---

## 8. Business verification (KYC) 🔒
GSTIN is stored on the profile; business PAN + proof docs on the shared KYC record.

### `GET /employer/kyc`
→ `{ "gstin": "22ABCDE1234F1Z5", "kyc": { "status": "verified", "status_label": ...,
     "masked_pan": "ABCXX1234F", "remarks": null, ... } | null }`

### `POST /employer/kyc` — `multipart/form-data`
```
gstin=22ABCDE1234F1Z5   pan_number=ABCDE1234F
gst_doc=@gst.pdf        pan_doc=@pan.jpg      (jpg/png/pdf ≤5 MB; docs optional on re-submit)
// 201 → { "message": "Business verification submitted for review.", "gstin", "kyc": {...} }
```

---

## 9. Notifications 🔒 (shared with worker app)

### `GET /notifications` → `{ "notifications": { paginated }, "unread": 3 }`
### `POST /notifications/{id}/read` → `{ "unread": 2 }`
### `POST /notifications/read-all` → `{ "unread": 0 }`

---

## 10. Reviews 🔒

### `GET /employer/reviews`
Reviews workers left for this employer, paginated (15/page).
```json
{
  "data": [ { "id", "rating": 5, "comment": "...", "created_ago": "1 month ago",
              "reviewer": { "id", "name" }, "job": { "id", "title" } } ],
  "summary": { "average": 4.6, "count": 18 },
  "links": {...}, "meta": {...}
}
```

### `POST /employer/applicants/{application}/review`
Rate a **hired** worker (application must be `accepted`; one review per worker+job).
```json
// body → 201
{ "rating": 5, "comment": "Neat work, finished ahead of time." }
{ "message": "Review submitted.", "review": { ...ReviewResource } }
```

---

## 11. Team members 🔒 (account owner only)
Owner invites staff by mobile number; they log in with their own OTP.
Roles: `manager` (posts & manages jobs) · `recruiter` (works applicants only).
Non-owners get `403`.

### `GET /employer/team`
→ `{ "members": [ { "id", "name", "phone", "role", "added_ago" } ], "roles": ["manager", "recruiter"] }`

### `POST /employer/team`
```json
// body → 201
{ "name": "Ravi", "phone": "9876500000", "role": "recruiter" }
{ "message": "Team member added — they can log in with their mobile number.",
  "member": { "id", "name", "phone", "role", "added_ago" } }
```
`422` if the number is your own, belongs to a worker, already runs/joins a team,
or already has its own job posts.

### `PATCH /employer/team/{member}` — body `{ "role": "manager" }` → `{ "message", "role" }`
### `DELETE /employer/team/{member}` → `{ "message": "Team member removed." }`

---

## Not included (out of scope for this API pass)
These employer-app screens have **no web backend** either, so no endpoint exists yet:
- **Boost job / Billing & Plans** — subscription/Razorpay surface (separate payment flow).
- **Login & security → device sessions** — employers are OTP-only (no passwords).
- **Applicant-alert / dark-theme toggles** — client-side prefs, no stored setting.
- **Job views count** — not tracked in the schema (`stats` covers applicants/shortlisted/hired only).
