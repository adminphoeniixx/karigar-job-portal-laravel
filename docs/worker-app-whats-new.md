# Karigar **Worker app** — what's new

Everything the **worker** app needs to integrate since the last handover.
Nothing here concerns the employer app.

Full reference: `docs/worker-app-api.md` ·
Postman: `docs/karigar-worker-app.postman_collection.json`

Base URL `{{base_url}}/api/v1`. Auth unchanged — Sanctum bearer token from OTP
verify, sent as `Authorization: Bearer <token>`. 🔒 = auth required.

**New endpoints for this app: 8.** Plus two new fields on a response you already
parse, and one behaviour change that needs handling.

---

## 1. Resume upload 🔒 — the big one

Brand new. There was no resume anywhere in the app before.

The AI scores every application the moment it is submitted, and it **reads the
worker's resume** when one exists. So uploading a resume directly changes the
match score the employer sees, and whether the worker gets shortlisted. Worth a
visible prompt on the profile screen — it is the single highest-value thing a
worker can do to improve their chances.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/worker/resume` | Current resume, or `null` |
| `POST` | `/worker/resume` | Upload / replace (**multipart**) |
| `DELETE` | `/worker/resume` | Remove |

### GET

```jsonc
// none uploaded
{ "resume": null }

// uploaded
{ "resume": { "name": "suresh-plumber.pdf",
              "uploaded_at": "2026-07-30T05:20:11+00:00",
              "uploaded_ago": "2 minutes ago",
              "characters": 1660, "max_characters": 8000 } }
```

`characters` is how much text we actually pulled out of the PDF. Surfacing it
("1,660 characters read from your resume") reassures the worker it worked —
otherwise they have no way to know we could read the file.

### POST — `multipart/form-data`, single field `resume`

- **PDF only**, max **4 MB**.
- Uploading again **replaces** the old one. No need to DELETE first.
- `201` on success, returning the same `resume` object as GET.

Three ways it can fail, all `422`:

```jsonc
// not a PDF
{ "message": "Please upload your resume as a PDF.",
  "errors": { "resume": ["Please upload your resume as a PDF."] } }

// over 4 MB
{ "errors": { "resume": ["Your resume must be smaller than 4 MB."] } }

// a PDF, but with no readable text  ← design for this one
{ "message": "We could not read any text in that PDF. If it is a scan or photo, please upload a text PDF instead.",
  "errors": { "resume": ["No readable text found in the PDF."] } }
```

**That last case will be common.** Workers photograph a paper resume and save it
as a PDF. There is no text layer in it, the AI cannot read it, so we refuse the
upload rather than silently storing something useless. Show the message as-is —
it tells them exactly what to do instead.

### DELETE

```jsonc
{ "message": "Resume removed.", "resume": null }
```
Drops the file and the extracted text. Later applications get scored on the
profile fields alone.

### Privacy

The PDF is stored on a **private** disk, same as KYC documents. There is no
public URL and none is returned — by design. Only an employer the worker has
actually applied to can fetch the file. Don't build any "share my resume" link.

---

## 2. Chat with employers 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/conversations` | Thread list + `unread_total` |
| `POST` | `/conversations` | Open (or reuse) a thread |
| `GET` | `/conversations/{id}` | Latest 30 messages, marks the thread read |
| `POST` | `/conversations/{id}/messages` | Send a message |
| `POST` | `/conversations/{id}/read` | Mark read |

```jsonc
// POST /conversations
{ "employer_id": 8, "job_id": 3, "body": "Hi, is this still open?" }
// → 201 (new) or 200 (existing thread)
{ "conversation": { "id": 4, "other_party": {...}, "job": {...},
                    "unread": 0, "last_message_at": "..." } }

// POST /conversations/{id}/messages
{ "body": "Yes, I can start Monday." }
// → 201
{ "message": { "id": 88, "body": "...", "mine": true,
               "read_at": null, "created_at": "..." } }
```

Rules to build against:

- **A worker can only message an employer they have applied to.** Anything else
  returns `422` with `"code": "chat_not_allowed"`. So the chat button belongs on
  the application/job screens, not on a general employer profile.
- `unread` counts messages the **other side** sent.
- New messages arrive as an FCM push, `type: "chat.message"`, carrying
  `conversation_id` and `url` — reuse the existing push handler.
- `GET /conversations/{id}` marks the thread read as a side effect. Don't call
  it just to refresh a badge; use `GET /conversations` for that.

---

## 3. App settings 🔒

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/preferences` | Theme + alert toggles |
| `PUT\|PATCH` | `/preferences` | Any subset of the keys |

```jsonc
{ "preferences": { "theme": "system", "applicant_alerts": true,
                   "job_alerts": true, "message_alerts": true } }
```

`theme` is `system \| light \| dark`. Send only the keys you are changing.
For the worker app the ones that matter are `job_alerts` and `message_alerts`
(`applicant_alerts` is an employer concept and can be ignored).

---

## 4. Signed-in devices 🔒

For the "Login & security" screen.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/auth/sessions` | Devices holding a token |
| `DELETE` | `/auth/sessions/{token}` | Sign that device out |

```jsonc
{ "sessions": [ { "id": 27, "device": "Pixel 8", "current": true,
                  "last_used_ago": "2 minutes ago",
                  "last_used_at": "...", "created_at": "..." } ] }
```

`404` if the token isn't theirs. Deleting the session with `"current": true`
logs **this** device out — confirm before calling it.

There is no password/2FA screen: workers are OTP-only, by design.

---

## 5. New fields on `ApplicationResource`

Additive, so nothing breaks — but the applications screen won't show them until
you read them. Both are `null` until the employer acts.

```jsonc
// set once the employer schedules an interview
"interview": { "at": "2026-08-02T05:00:00+00:00",
               "at_label": "02 Aug 2026, 10:30 AM",
               "mode": "site", "note": "Gate 2, ask for Anil" }

// set once the employer hires them
"offer": { "wage": "900.00", "start_date": "2026-08-05",
           "message": "Report by 9 AM" }
```

`mode` is `site \| phone \| video`. `wage` is a **string** — parse it.

These are what make the "you have an interview" and "you got the job" states on
the applications screen real, rather than just a status label.

---

## 6. Behaviour change: an application can move on its own

Two admin-controlled features (both **off by default**, so you won't see them
until they're switched on) let the backend change an application **with no
employer action at all**:

- a strong match can become **shortlisted** automatically
- a poor match can become **rejected** automatically

For the worker app this means: **an application can go to `rejected` without the
employer ever opening it**, within seconds of applying.

You do not need a new endpoint for this. The existing `application.rejected` /
`application.accepted` FCM push and the `status` field already carry it. What
you must do:

- Reflect a status change that arrives **while the app is open** — don't cache
  the applications list for the session.
- Don't write copy that assumes a human read the application. "Not selected"
  works; "the employer reviewed and declined" may not be true.

---

## 7. Integration checklist

- [ ] Resume: upload / replace / remove on the profile screen
- [ ] Resume: the "no readable text in PDF" `422` handled with its own message
- [ ] Resume: show `characters` so the worker knows it was read
- [ ] Prompt workers without a resume — it measurably changes their score
- [ ] Chat: thread list, thread view, send, unread badge
- [ ] Chat: FCM `chat.message` handled; button only where they've applied
- [ ] Settings: theme + `job_alerts` / `message_alerts`
- [ ] Login & security: device list + sign-out-device (confirm on current)
- [ ] Applications: `interview` and `offer` blocks
- [ ] Applications: status refreshed live, not cached

---

## 8. Gotchas that have cost us time before

- **OTP is 4 digits**, not 6. The mockups draw six boxes; the backend issues four.
- Resume upload is `multipart/form-data`. **Don't set `Content-Type` by hand** —
  let the HTTP client set the multipart boundary.
- Send `Accept: application/json` on **every** call. Without it a validation
  failure comes back as a redirect instead of a `422`.
- Reference lists (`skills`, `spoken_languages`, `education_levels`, `states`,
  `cities`, `job_categories`) come from `GET /reference` — read them from the
  API, they change without an app release.
- Money fields arrive as **strings** (`"800.00"`). Parse, don't concatenate.

Questions on any payload: `docs/worker-app-api.md` has the full shapes, and the
Postman collection is importable and current.
