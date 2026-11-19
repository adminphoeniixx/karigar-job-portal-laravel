# Employer app: Job Profile dropdown, title suggests category and skills

QA round of 2026-11-18. Three asks on New Job Post:

- Job title as a dropdown of job profiles, with a custom option.
- The category suggested from the title.
- Skill tags suggested from the title, each removable.

## 1. Job profiles for the title dropdown

`GET /employer/jobs/form-options` (and `GET /reference`) now carry
`job_profiles`: every job title of the active categories, in category order.

```json
"job_profiles": [
  { "title": "Tailor", "category": "Tailoring" },
  { "title": "Darzi", "category": "Tailoring" },
  { "title": "Blouse Tailor", "category": "Tailoring" },
  { "title": "Weaver", "category": "Weaving" },
  …
]
```

Show the title as a searchable dropdown of these, with an **"Other (type your
own)"** option at the end that turns it into a free-text box. `title` is still
plain text on the job, so nothing else changes on save.

> **Changed on 2026-11-19:** the title is a search box with **+ Add "‹typed›"**
> instead of "Other (type your own)", and the category has an "Other" option.
> See [2026-11-19/employer-job-title-add-your-own.md](../2026-11-19/employer-job-title-add-your-own.md).

Admins edit these lists in Admin → Categories → "Job titles", the same way as
skills. Re-read `form-options` when the form opens; don't bundle the list into
the app.

## 2. Category and skills from the title

```
GET /employer/jobs/suggest-category?title=Need%20blouse%20tailor
```

```json
{ "suggestion": { "category": "Tailoring",
                  "job_profile": "Blouse Tailor",
                  "skills": ["Blouse stitching", "Cutting", "Stitching", "Kurti / suit stitching", "Alteration", "Pattern making", "Machine operation"] } }
```

- Call it when a job profile is picked, or about half a second after the
  employer stops typing a custom title.
- `suggestion` is `null` when nothing fits, or when two crafts fit equally well
  ("Machine operator"). In that case leave the category for the employer to pick.
- `job_profile` is the job title that matched, or `null` when the match came
  from the category's name or skills ("handloom weavers" → Weaving).
- `skills` is the category's skill list with the ones closest to the title
  first.
- It answers instantly (word matching, no AI) and is rate-limited to 60 a minute.

**How to apply it:**

1. Set the category **only if the employer hasn't picked one**, or if the one
   showing came from an earlier suggestion. Never overwrite their own pick. Show
   a small "Suggested from the job title" hint under it.
2. Pre-add the **first 3 skills** as tags, but only when the skill box is empty
   (or still holds only the earlier suggestion). Offer the rest as tap-to-add
   chips.
3. **Every tag gets a remove ✕.** Skills stay free text: the employer can type
   any skill.

## Test it

Employer 9000000001 / OTP 1234. Try "Darzi" → Tailoring, "zardozi karigar" →
Kadhai / Embroidery, "handloom weavers" → Weaving, "Machine operator" →
`null`.
