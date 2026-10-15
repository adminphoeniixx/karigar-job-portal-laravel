# Super Karigar **Worker app**: changes of 13 Oct 2026

**AI resume builder.** A karigar with no resume fills a short form; the AI
writes a neat resume from it (English or Hindi), the karigar checks and edits
it, and saving turns it into their resume PDF. That PDF is what employers
download, and its text is what the AI match score reads. It replaces an
uploaded PDF, and uploading a PDF later replaces it.

Full reference: `docs/worker-app-api.md` §3 "AI resume builder". Postman:
`docs/karigar-worker-app.postman_collection.json` → "Resume" (every new request
has a saved example response).

## What changed on the server

| Call | What it does |
|---|---|
| `GET /worker/resume/builder` | The form, already filled in (last answers, or else the profile), plus the last saved resume |
| `POST /worker/resume/builder/draft` | Form answers → AI draft. Nothing is saved as the resume yet. Throttled to 10 a minute |
| `POST /worker/resume/builder/preview` | Same body as save → the PDF (`application/pdf`) **without saving**. For the live preview and the design picker. Throttled to 60 a minute |
| `POST /worker/resume/builder` | Form answers + the (edited) draft → builds the PDF and makes it the resume. `201` |
| `GET /worker/resume/file` | **New for any resume:** the PDF itself (`application/pdf`), so the app can show it. `404` when there is none |

- **Three PDF designs and four colours.** `template`: `modern` (dark
  sidebar with photo) · `classic` (serif, centred) · `bold` (colour header
  with photo). `accent`: one of `#bf3a16` terracotta, `#1e3a5f` ink blue,
  `#4f6b4a` olive, `#6b2d5c` plum. `show_photo`: puts the profile photo
  (cut round) on the resume; with no photo the karigar's initials are used.
  A KYC-verified karigar gets a "✔ Verified on Super Karigar" badge on
  the PDF automatically. `GET /worker/resume/builder` lists `templates`,
  `accents`, `photo_url` and `verified`.
- `GET /worker/resume` (and the `resume` object everywhere) gains
  `source`: `"ai"` when made in the builder, `"upload"` for an uploaded PDF.
- The draft takes ~8–15 s (it is an AI call). Show a loader that says the
  resume is being written. If the AI is down the server still answers `200`
  with a plain resume built from the answers, so treat any `200` as success.

## What to build

1. **Entry points.** On the Profile → Resume card, under the upload box, add
   **"Make my resume with AI"** (or **"Edit in AI builder"** when
   `resume.source == "ai"`). Optional: the same link next to the resume box on
   the apply sheet.
2. **Screen 1: Your details** (open with `GET /worker/resume/builder`, fill
   every field from `form`):

   | Field | Key | Rules |
   |---|---|---|
   | Resume language | `language` | `en` \| `hi` toggle, default `en` |
   | Full name * | `name` | ≤100 |
   | Phone | `phone` | ≤20 |
   | Email | `email` | valid email |
   | City / State | `city`, `state` | ≤80 each |
   | What work do you do? * | `trade` | ≤100, e.g. "Zari embroidery karigar" |
   | Total experience (years) | `experience_years` | 0–60 |
   | Skills | `skills[]` | ≤20 tags, each ≤60 |
   | Tools / machines | `tools` | ≤300 |
   | Work experience (repeatable, ≤5) | `work[]` | each: `role`* ≤100, `employer` ≤120, `place` ≤80, `from` ≤20, `to` ≤20, `current` bool, `details` ≤600 ("What did you do there?") |
   | Education | `education` | ≤150, e.g. "10th pass" |
   | Training / certificates | `training` | ≤300 |
   | Languages you speak | `languages[]` | ≤8 tags |
   | About you | `about` | ≤600 |
   | Achievements | `achievements` | ≤600 |
   | Expected monthly salary (₹) | `expected_wage` | number |
   | Ready to travel / relocate | `relocate` | bool |
   | Design | `template` | `modern` \| `classic` \| `bold`, default `modern` |
   | Colour | `accent` | one of `accents`, default `#bf3a16` |
   | Photo on resume | `show_photo` | bool, default `true` |

   Start with one empty job card. Drop a job card whose `role` is empty before
   sending. A karigar can type the details in Hinglish; the AI rewrites them
   in the chosen language.
   Button **"Write my resume with AI"** → `POST /worker/resume/builder/draft`
   with the fields above as JSON. `422` → show `errors.<field>[0]` under the
   field (job fields come as `work.0.role`). `429` → "Wait a minute and try
   again".
   Split the form into steps as the web does: **About you** (photo, language,
   name, contact) → **Your work** (trade, years, skills, tools, jobs) →
   **Education & more** → **Design & save**. A strength meter (how many of:
   photo, trade, years, 3+ skills, a described job, tools, education,
   languages, about/achievement are filled) nudges the karigar to add more.
3. **Screen 2: Design & save.** At the top, three template cards and four
   colour dots (and the photo switch when `photo_url` is set). Show the real
   PDF as the preview: `POST /worker/resume/builder/preview` with the save
   body, ~1 s after the karigar stops typing or right after a design tap, and
   render the returned PDF (e.g. a PDF view / `pdfrx` / `react-native-pdf`).
   Below it, the editable resume: `headline` (one line), `summary`
   (multi-line), `skills[]` (tags), each `experience[].role` / `period` (single line) and
   `experience[].points[]` (one point per line), `education[]`, `languages[]`,
   `extras[]` (one per line). `name`, `phone`, `email`, `location` and
   `expected_wage` are read-only: they come from Screen 1 (the server ignores
   them in the save and re-reads them from the form). Buttons: **Edit details**
   (back), **Write again** (draft again), **Save as my resume**.
4. **Save** → `POST /worker/resume/builder` with **all the Screen 1 fields and**
   `resume: { headline, summary, skills[], experience[{role, employer, place, period, points[]}], education[], languages[], extras[] }`.
   `201` → toast `message`, show "Your resume is ready" with **Open PDF**
   (`GET /worker/resume/file` with the token, open in a PDF viewer).
5. Next time the screen opens with `saved` set, go straight to Screen 2 with
   `saved` as the resume.

### Draft response (`POST /worker/resume/builder/draft`)
```json
{
  "resume": {
    "language": "en",
    "name": "Sunita Devi",
    "phone": "9876500011",
    "email": null,
    "location": "Lucknow, Uttar Pradesh",
    "expected_wage": "₹18,000 / month",
    "headline": "Chikankari embroidery karigar with 12 years of experience",
    "summary": "Does hand embroidery on kurtas and sarees, skilled in Chikankari. Looking for work.",
    "skills": ["Chikankari", "Hand embroidery"],
    "experience": [
      { "role": "Chikan karigar", "employer": "Awadh Chikan House", "place": "Lucknow",
        "period": "2016 – Present",
        "points": ["Does Chikan work on kurta and saree daily", "Makes one kurta every day"] }
    ],
    "education": ["8th pass"],
    "languages": ["Hindi", "Urdu"],
    "extras": ["Uses needle and wooden frame", "Completed 200 kurtas for export order on time"]
  }
}
```

### Save response (`POST /worker/resume/builder`, `201`)
```json
{
  "message": "Resume saved — employers see it and new applications are matched against it.",
  "resume": { "name": "Resume - Sunita Devi.pdf", "source": "ai", "uploaded_at": "2026-10-13T10:40:12+00:00",
              "uploaded_ago": "1 second ago", "characters": 712, "max_characters": 8000 },
  "saved": { "...": "the resume as saved, same shape as the draft" }
}
```

A Hindi resume (`language: "hi"`) comes back in Devanagari, and the PDF prints
it correctly.
