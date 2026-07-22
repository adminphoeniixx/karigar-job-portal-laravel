# App changelog — Email field + Application Tracker

**Date:** 2026-07-21
**Audience:** Mobile app developer (worker app; employer app where noted)
**Scope:** Two changes only — (1) a real **email** field on profiles, (2) a parcel-style **application status tracker**. Nothing else in the API contract changed.

---

## 1. Real email field (profile)

### Why
Users who sign up via phone-OTP get a placeholder email `<phone>@phone.karigar`, which is not a real inbox. The backend now **skips sending email** to any `@phone.karigar` address, so users only receive email notifications once they set a **real email**. The app must let them enter it.

### API

**`PATCH /api/v1/worker/profile`** — accepts one new optional field:

| field | rules |
|-------|-------|
| `email` | nullable, valid email, **unique** across all users |

- Duplicate email → `422` validation error (`errors.email`).
- Stored on the user record (like `name`), not on the profile object.

**`GET /api/v1/worker/profile`** (`WorkerProfileResource`) now returns:

```json
{ "email": "rakesh@example.com", ... }
```

> ⚠️ If the user still has the placeholder, `email` comes back as **`null`**. Treat `null` as "not set yet" — show an empty field, not the placeholder.

### UI (see `app-mockup/worker-app.html`)
- **Edit Profile** and **Registration** screens: add an optional **Email** input with a hint like *"Add a real email to get job & application updates by email."*
- Send it in the same `PATCH /worker/profile` call.

---

## 2. Application status tracker (parcel-style)

### API — new response fields

`ApplicationResource` — returned by:
- `GET /api/v1/worker/applications` (list)
- `POST /api/v1/jobs/{job}/apply` (single, inside `application`)

Two new fields:

| field | type |
|-------|------|
| `status_changed_at` | ISO8601 string or `null` — when employer accepted/rejected (or worker withdrew) |
| `tracking_steps` | array of 4 step objects (below) |

**`tracking_steps`** — always 4 steps, fixed order:

```json
"tracking_steps": [
  { "key": "applied",     "state": "done",     "at": "2026-07-17T14:30:00+05:30", "result": null },
  { "key": "review",      "state": "done",     "at": null,                        "result": null },
  { "key": "shortlisted", "state": "done",     "at": "2026-07-18T09:00:00+05:30", "result": null },
  { "key": "decision",    "state": "done",     "at": "2026-07-19T11:00:00+05:30", "result": "accepted" }
]
```

| key (order) | field | meaning |
|-------------|-------|---------|
| `applied` → `review` → `shortlisted` → `decision` | `key` | step id; map to a localized label in the app |
| | `state` | one of `done` \| `current` \| `upcoming` \| `rejected` \| `skipped` |
| | `at` | ISO8601 timestamp for that step, or `null` |
| | `result` | only on the `decision` step → `accepted` \| `rejected` \| `withdrawn` \| `null` |

**Rendering rules**
- Icon/colour comes from `state`:
  - `done` → green, ✓
  - `current` → orange, ● (pulsing "in progress")
  - `rejected` → red, ✕ (terminal — decision step when not selected)
  - `upcoming` → grey outline (not reached yet)
  - `skipped` → grey (e.g. accepted/rejected without being shortlisted)
- Label comes from `key`. Suggested labels:
  - `applied` = "Applied", `review` = "Under review", `shortlisted` = "Shortlisted", `decision` = "Decision"
  - On `decision`, override label by `result`: `accepted` → "Selected 🎉", `rejected` → "Not selected", `withdrawn` → "Withdrawn".
- Sub-text: show `at` (formatted date/time) when present; else "in progress" for `current`, "—/Pending" for `upcoming`.

**Employer app:** the same `tracking_steps` array is now also on `ApplicantResource` (employer applicants endpoints), if the employer app wants to show the timeline per applicant.

### UI (see `app-mockup/worker-app.html`)
- **My Applications**: each application card shows a **4-step vertical timeline** (Applied → Under review → Shortlisted → Decision) with coloured dots, connector lines, and the date on each completed step.
- Example JS render lives in `worker-app.html` (`trackerHtml()` function) — use it as a visual reference.

---

## Backend reference (for context)
- Single source of truth: `App\Models\JobApplication::trackingSteps()`.
- New DB column: `job_applications.status_changed_at` (already migrated).
- Localized labels for web live under the `tracker.*` i18n key (all 8 languages) — mirror the same labels in the app.

## Still pending
- Employer-app **mockup** (`employer-app.html`) tracker UI is **not** added yet (API side is ready). Ask if needed.
