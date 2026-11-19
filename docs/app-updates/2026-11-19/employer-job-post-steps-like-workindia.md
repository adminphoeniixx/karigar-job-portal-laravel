# Employer app: New Job Post in 4 steps, with WorkIndia's details

Asked on 2026-11-19: New Job Post should have every feature of WorkIndia's job
post, without copying its look. We went through WorkIndia's five steps (job
basics, phone OTP, review with matching candidates, plan, payment). Login and the
plan stay where they are in our app, so the job form itself has **4 steps**. The
website's form already works this way.

The save body is the same `POST /employer/jobs` / `PATCH /employer/jobs/{job}`,
with new optional fields. An older build that leaves them out keeps working, and
its edits keep whatever a newer build saved.

```
 ① Job basics   ② Who you need   ③ Timings & interview   ④ Review & post
 ───────────────────────────────────────────────────────────────────────
 [ Back ]                                  [ Save as draft ]  [ Next → ]
```

## Step 1: Job basics

| Field | Body key | Notes |
|---|---|---|
| Job type | `job_type` | `full_time` (default) or `part_time`, as two chips |
| Job title + category | `title`, `category` | As on 2026-11-19 (search box with "+ Add", category beside it) |
| Openings | `vacancies` | |
| State, city, address, map pin | `state`, `city`, `address`, `latitude`, `longitude` | Unchanged |
| Monthly in-hand salary | `wage_min`, `wage_max` | Hint: "The fixed pay the karigar takes home each month, without bonus or incentive." |
| Bonus on top? Yes / No | — | Yes shows the next two |
| Up to (₹ a month) | `incentive_max` | Required when Yes |
| What it is for | `incentive_type` | Free text ≤100. Suggest chips: Per-piece rate, Overtime, Performance bonus, Festival bonus, Commission |
| Anything else you need? (optional) | `other_requirements` | ≤500, with a counter |

**Next** needs: a title, a category (or Other), state and city, openings ≥ 1,
max salary ≥ min, and the bonus amount when Yes.

## Step 2: Who you need

Above the questions: "Filled in from {count} recent {category} jobs. Change
anything that does not fit." (or "…what craft jobs usually ask" when the
category has no answers yet).

| Question | Body key | Choices (from `form-options` → `details`) |
|---|---|---|
| Experience | `experience_min` / `experience_max` | Any (both null) · Freshers only (0 and 0) · Experienced only (min ≥ 1, optional max) |
| Minimum education | `education` | `details.education_levels`: "Below 10th pass is fine", "10th pass or above", … |
| Who can apply | `gender` | Both · Male · Female |
| English needed | `english` | `details.english_levels`: none, basic ("Thoda English"), good, fluent |
| Get karigars from | `reach` | Nearby (within 10 km) · Anywhere in {city}. Cards with a line under each |
| Karigars from other cities who will move here? | `relocation_allowed` | Yes, allow · No, local only |
| Skills | `skills` | Unchanged |
| More requirements (optional): Age | `age_min`, `age_max` | 18–70, never under 18 |
| More requirements: Languages | `languages` | `details.spoken_languages` as chips |
| More requirements: They must have | `assets` | `details.assets`: Aadhaar card, PAN card, Bank account, Smartphone, Bike, Driving licence, Own tools, ITI / training certificate |

"More requirements" shows three "+ Age", "+ Languages", "+ They must have"
chips. Each opens its field, with an ✕ that closes and clears it. Hint: "Add
these only if they matter: each one leaves some karigars out."

### Filled in from similar jobs

`form-options` → `requirement_defaults` holds, per category, what most of its
jobs ask, and `""` holds the usual values:

```json
"requirement_defaults": {
  "": { "values": { "experience_level": "any", "education": "Below 10th", "english": "none", "gender": "both", "reach": "city" }, "jobs": 0, "from_jobs": [] },
  "Tailoring": { "values": { … }, "jobs": 10, "from_jobs": ["experience_level"] }
}
```

On a **new** job, once a category is picked, set each of these five the
employer has not tapped yet to `values`. On an edit, never change them. Tag the
chip that matches the default: "Most chosen" if its field is in `from_jobs`,
else "Usual".

**Next** needs the minimum years when Experienced only is picked, and max age ≥
min age.

## Step 3: Timings & interview

| Field | Body key | Notes |
|---|---|---|
| Shift and its hours | `shift`, `shift_start`, … | Unchanged |
| Working days | `work_days` | Day toggles Mon–Sun, a new job starts Mon–Sat. Hint: "Give the real timings and days, or karigars may not join." |
| Where will the karigar work? | `work_mode` | Unchanged |
| How will you take the interview? | `interview_mode` | Unchanged |
| Interview days | `interview_days` | Day toggles, a new job starts Mon–Sat |
| Interview from / to | `interview_start`, `interview_end` | `"HH:MM"`, both or neither, end after start. A new job starts 11:00–16:00 |
| The interview is at the job address ☑ | — | Unticked shows the next field. Hidden for a remote interview |
| Interview address | `interview_address` | ≤255. Leave it `null` for the job address |
| Perks | `perks` | Unchanged |
| Does the karigar pay anything? | `requires_worker_fee`, `worker_fee_amount` | Unchanged |
| What is the fee for? | `worker_fee_purpose` | `details.worker_fee_purposes`: uniform, tools, security_deposit, training, exam, documentation, other |
| Job description + AI drafts | `description` | Moved here, unchanged |

**Next** needs a description, both interview times or neither, and a fee amount
when a fee is charged.

## Step 4: Review & post

- **The job as karigars will see it**: three blocks (Job basics, Who you need,
  Timings & interview), each with **Edit**, which opens that step.
- **Karigars matching this job**, beside it on a tablet and under it on a phone:

  ```
  GET /employer/jobs/matching-karigars?category=Tailoring&skills[]=Stitching&latitude=26.91&longitude=75.79&city=Jaipur&reach=nearby&gender=female&education=10th Pass&experience_level=experienced&experience_min=1
  ```
  ```json
  { "count": 8, "radius_km": 10,
    "karigars": [ { "id": 41, "name": "Vimla Y.", "avatar_url": null, "gender": "female",
                    "experience_years": 25, "education": "Below 10th", "languages": ["Hindi"],
                    "skills": ["Tailoring"], "verified": false,
                    "distance_km": 3.2, "distance_label": "3.2 km", "location_label": "Sanganer, Jaipur" } ] }
  ```
  Show "{count} karigars match, within {radius_km} km of the job, or in its city"
  and the five cards. `count: 0` → "No karigars match yet. Try 'Anywhere in the
  city', or ask for less." Call it when the review step opens and again when an
  edit changes these fields; 30 calls a minute are allowed. No phone numbers
  come back.
- How karigars contact you (unchanged), with role chips under "Their
  designation": Owner, Manager, Supervisor, HR (a tap fills
  `contact_designation`).
- **How soon do you need to hire?** `hiring_urgency`: Immediately (1–2 weeks)
  ("Your job shows an 'Urgent' tag") · Can wait.
- Expiry, status (live jobs only), AI switches: unchanged.
- Warning: "Never ask a karigar for money that is not written on this job. Jobs
  that do are taken down."
- Buttons: **Save as draft** on every step and **Post job** here, for a job that
  has never been live. An edit of a live job shows **Save changes** on every
  step, and its steps open in any order.
- A server error opens the step that holds the field (`errors` keys match the
  body keys above).

## A live job keeps its title

Once a job has been live, its title is fixed, as on WorkIndia: one job post is
for one role. Every job now carries `title_locked`:

- `title_locked: true` → show the title read-only with "The title cannot change
  once the job has been live. To hire for another role, post a new job."
- A different title anyway → `422`, `errors.title`. Case and spacing can still
  change.
- Drafts that never went live (`title_locked: false`) can change it.

## Unfinished job, kept on the phone

WorkIndia offers "Do you want to restore your 'sweper' job?". Do the same:

1. Keep the new job's fields on the phone while the employer types (from the
   second letter of the title).
2. Next time New Job Post opens, show "Continue your unfinished "{title}" job?"
   with the pay, city and openings, and **Restore** / **Start fresh**.
3. Forget it once the job is posted or saved as a draft, after 7 days, or on
   Start fresh.

## Other details

- The safety check now also reads `other_requirements`, `incentive_type`,
  `interview_address` and the fee purpose. "Pay registration fee…" in any of
  them holds the job, like in the description.
- A `reach: nearby` job is shown in the feed, and alerted, only to karigars
  within 10 km of its pin (or of its city when they have no pin).
- Every job's response now carries the new fields with labels, e.g.
  `education_label`, `work_days_label` ("Mon–Sat"), `interview_hours_label`
  ("11:00 AM – 4:00 PM"), `incentive_label` ("Up to ₹2,000 a month ·
  Per-piece rate"), `experience_level`. See `docs/employer-app-api.md` §5.
- "Need help? Call …" above the steps: the support number the app already gets
  from the Help endpoint (2026-10-07).

## Texts

| Where | English | Hindi |
|---|---|---|
| Steps | Job basics · Who you need · Timings & interview · Review & post | जॉब की जानकारी · किसकी ज़रूरत है · समय और इंटरव्यू · जाँचें और पोस्ट करें |
| Bar | Step {n} of {total} · Next · Back · Save changes | स्टेप {n} / {total} · आगे · पीछे · बदलाव सेव करें |
| Missing fields | Fill in the fields marked below to go on. | आगे बढ़ने के लिए नीचे बताई जगहें भरें। |
| Salary | Monthly in-hand salary (₹) | हर महीने हाथ में सैलरी (₹) |
| Bonus | Do you give a bonus or incentive on top of the salary? | क्या सैलरी के ऊपर बोनस या इंसेंटिव देते हैं? |
| Other | Anything else you need? (optional) | और कुछ चाहिए? (ज़रूरी नहीं) |
| Filled in | Filled in from {count} recent {category} jobs. Change anything that does not fit. | हाल की {count} {category} जॉब्स से भरा गया। जो ठीक न लगे, बदल दें। |
| Tags | Most chosen · Usual | सबसे ज़्यादा चुना गया · आम तौर पर |
| Experience | Any · Freshers only · Experienced only | कोई भी · सिर्फ़ फ्रेशर · सिर्फ़ अनुभवी |
| Reach | Nearby (within 10 km) · Anywhere in {city} | पास से (10 किमी तक) · {city} में कहीं से भी |
| Relocation | Can karigars from other cities who will move to {city} apply? | दूसरे शहर के कारीगर, जो {city} आ सकते हैं, अप्लाई कर सकते हैं? |
| More | More requirements (optional) | और ज़रूरतें (ज़रूरी नहीं) |
| Interview | Interview timings · The interview is at the job address | इंटरव्यू का समय · इंटरव्यू जॉब के पते पर है |
| Fee | What is the fee for? | फ़ीस किस बात की है? |
| Review | Check your job before posting · Edit | पोस्ट करने से पहले जाँच लें · बदलें |
| Matching | Karigars matching this job · {count} karigars match | इस जॉब से मेल खाते कारीगर · {count} कारीगर मेल खाते हैं |
| Urgency | How soon do you need to hire? · Immediately (1–2 weeks) · Can wait | कितनी जल्दी भर्ती करनी है? · तुरंत (1–2 हफ़्ते) · रुक सकते हैं |
| Title lock | The title cannot change once the job has been live. To hire for another role, post a new job. | जॉब लाइव होने के बाद टाइटल नहीं बदलता। दूसरे काम के लिए नई जॉब पोस्ट करें। |
| Restore | Continue your unfinished "{title}" job? · Restore · Start fresh | अधूरी "{title}" जॉब पूरी करें? · वापस लाएँ · नए सिरे से शुरू करें |
| Warning | Never ask a karigar for money that is not written on this job. Jobs that do are taken down. | कारीगर से ऐसा कोई पैसा न माँगें जो इस जॉब में लिखा नहीं है। ऐसी जॉब हटा दी जाती है। |

The labels of the choices themselves come in `form-options` → `details`.

## Test it

Employer 9000000001 / OTP 1234 (renew its plan first: it lapsed on 06 Nov).

1. New job "Darzi": the category becomes Tailoring, step 2 is filled in with
   "Usual" / "Most chosen" tags.
2. Press **Next** with the title empty: the step stays, with the errors.
3. Pick Experienced only, Female, Thoda English, Nearby; add Age 18–40 and
   Aadhaar card. On step 4 the matching count changes with them.
4. Post. The job comes back with `job_type`, `education_label`,
   `interview_hours_label` and `title_locked: true`.
5. Edit it and change the title: `422` on `title`.
