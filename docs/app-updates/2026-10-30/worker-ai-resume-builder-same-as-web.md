# Super Karigar **Worker app**: AI resume builder, same as the website

**Goal:** build the AI resume builder in the worker app so it looks and works
exactly like the website's **AI Resume** page (`/worker/resume/builder`): a
4-step wizard, a resume-strength meter, **3 designs × 4 colours**, the photo
switch, the "Verified" badge, an editable AI draft and a live PDF preview.

**No server change is needed.** Every API below is already live; the app only
has to build the screens. (The first note on these APIs was
`docs/app-updates/2026-10-13/worker-app.md`; this file is the full,
screen-by-screen spec to match the web.)

All calls: base `/api/v1`, header `Authorization: Bearer <token>`,
`Accept: application/json`.

---

## 1. The APIs

| Call | What it does |
|---|---|
| `GET /worker/resume/builder` | Opens the builder: the form already filled in, the last saved resume, the designs, the colours, the photo and the verified flag |
| `POST /worker/resume/builder/draft` | Form answers → AI-written resume. Saves nothing as the resume. **Throttle 10/min** |
| `POST /worker/resume/builder/preview` | Same body as save → the PDF (`application/pdf`) **without saving**. For the live preview. **Throttle 60/min** |
| `POST /worker/resume/builder` | Form answers + edited resume → builds the PDF and makes it the karigar's resume. `201` |
| `GET /worker/resume/file` | The saved resume PDF (`application/pdf`). `404` when there is none |
| `POST /worker/profile/avatar` | (multipart, field `avatar`, image ≤2 MB) → `{ "avatar_url": "…" }`. For "Add photo" |

### `GET /worker/resume/builder` response

```json
{
  "form": {
    "language": "en",
    "name": "Sunita Devi",
    "phone": "9876500011",
    "email": null,
    "city": "Lucknow",
    "state": "Uttar Pradesh",
    "trade": "",
    "experience_years": 12,
    "skills": ["Chikankari", "Hand embroidery"],
    "tools": null,
    "work": [],
    "education": "8th pass",
    "training": null,
    "languages": ["Hindi", "Urdu"],
    "about": null,
    "achievements": null,
    "expected_wage": 18000,
    "relocate": false,
    "template": "modern",
    "accent": "#bf3a16",
    "show_photo": true
  },
  "saved": null,
  "resume": null,
  "languages": ["en", "hi"],
  "templates": ["modern", "classic", "bold"],
  "accents": ["#bf3a16", "#1e3a5f", "#4f6b4a", "#6b2d5c"],
  "photo_url": "https://…/avatars/x.jpg",
  "verified": true,
  "limits": { "jobs": 5, "skills": 20 }
}
```

- `form`: the answers saved last time, or else what the profile already
  knows. Show `null` as an empty field.
- `saved`: the resume saved last time (same shape as the draft below), or
  `null`.
- `resume`: the resume on record (`name`, `source` = `"ai"` / `"upload"`,
  `uploaded_at`, `uploaded_ago`, …), or `null`.
- **Draw the template cards and colour dots from `templates` and `accents`.**
  Do not hard-code them, so a new colour added on the server shows up in the
  app without a new build.

---

## 2. Designs and colours (the multi-colour part)

### 3 designs (`template`)

| Key | Label (EN / HI) | What the PDF looks like |
|---|---|---|
| `modern` (default) | Modern / मॉडर्न | Full-height **dark ink sidebar** (`#241d19`, same for every colour) on the left with the round photo (or initials), contact, skills, languages, education; main column on the right with the name, a headline in the accent colour, 3 tinted stat tiles, accent section headings, then the work timeline |
| `classic` | Classic / क्लासिक | Serif font, centred name over a **double accent rule**, accent headings and ◆ skill bullets, small photo centred on top, single column |
| `bold` | Bold / बोल्ड | **Full-width colour header band** with name, headline and photo, then 3 stat tiles with an accent top border, then the rest |

### 4 colours (`accent`)

| Hex | Name (EN / HI) |
|---|---|
| `#bf3a16` (default) | Terracotta / टेराकोटा |
| `#1e3a5f` | Ink blue / गहरा नीला |
| `#4f6b4a` | Olive / ज़ैतूनी हरा |
| `#6b2d5c` | Plum / बैंगनी |

The colour paints the headline, headings, dates and the initials circle in
every design, plus the double rule (classic) or the whole header band and
tile borders (bold). Any colour works with any design: 12 looks in total.

### How the web shows them (copy this)

- **Template cards**: 3 cards in a row. Each has a small drawn miniature of
  the layout **painted in the currently picked colour** (so tapping a colour
  recolours all 3 miniatures), the label under it, and a ✓ on the picked one.
  Picked card: primary-colour border + soft ring.
- **Colour dots**: a "Colour" label, then 4 round swatches (~32 px) filled
  with the hex. The picked one has a dark ring and a white ✓ inside. Use the
  name from the table above as the accessibility label.
- **Photo switch**: "Show photo on resume" checkbox/switch, **only when
  `photo_url` is set**. With `show_photo: false`, or no photo, the PDF shows the
  karigar's initials in an accent-coloured circle.
- **Verified chip**: when `verified` is `true`, show a small green chip
  `✔ "Verified" badge on your resume`. Nothing to send: the server adds the
  badge to the PDF on its own.
- Any tap on a card, a dot or the photo switch → refresh the preview quickly
  (web waits **150 ms**).

---

## 3. The screens: a 4-step wizard

Top of the screen: title **"AI Resume"**, subtitle *"Answer a few questions.
AI writes a professional resume, you pick a design, and employers can
download it."*

When `resume.source == "ai"`, show a green card above the steps:
**"Your resume is ready"** · *"Saved {uploaded_ago}. Employers download it
when you apply."* · button **Download PDF** (`GET /worker/resume/file`).

**Stepper** (4 tappable steps with icons):

| # | Label EN | Label HI | Icon |
|---|---|---|---|
| 1 | About you | आपके बारे में | user |
| 2 | Your work | आपका काम | wrench |
| 3 | Education & more | पढ़ाई और बाकी | graduation cap |
| 4 | Design & save | डिज़ाइन और सेव | palette |

- Step 4 is disabled until there is a draft (or a `saved` resume).
- **Open on step 4** when `saved` is not null (use `saved` as the resume);
  otherwise open on step 1.
- Going forward checks: leaving step 1 needs `name`; going past step 2 needs
  `trade`. Show *"Please fill this in."* under the empty field and stay /
  jump back to that step.
- Bottom buttons on steps 1–3: **Back** (not on step 1) and **Next**; on step
  3 **Next** becomes **Write my resume with AI** (or **Write again with AI**
  when a draft already exists), and under it, when a draft exists, the link
  **"Keep my current resume and go to design →"**.

### Step 1: About you

Heading *"About you"*, hint *"This goes at the top of your resume. We filled
in what your profile already has."*

| Field | Key | Rules / placeholder |
|---|---|---|
| Photo row | — | Round photo from `photo_url`, else initials. Text: *"A clear photo makes your resume stand out."* / no photo: *"No photo yet. Resumes with a photo get more calls."* With photo: **Show photo on resume** switch (`show_photo`). Without: **Add photo** button → pick image → `POST /worker/profile/avatar` → use the returned `avatar_url` as `photo_url` |
| Resume language | `language` | Toggle **English / हिंदी** (`en` / `hi`). Hint *"Your resume is written in this language."* |
| Full name * | `name` | ≤100 |
| Phone | `phone` | ≤20, placeholder `+91…` |
| Email | `email` | valid email, ≤150, placeholder `you@example.com` |
| City | `city` | ≤80 |
| State | `state` | ≤80 |

### Step 2: Your work

Heading *"Your work"*, hint *"What you do and what you are good at."*

| Field | Key | Rules / placeholder |
|---|---|---|
| What work do you do? * | `trade` | ≤100, *"e.g. Zari embroidery karigar"* |
| Experience (years) | `experience_years` | number 0–60 |
| Skills | `skills[]` | tag input, ≤20 tags, each ≤60, *"e.g. Aari work — type or pick"*, with skill suggestions |
| Tools / machines you use | `tools` | ≤300, *"e.g. Aari needle, adda frame, Usha machine"* |

**Work experience** (*"Where have you worked? Add your latest job first."*).
Repeatable cards titled "Job 1", "Job 2"… with a delete icon, **max 5**
(`limits.jobs`). Start with **one empty card** when `form.work` is empty.
Button **Add another job** (hide at 5).

| Field | Key | Rules / placeholder |
|---|---|---|
| Your work there * | `work[].role` | ≤100, *"e.g. Embroidery karigar"* |
| Workshop / company | `work[].employer` | ≤120 |
| Place | `work[].place` | ≤80 |
| From (year) | `work[].from` | ≤20, placeholder `2019` |
| To (year) | `work[].to` | ≤20, placeholder `2023`; hide/disable when "current" is on |
| I still work here | `work[].current` | bool |
| What did you do there? | `work[].details` | ≤600, multi-line, *"e.g. Zari work on bridal lehengas, 2 pieces a day, trained 3 new workers"*. Hint *"Write it any way you like. AI turns it into clean resume points."* |

**Drop every job card whose `role` is empty before sending** any request.

### Step 3: Education & more

*"Education & languages"* — *"Schooling, any course or training, and the
languages you speak."*

| Field | Key | Rules / placeholder |
|---|---|---|
| Education | `education` | ≤150, *"e.g. 10th pass"* |
| Training / certificates | `training` | ≤300, *"e.g. Skill India embroidery course, 2021"* |
| Languages | `languages[]` | tag input, ≤8, each ≤30. Suggestions: Hindi, English, Urdu, Bengali, Gujarati, Marathi, Punjabi, Rajasthani, Tamil, Telugu, Kannada, Odia, Bhojpuri |

*"More about you"* — *"Optional, but it makes your resume stronger."*

| Field | Key | Rules / placeholder |
|---|---|---|
| About you | `about` | ≤600, *"Anything you want employers to know"* |
| Achievements | `achievements` | ≤600, *"e.g. Made pieces for an export order, won a state craft award"* |
| Expected monthly salary (₹) | `expected_wage` | number, placeholder `15000` |
| Ready to travel or move for work | `relocate` | bool |

### Resume strength meter (steps 1–3)

Web shows it in a side panel; on the phone put it as a collapsible card
under the stepper (or a sticky bar with % that opens the list).

9 checks, each worth the same:

| Done when | Tip text (EN) |
|---|---|
| `photo_url` set **and** `show_photo` on | Add a profile photo |
| `trade` filled | Say what work you do |
| `experience_years` filled | Add your years of experience |
| 3+ `skills` | Add at least 3 skills |
| a job with `role` and `details` of 20+ characters | Describe at least one job |
| `tools` filled | List the tools or machines you use |
| `education` or `training` filled | Add education or training |
| 1+ `languages` | Add the languages you speak |
| `about` or `achievements` filled | Add something about you or an achievement |

`strength = round(done / 9 × 100)` %. Bar colour and label:

- **≥ 80**: green (`emerald-600`), *"Strong — employers will like this"*
- **≥ 50**: amber, *"Good — a few more details will help"*
- **below 50**: primary (terracotta), *"Basic — add more details"*

Each tip has a round tick: filled green ✓ when done, empty when not (done
tips greyed). Under the meter, a lightbulb tip: *"AI writes only from your
answers and never adds anything that is not true. The more you tell it, the
better your resume. You can type in Hindi or Hinglish too."*

### Writing with AI (the loader)

**Write my resume with AI** → `POST /worker/resume/builder/draft` with all
step 1–3 fields **plus** `template`, `accent`, `show_photo` as JSON.

While it runs (8–15 s) show a full-screen overlay: sparkle icon, **"AI is
writing your resume"**, a line that rotates **every 2.6 s** through:

1. Reading your work history…
2. Writing your profile…
3. Turning your work into clear points…
4. Laying out your resume…

an indeterminate progress bar, and *"This takes about 10 seconds."*

Results:

- `200` → put `resume` into the editor and go to step 4. **Any `200` is
  success**: if the AI is down the server still returns a plain resume built
  from the answers.
- `422` → show `errors.<field>[0]` under each field (job fields come as
  `work.0.role`), plus *"Please fix the fields marked in red."*; jump to the
  step that has the first error.
- `429` → *"Too many tries. Please wait a minute and try again."*
- anything else → *"Could not write the resume right now. Please try again."*

### Step 4: Design & save

Three blocks, top to bottom on the phone (web: editor left, preview right):

**a) Choose a design** — *"Pick a layout and a colour. The preview updates as
you go."* Template cards, colour dots, photo switch and verified chip as in
§2. Add a **"See the preview"** button that scrolls to the preview (the web
does this on small screens).

**b) Your resume** (editable) — *"Change any line you like. The preview
updates as you type."* Button top-right: **Write again with AI** (same draft
call, same loader).

| Field | Resume key | Input |
|---|---|---|
| Headline | `headline` | single line, ≤150 |
| Profile | `summary` | multi-line, ≤1200 |
| Skills | `skills[]` | tag input, ≤20 |
| Work experience | `experience[]` | per job: `role` (single line, ≤100) + `period` (single line, ≤60, *"e.g. 2019 – 2023"*); under them `employer, place` as grey read-only text; then `points` as **one multi-line box, one point per line** (≤6 points, each ≤300), hint *"One point per line."* |
| Education | `education[]` | multi-line box, one per line (≤4) |
| Languages | `languages[]` | tag input (≤8) |
| More about you | `extras[]` | multi-line box, one per line (≤6), *"One point per line."* |

`name`, `phone`, `email`, `location` and `expected_wage` are **not editable
here**: they come from steps 1–3, and the server re-reads them from the form
on every save, whatever is sent.

**c) Live preview** — card titled **"Live preview"** with a status dot and
text: *"Updating…"* (pulsing) / *"Up to date"* (green) / *"Could not update"*
(red). Buttons: **Refresh** (↻) and **Open** (open the PDF full screen /
share sheet). Under it, render the PDF page(s) (`pdfrx`, `syncfusion_flutter_pdfviewer`,
`react-native-pdf`, …). Spinner until the first PDF arrives.

Preview call: `POST /worker/resume/builder/preview` with the **save body**
(§4), response is the PDF bytes.

- Refresh when step 4 opens (~50 ms), **~900 ms after the karigar stops
  typing** in the editor, and **~150 ms after a design / colour / photo tap**.
- Keep a request counter: when an older, slower response arrives after a
  newer one was sent, throw it away (otherwise the preview flips back to an
  old colour).
- Throttle is 60/min, so always debounce; never call on every keystroke.

**Bottom buttons:** **Edit answers** (← back to step 3) and **Save as my
resume** (shows *"Saving…"* with a spinner while it runs).

---

## 4. Save

`POST /worker/resume/builder`, JSON:

```json
{
  "language": "en",
  "name": "Sunita Devi",
  "phone": "9876500011",
  "email": null,
  "city": "Lucknow",
  "state": "Uttar Pradesh",
  "trade": "Chikankari embroidery karigar",
  "experience_years": 12,
  "skills": ["Chikankari", "Hand embroidery"],
  "tools": "Needle, wooden frame",
  "work": [
    { "role": "Chikan karigar", "employer": "Awadh Chikan House", "place": "Lucknow",
      "from": "2016", "to": null, "current": true,
      "details": "roz 1 kurta banati hu, export order ke 200 kurte time pe diye" }
  ],
  "education": "8th pass",
  "training": null,
  "languages": ["Hindi", "Urdu"],
  "about": null,
  "achievements": "Completed 200 kurtas for an export order on time",
  "expected_wage": 18000,
  "relocate": false,
  "template": "bold",
  "accent": "#1e3a5f",
  "show_photo": true,
  "resume": {
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

Split each "one per line" box into an array (trim, drop empty lines) before
sending. `template` must be one of `templates`, `accent` one of `accents`,
or the server answers `422`.

`201` response:

```json
{
  "message": "Resume saved — employers see it and new applications are matched against it.",
  "resume": { "name": "Resume - Sunita Devi.pdf", "source": "ai", "uploaded_at": "2026-10-30T10:40:12+00:00",
              "uploaded_ago": "1 second ago", "characters": 712, "max_characters": 8000 },
  "saved": { "...": "the resume as saved, same shape as the draft" }
}
```

On `201`: toast the `message`, show the green **"Your resume is ready"** card
with **Download PDF**, and update the Profile → Resume card. The picked
design and colour are remembered: next time `GET /worker/resume/builder`
returns them in `form.template` / `form.accent`.

---

## 5. Entry points

- **Profile → Resume card**: under the upload box, **"Make my resume with
  AI"**, or **"Edit in AI builder"** when `resume.source == "ai"`.
- Optional: the same link next to the resume box on the apply sheet.
- Sidebar / menu item **"AI Resume"** (web has it in the worker sidebar).

## 6. Hindi

- Every label above has a Hindi version on the web; use the app's Hindi
  strings when the app language is Hindi (e.g. steps: आपके बारे में · आपका
  काम · पढ़ाई और बाकी · डिज़ाइन और सेव; colour: रंग; save: मेरे रिज़्यूमे के
  रूप में सेव करें).
- The **resume language** (`language`) is separate from the app language: a
  karigar can use the app in Hindi and make an English resume, or the other
  way round.
- With `language: "hi"` the draft comes back in Devanagari and the PDF prints
  it correctly in all 3 designs and all 4 colours. The karigar can type the
  answers in Hinglish; the AI converts them.

## 7. Checklist for the developer

- [ ] Template cards and colour dots built from `templates` / `accents`, not hard-coded
- [ ] Miniatures recolour when a colour is tapped
- [ ] Photo switch only when `photo_url` is set; "Add photo" uploads the avatar
- [ ] Verified chip when `verified` is `true`
- [ ] Strength meter with the 9 checks and 3 levels
- [ ] Empty job cards dropped before every request
- [ ] AI loader with rotating lines; any `200` = success; `422` / `429` handled
- [ ] Live PDF preview: debounced, stale responses ignored
- [ ] Opens on step 4 when `saved` is set
- [ ] Save sends all form fields **plus** `resume`; Download PDF via `GET /worker/resume/file`
