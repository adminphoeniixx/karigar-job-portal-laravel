# Super Karigar **Worker app** — two fixes for the app developer

Handover note for **two issues** found on the worker app. Both are **app-side
only** — the backend already sends everything needed and nothing on the server
has to change.

Base URL `{{base_url}}/api/v1`. Auth: Sanctum bearer token, sent as
`Authorization: Bearer <token>`. 🔒 = auth required.

Full API reference: `docs/worker-app-api.md` ·
Postman: `docs/karigar-worker-app.postman_collection.json`

---

## 1. Job Details — the **Wage** value is blank

**Screen:** Job Details (`GET /jobs/{job}` 🔒).

**Bug:** The "Wage" card shows only the label — the amount underneath is empty,
while the other three cards (Openings, Location, Shift) render fine.

**Cause:** The Job Details response did not carry a ready wage string, and the
app was reading a single `wage`/`salary` key that does not exist, so it rendered
nothing.

### Fixed on the backend — just read `wage_label`

`GET /jobs/{job}` (and the job **list** `GET /jobs`) now include a
**pre-formatted** `wage_label` alongside the raw fields. Show that string
directly in the card — **no formatting logic needed in the app.**

```jsonc
{
  "wage_min": "900.00",     // raw, string or null  (kept for filters)
  "wage_max": "1200.00",    // raw, string or null
  "wage_type": "monthly",   // raw, string or null
  "wage_label": "₹900 – ₹1,200 / monthly"   // 👈 display this
}
```

`wage_label` already handles every case for you:

| Situation | `wage_label` value |
| --- | --- |
| min and max, different | `₹900 – ₹1,200 / monthly` |
| min and max, equal | `₹900 / monthly` |
| only one of them set | `₹500 / monthly` |
| both null (no wage set) | `Not disclosed` |

So the whole fix is: bind the Wage card to `wage_label`. It is always a
non-empty string, so the card can never go blank again — jobs with no wage
(like the Beautician job in the report) now read **"Not disclosed"**.

---

## 2. Apply screen + Profile — **no resume upload** anywhere

**Screens:** "Apply for this job" sheet, and the worker Profile screen.

**Gap:** There is no way for a worker to attach or upload a resume anywhere in
the app. The backend has full resume support and the **AI reads the resume when
scoring every application** — a worker with a resume gets a better match score
and is more likely to be shortlisted. So this is high-value, not cosmetic.

### Important: resume is on the **profile**, not the application

The resume is uploaded **once to the worker's profile** and is then
automatically read for **every** job they apply to. Do **not** add a file
picker to the per-job Apply sheet — the apply request stays exactly as it is:

```
POST /jobs/{job}/apply 🔒
{ "cover_note": "...", "expected_wage": 900 }   // no file, no change
```

Instead, add a **Resume** section on the **Profile** screen, plus an optional
nudge on the Apply sheet ("Add a resume to improve your match" → deep-link to
the profile resume section).

### Resume endpoints (already live 🔒)

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/worker/resume` | Current resume, or `null` |
| `POST` | `/worker/resume` | Upload / replace (**multipart/form-data**) |
| `DELETE` | `/worker/resume` | Remove |

**GET** — current state:

```jsonc
// none uploaded
{ "resume": null }

// uploaded
{ "resume": {
    "name": "suresh-plumber.pdf",
    "uploaded_at": "2026-07-30T05:20:11+00:00",
    "uploaded_ago": "2 minutes ago",
    "characters": 1660,        // text we actually parsed from the PDF
    "max_characters": 8000
} }
```

Surface `characters` ("1,660 characters read from your resume") so the worker
knows the file was parsed successfully — otherwise they can't tell it worked.

**POST** — `multipart/form-data`, single field named `resume`:

- **PDF only**, max **4 MB**.
- Uploading again **replaces** the old one — no need to DELETE first.
- `201` on success, returns the same `resume` object as GET.
- Failures are all `422` with a `message` and `errors.resume[]`:
  - not a PDF → *"Please upload your resume as a PDF."*
  - over 4 MB → *"Your resume must be smaller than 4 MB."*
  - scanned image / no readable text → *"We could not read any text in that PDF.
    If it is a scan or photo, please upload a text PDF instead."*

**DELETE** — removes it, returns `{ "resume": null }`.

### Suggested Profile UI

- Empty state: "Add your resume (PDF)" button + one line on why it helps the
  match score.
- Uploaded state: file name, `uploaded_ago`, "X characters read", and
  Replace / Remove actions.

---

## Summary for the developer

| # | Screen | Change | Backend |
| --- | --- | --- | --- |
| 1 | Job Details | Bind the Wage card to the new `wage_label` string | ✅ Done — `wage_label` now sent on detail + list |
| 2 | Profile (+ Apply nudge) | Add resume Upload / View / Replace / Remove using `/worker/resume` | ✅ Already live — endpoints exist |
