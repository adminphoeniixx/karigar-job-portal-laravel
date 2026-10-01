# Super Karigar **Employer app**: the Post Job form, and reposting

Everything the employer app's Post Job / Edit Job screens need to match the
website, plus reposting old jobs. One small part is for the worker app (§10).

Full reference: `docs/employer-app-api.md` §5 Jobs. Postman:
`docs/karigar-employer-app.postman_collection.json` ("Job form options",
"Repost job"). Base URL `{{base_url}}/api/v1`, auth as before. 🔒 = needs auth.

**Server status (30 Sep 2026):** ships with the next server deploy. The new
database columns and each category's skills are already in place.

**Summary of what to change**

| # | Screen | Change | Priority |
|---|---|---|---|
| 1 | Post Job | Skills chips come from the **chosen category** | **Must** |
| 2 | Post Job | **Min and max experience** | **Must** |
| 3 | Post Job | Wage **per month** only | **Must** |
| 4 | Post Job | **Shift start and end time** under the shift | **Must** |
| 5 | Post Job | Perks: ESI, PF and more to pick, plus **the employer's own** | **Must** |
| 6 | Post Job | "Suggest with AI" in **English or Hindi** | **Must** |
| 7 | Post Job | **Address** field after state and city; the map pin follows it | **Must** |
| 8 | Post Job | Call jobs: **name, mobile and designation** of whoever picks up | **Must** |
| 9 | My Jobs | **Repost** a closed or expired job | **Must** |
| 10 | Worker app, job page | Show experience, shift hours and who to ask for | Should |

The web form already has all of this: match it.

---

## 0. Load the form's options 🔒

Once when the Post Job screen opens:

```
GET /employer/jobs/form-options
```
```jsonc
{
  "category_skills": {
    "Weaving": ["Handloom weaving", "Powerloom operation", "Warping", "Dyeing", "Carpet weaving", "Saree weaving", "Jacquard weaving"],
    "Kadhai / Embroidery": ["Zardozi", "Chikankari", "Aari work", "Hand embroidery", "Machine embroidery", "Phulkari", "Mirror work", "Sequin work"]
    // ...one entry per active category
  },
  "perks": ["ESI", "PF", "Food", "Accommodation", "Travel allowance", "Bonus", "Overtime pay",
            "Weekly off", "Paid leave", "Medical insurance", "Uniform", "Tools provided",
            "Diwali bonus"],        // the usual ones, then this employer's own from earlier jobs
  "shifts": ["day", "night", "rotational", "flexible"]
}
```
`GET /reference` also carries `category_skills` (and the base `perks`) if you
already cache that.

---

## 1. Skills follow the category

When the employer picks a category, show `category_skills[<category>]` as skill
chips to tap. They can still type any other skill. Before a category is picked,
show "Pick a category first to see its skills". Changing the category keeps the
skills already chosen.

The admin edits each category's skills in Admin → Categories.

## 2. Experience range

Two number fields, years:

| Field | Rule |
|---|---|
| `experience_min` | 0–60, optional |
| `experience_max` | 0–60, optional, **≥ `experience_min`** (`422` otherwise) |

Responses carry a ready label, `experience_label`: `"2–5 yrs"`, `"3 yrs"`,
`"2+ yrs"`, `"Up to 5 yrs"`, `"Freshers welcome"` (min 0, no max), or `null`.

## 3. Wage per month

`wage_min` / `wage_max` are **per month**; send `"wage_type": "monthly"` or
leave it out. No daily/hourly picker (`/reference` `wage_types` is
`["monthly"]`). An older build that still sends `daily` is stored ×26 as
monthly.

## 4. Shift hours

Under the shift picker, two time pickers:

| Field | Format |
|---|---|
| `shift_start` | `"HH:MM"` 24-hour, e.g. `"09:00"` |
| `shift_end` | `"HH:MM"`, e.g. `"18:00"`; may be earlier than the start for a night shift |

Send both or neither (`422` for one alone or a bad format). Responses add
`shift_hours_label`: `"9:00 AM – 6:00 PM"`.

## 5. Perks: pick or add your own

Show `form-options` `perks` as toggle chips, plus an **Add your own** text box.
`perks` accepts **any** text, up to 15 perks of 40 characters each; the server
trims them and drops repeats (any case). A perk the employer typed on one job is
offered back on the next through `form-options`.

```json
"perks": ["ESI", "PF", "Diwali bonus"]
```

## 6. AI description in English or Hindi

Add an **English / हिंदी** switch next to "Suggest with AI":

```
GET /employer/jobs/suggest-description?title=Handloom weaver&category=Weaving&language=hi
```

`language`: `en` (default) or `hi` (Hindi in Devanagari, common English work
words kept). Anything else returns `422`. The response is unchanged:
`{ "suggestions": ["…", "…"] }`. Show every entry: normally two, one when the AI
provider is off.

## 7. Address and the map pin

After state and city, add an **Address** field (street, area, landmark):

```json
"state": "Rajasthan", "city": "Jaipur", "address": "Plot 12, Sanganer"
```

- If the app places the pin itself, send `latitude` / `longitude` as before.
- **If it sends none**, the server finds the pin from the address (or from the
  city and state), the same way the web form does, and stores it.
- `EmployerJobResource` now returns `address`, so Edit Job can show it again.
- Show the map from the job's `latitude` / `longitude` after saving.

Karigars see the address on the job page and the map pin at that spot.

## 8. Who picks up the call

When `contact_mode` is `call` or `both`, ask for three things:

| Field | Label | Rule |
|---|---|---|
| `contact_name` | Who picks up the call | ≤100, optional |
| `contact_phone` | Mobile number | required for call/both |
| `contact_designation` | Their designation (Supervisor, Manager…) | ≤100, optional |

Karigars see them next to the call button ("Ask for: Ramesh Kumar,
Supervisor"). On an apply-only job none of the three is shown to karigars.

## 9. Repost 🔒

On My Jobs, a **Repost** action for a job that is **closed** or **expired**:

```
POST /employer/jobs/{job}/repost
```
```jsonc
// 201: a new job, live today
{ "message": "Job reposted.", "job": { /* EmployerJobResource, reposted_from_id = the old job */ } }

// 422
{ "message": "This job is still live.", "code": "cannot_repost" }
{ "message": "A draft has never been live; publish it instead.", "code": "cannot_repost" }
{ "message": "You have used all 10 job posts in your plan for this billing period…", "code": "cannot_repost" }
```

- The copy is a **new job post**: it counts against the plan's monthly job posts.
- The old job and its applicants stay as they were.
- An expiry date carries over as a fresh run of the same length (minimum 7
  days); no expiry stays no expiry.
- Show `message` on `422`.

---

## 10. Worker app: the job page

`GET /jobs/{job}` now also carries, for the worker app to show:

| Field | Show as |
|---|---|
| `experience_label` | "Experience: 2–5 yrs" |
| `shift_hours_label` | next to the shift: "Day shift · 9:00 AM – 6:00 PM" |
| `contact_name`, `contact_designation` | under the call button: "Ask for: Ramesh Kumar, Supervisor" (call jobs only) |
| `address` | above the map (already there) |

`GET /jobs` cards also carry `experience_label`.

---

## Checklist

**Employer app**
- [ ] Load `GET /employer/jobs/form-options` on the Post Job screen
- [ ] Skill chips from `category_skills[category]`; free typing still allowed
- [ ] Min / max experience fields (max ≥ min)
- [ ] Wage per month; no daily/hourly picker
- [ ] Shift start / end time pickers ("HH:MM")
- [ ] Perk chips from `perks` + "Add your own"
- [ ] English / हिंदी switch on "Suggest with AI" (`language=en|hi`)
- [ ] Address field after state / city; map from the saved pin; show `address` on Edit
- [ ] Call jobs: contact name, mobile, designation
- [ ] My Jobs: Repost on closed / expired jobs; handle `cannot_repost`

**Worker app**
- [ ] Job page: experience, shift hours, "Ask for" name and designation
