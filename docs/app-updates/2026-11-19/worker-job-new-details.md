# Worker app: new job details on the job page and cards

Employers can now say more about a job (2026-11-19, WorkIndia-style job form):
job type, education, English, age, languages, what to bring, where karigars may
come from, working days, when and where the interview is, bonus, urgency and
anything else. Show them to karigars.

No new endpoint. `GET /jobs/{job}` and the job cards carry the fields with
ready labels. A field the job does not fill is `null` (or `[]`): leave its row
out.

## Job card (`JobResource`)

| Key | Show |
|---|---|
| `job_type` (`full_time` / `part_time`), `job_type_label` | A "Part time" chip for `part_time` |
| `urgent` (bool) | An "Urgent hiring" tag (red) when true |

## Job page (`GET /jobs/{job}` → `data`)

Add a **Requirements** block after the skills, one row per field:

| Row | Use | Example |
|---|---|---|
| Job type | `job_type_label` | Part time |
| Education | `education_label` | 10th pass or above |
| English | `english_label` | Thoda English |
| Age | `age_label` | 18–40 yrs |
| Languages | `languages` (join) | Hindi, Urdu |
| Must have | `asset_labels` (join) | Aadhaar card, Own tools |
| Karigars from | `reach_label` | Karigars within 10 km |
| Working days | `work_days_label` | Mon–Sat |
| Interview | `interview_days_label` · `interview_hours_label` · `interview_address` | Mon–Sat · 11:00 AM – 4:00 PM · Shop 12, MI Road |
| Bonus / incentive | `incentive_label` | Up to ₹2,000 a month · Per-piece rate |
| Other requirements | `other_requirements` | Must know hand embroidery on silk. |

Also:

- `relocation_allowed: true` → a line "Karigars from other cities who will move
  here can apply".
- `hiring_urgency: "immediate"` → the "Urgent hiring" tag at the top.
- With a fee, add `worker_fee_purpose_label` to the fee chip: "Joining fee:
  ₹300 · for Uniform / T-shirt / bag".
- `interview_address: null` on an in-person interview means the job address.
- `experience_label` now says "Freshers only" for a job that takes only
  freshers.

The raw values are there too (`education`, `english`, `age_min`, `age_max`,
`assets`, `reach`, `work_days`, `interview_days`, `interview_start`,
`interview_end`, `incentive_max`, `incentive_type`, `worker_fee_purpose`),
for an app that builds its own labels in Hindi. The values are listed in
`docs/employer-app-api.md` §5.

## Feed

A job that wants karigars from nearby only (`reach: "nearby"`) shows in the
feed only to karigars within 10 km of it (by the karigar's position, or their
city when they have none), and only they get its alert. Search still finds it.

## Texts

| English | Hindi |
|---|---|
| Requirements | ज़रूरतें |
| Job type · Education · English · Age · Languages · Must have | जॉब का प्रकार · पढ़ाई · अंग्रेज़ी · उम्र · भाषाएँ · पास होना चाहिए |
| Karigars from · Working days · Interview · Bonus / incentive · Other requirements | कारीगर कहाँ से · काम के दिन · इंटरव्यू · बोनस / इंसेंटिव · और ज़रूरतें |
| Urgent hiring · Part time | अर्जेंट भर्ती · पार्ट टाइम |
| Karigars from other cities who will move here can apply | दूसरे शहर के कारीगर, जो यहाँ आ सकें, अप्लाई कर सकते हैं |

The labels in the response are English. In the Hindi app, build them from the
raw values, as the website does:

| Value | Hindi |
|---|---|
| `job_type` full_time · part_time | फ़ुल टाइम · पार्ट टाइम |
| `education` Below 10th · 10th Pass · 12th Pass · ITI / Diploma · Graduate · Post Graduate | 10वीं से कम भी चलेगा · 10वीं पास या ऊपर · 12वीं पास या ऊपर · ITI / डिप्लोमा या ऊपर · ग्रेजुएट या ऊपर · पोस्ट ग्रेजुएट |
| `english` none · basic · good · fluent | अंग्रेज़ी ज़रूरी नहीं · थोड़ी अंग्रेज़ी · अच्छी अंग्रेज़ी · धाराप्रवाह अंग्रेज़ी |
| `assets` aadhaar · pan · bank_account · smartphone · bike · driving_licence · own_tools · certificate | आधार कार्ड · पैन कार्ड · बैंक खाता · स्मार्टफ़ोन · बाइक · ड्राइविंग लाइसेंस · अपने औज़ार · ITI / ट्रेनिंग सर्टिफ़िकेट |
| `worker_fee_purpose` uniform · tools · security_deposit · training · exam · documentation · other | वर्दी / टी-शर्ट / बैग · औज़ार / किट · सिक्योरिटी डिपॉज़िट · ट्रेनिंग फ़ीस · परीक्षा फ़ीस · काग़ज़ी काम · अन्य |
| days mon … sun | सोम · मंगल · बुध · गुरु · शुक्र · शनि · रवि (all seven: हर दिन) |
| `reach` nearby · city | {km} किमी के अंदर · {city} में कहीं से भी |

## Test it

Worker 9000000002 / OTP 1234. Open a job posted after the website update with
these details filled in: the Requirements block shows each one. A job posted
before shows only "Job type: Full time".
