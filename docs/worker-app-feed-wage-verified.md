# Super Karigar **Worker app**: jobs by location and category, monthly wages, verified employers, all categories at sign-up

The changes the **worker** app needs, plus one for the **employer** app (§3,
posting wages monthly).

Full reference: `docs/worker-app-api.md` (§2 Reference data, §3 Profile, §5 Jobs,
§10 Dashboard). Base URL `{{base_url}}/api/v1`, auth as before
(`Authorization: Bearer <token>`). 🔒 = needs auth.

**Server status (30 Sep 2026):** ships with the next server deploy. The wage
data is already converted to monthly in the database (§3), so the live app
shows monthly figures even before the deploy.

**Summary of what to change**

| # | App | Screen | Change | Priority |
|---|---|---|---|---|
| 1 | Worker | Jobs list | Send the phone's `lat`/`lng`; show `distance_km`; the list is now the karigar's own categories | **Must** |
| 2 | Worker | Home | Send `lat`/`lng` to the dashboard too | **Must** |
| 3 | Worker | Jobs list | "Show all jobs" toggle (`all=1`) and the feed caption from `feed` | Should |
| 4 | Worker | Everywhere wages show | Wages are **monthly**: "per month" labels, `wage_label` as it is | **Must** |
| 5 | Worker | Profile / sign-up | Expected wage asked per month; no daily/hourly choice | **Must** |
| 6 | Worker | Apply sheet | Expected wage per month | **Must** |
| 7 | Worker | Job card + job page | ✔ **Verified** tag when `employer.verified` | **Must** |
| 8 | Worker | Sign-up skills step | Show every item of `/reference` `skills` (the 14 crafts); don't hard-code | **Must** |
| 9 | Employer | Post / edit job | Wage is per month; no daily/hourly choice | **Must** |

---

## 1. Jobs list: the karigar's own feed 🔒

`GET /jobs` opened plain (no search text, no filters) is now the karigar's feed:

- **Category.** Only jobs in the karigar's own categories: a job whose
  category, or one of whose skills, is one of the profile's `skills`. A
  plumber sees plumbing jobs; a weaver, weaving jobs. A karigar with no skills
  sees every job.
- **Location.** Nearest first, from where the karigar is **right now**. Send
  the phone's position every time the list opens:

  ```
  GET /jobs?lat=26.9124&lng=75.7873
  ```

  Without `lat`/`lng` the server falls back to the position saved on the
  profile, then to the profile's city (same city first, then same state). Jobs
  without a map pin come after the pinned ones.
- Paused jobs (the employer's plan ran out) never appear.

```jsonc
{
  "data": [
    {
      "id": 153, "title": "Handloom weaver", "category": "Weaving",
      "wage_label": "₹20,800 – ₹26,000 / monthly",
      "distance_km": 3.4,                              // new: km from the karigar; null without a pin
      "employer": { "id": 12, "name": "Jaipur Looms", "verified": true }   // new: verified
      // ...rest of JobResource unchanged
    }
  ],
  "feed": {                                            // new
    "type": "for_you",
    "categories": ["Weaving"],                         // empty = not filtered by category
    "location": "current"                              // current | profile | city | none
  },
  "links": { }, "meta": { }
}
```

| Param | Meaning |
|---|---|
| `lat`, `lng` | The phone's current position. Send both, every time |
| `radius` | Optional, km: only jobs within this distance |
| `all=1` | Optional: every category, still nearest first ("Show all jobs") |
| `page` | 15 per page |

**Suggested UI**

- Ask for location permission when the Jobs tab opens. If it is refused, call
  without `lat`/`lng` and show a small "Turn on location to see jobs near you"
  prompt when `feed.location` is `city` or `none`.
- Show `distance_km` on each card: "3.4 km away". Hide it when `null`.
- Caption above the list from `feed.categories`: "Jobs for Weaving near you".
- A **Show all jobs** toggle sends `all=1`.

**Search is unchanged.** Any of `q`, `state`, `city`, `category` or `skill`
switches `GET /jobs` to the full search across every job, as before (`lat` /
`lng` / `radius` filter by distance there). Those responses have no `feed`
block and `distance_km: null`.

---

## 2. Home screen 🔒

`GET /worker/dashboard?lat=26.9124&lng=75.7873`: `latest_jobs` is now the top 5
of the same feed, nearest first. Send the phone's position here too.

---

## 3. Wages are monthly

Every wage is **per month** now: what a job pays, what a karigar expects, and
what an applicant asks for.

- `GET /reference` returns `"wage_types": ["monthly"]`. Drop the
  daily/hourly/monthly picker and label wage fields "per month".
- Job cards and pages: show `wage_label` as it is (e.g. "₹20,800 – ₹26,000 /
  monthly"). `wage_type` is always `monthly`.
- **Profile / sign-up:** ask "Expected salary per month". Send
  `{ "expected_wage": 18000, "wage_type": "monthly" }` (or leave `wage_type`
  out).
- **Apply sheet:** `expected_wage` is per month.
- **Employer app, post/edit job:** `wage_min` / `wage_max` per month,
  `wage_type: "monthly"`.

Older app builds still work: a `daily` or `hourly` figure sent to the profile
or job endpoints is stored as its monthly amount (×26 days, ×208 hours).

The existing data was converted the same way: 70 daily jobs, 884 daily/hourly
karigar profiles and 20 applications (e.g. ₹800–1,500 a day → ₹20,800–39,000 a
month).

---

## 4. Verified employers 🔒

`employer.verified` is on every job in `GET /jobs`, `GET /worker/dashboard`
(`latest_jobs`), saved jobs, and `GET /jobs/{job}` (`data.employer.verified`).
It is `true` for a business the admin has verified, and `false` while the admin
has verification switched off.

Show a **✔ Verified** tag next to the employer name on the job card and the job
page when it is `true`; show nothing when it is `false`.

---

## 5. Sign-up: every category

`GET /reference` → `skills` is now **all 14 craft categories**, in display
order, the same list as `job_categories`:

> Bunai / Knitting · Weaving · Kadhai / Embroidery · Painting & Coloring ·
> Pottery / Handmade Pots · Wood Carving · Basket / Cane Work · Tailoring ·
> Decorative Handicrafts · Clay Work · Traditional / Artisan Crafts · Crochet ·
> Handmade Jewellery · Handmade Bags / Accessories

It used to be 40 general trades (Plumbing, Electrician…). Show every item on the
skills step, from the API, not a hard-coded list: the admin can add or rename
categories. The job feed (§1) matches on these, so a karigar's skills must come
from this list.

Karigars who signed up before keep their old skills, which still match jobs
posted under the same names.

---

## Checklist

**Worker app**
- [ ] Jobs list: location permission, `lat`/`lng` on every open, `distance_km` on cards
- [ ] Jobs list: caption from `feed`, "Show all jobs" (`all=1`), location prompt when `feed.location` is `city`/`none`
- [ ] Home: `lat`/`lng` on `GET /worker/dashboard`
- [ ] Wages: "per month" everywhere; no daily/hourly picker; profile, sign-up and apply sheet send monthly figures
- [ ] ✔ Verified tag on job cards and the job page (`employer.verified`)
- [ ] Sign-up skills step shows every `/reference` `skills` item

**Employer app**
- [ ] Post / edit job: wage per month, `wage_type: "monthly"`, no picker
