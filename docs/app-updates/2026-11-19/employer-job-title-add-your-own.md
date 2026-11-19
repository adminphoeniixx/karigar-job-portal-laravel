# Employer app: job title with "+ Add", category beside it, "Other"

Asked on 2026-11-19: New Job Post should work like WorkIndia's. The employer
types a job title and picks one of the matching titles, or adds their own. The
category sits beside the title and fills from it. The website's job form already
works this way.

This replaces the "Other (type your own)" option from
[2026-11-18](../2026-11-18/employer-job-profiles-suggest-category-and-skills.md).
No endpoint and no field of the save body changes.

> Later the same day the whole form became 4 steps with WorkIndia's other
> details, and a live job's title got locked. The title and category below are
> step 1 of it: see [employer-job-post-steps-like-workindia.md](employer-job-post-steps-like-workindia.md).

```
Job title                          Category
[ Machine operator          ]     [ Select a category      v ]
  Knitting Machine Operator   Bunai / Knitting
  Sewing Machine Operator     Tailoring
  + Add "Machine operator"
```

## 1. Job title: a search box with "+ Add"

- The titles come from `job_profiles` in `GET /employer/jobs/form-options` (or
  `GET /reference`): `{ "title": "Tailor", "category": "Tailoring" }`.
- As the employer types, list the titles that contain the text, best first:
  titles that start with it, then titles with a word that starts with it, then
  the rest. Show up to 8. Each row shows the title, with the typed part in bold,
  and its category in grey on the right.
- The last row is **+ Add "‹typed text›"**. Show it when the text is not exactly
  one of the titles (ignoring case). Tapping it keeps the typed text as the
  title.
- The typed text is the title even if the employer never taps "+ Add".
  `title` is still plain text (≤255).

## 2. The category beside the title

- **A title picked from the list**: set the category to that row's `category`
  straight away. Only do this if the employer hasn't picked a category
  themselves, or if the one showing was filled by the app. Show the hint
  "Suggested from the job title. Change it if it is wrong."
- **A title of their own**: call `GET /employer/jobs/suggest-category?title=…`
  as before. If it names a category, fill it the same way. If `suggestion` is
  `null` and the category showing came from an earlier title, clear it, along
  with any skills the app added. Then open the category picker with the hint
  "Pick the closest craft, so the right karigars get this job."
- **The category list**: `job_categories` from `GET /reference` (the live
  categories, in order), and one last option, **Other (not in the list)**.

## 3. "Other (not in the list)"

- Send `"category": null`. The server takes it, as it always has.
- Show this hint under the picker: "Karigars find this job only by searching. If
  a craft comes close, pick that instead." Feeds and job alerts follow the
  category, so a job without one reaches karigars only through search.
- Before **Post job**, require a choice: a category, or Other. The error is
  "Pick a category, or Other if none fits." A draft can skip it.
- When the employer edits a live job whose `category` is `null`, show Other as
  selected.

## 4. What happens to a new title

The app doesn't need to do anything. When a job is saved with a title that no
category lists, the server notes it. Admin → Categories → "New job titles from
employers" lists these titles. When an admin adds one to a category, it comes in
`job_profiles` for every employer from then on.

## Texts

| Where | English | Hindi |
|---|---|---|
| Under the title | Pick from the list, or add your own title. | सूची से चुनें, या अपना टाइटल जोड़ें। |
| Last row of the list | Add "‹title›" | "‹title›" जोड़ें |
| Last category option | Other (not in the list) | अन्य (सूची में नहीं) |
| Category filled from the title | Suggested from the job title. Change it if it is wrong. | जॉब टाइटल से सुझाया गया। गलत हो तो बदल दें। |
| Title typed, no category yet | Pick the closest craft, so the right karigars get this job. | सबसे मिलता-जुलता काम चुनें, ताकि जॉब सही कारीगरों तक पहुँचे। |
| Other picked | Karigars find this job only by searching. If a craft comes close, pick that instead. | यह जॉब कारीगरों को सिर्फ़ खोजने पर मिलेगी। कोई मिलता-जुलता काम हो तो वही चुनें। |
| Post job with no choice | Pick a category, or Other if none fits. | कैटेगरी चुनें, या कोई न मिले तो "अन्य"। |

## Test it

On the job form (employer 9000000001 / OTP 1234):

- Type "Tail": Tailor, Ladies Tailor, Gents Tailor, Blouse Tailor and
  Alteration Tailor, all under Tailoring, and then + Add "Tail".
- Pick "Darzi": the category becomes Tailoring, with the "Suggested" hint.
- Type "Machine operator": three machine-operator titles from three crafts, and
  then + Add. Add it, and suggest-category answers `null`, so the category
  empties and asks for a pick.
- Choose Other and post, from an employer whose plan allows posting. The job
  saves with `category: null`.
