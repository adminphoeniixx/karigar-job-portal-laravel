# Super Karigar **Employer app**: contacts, applicant batches, and job + database plans

The changes the **employer** app needs to match the website, plus one small
change for the worker app (§8).

Full reference: `docs/employer-app-api.md` (§6 Applicants, §7 Find Workers,
§7b My contacts, §13 Credits & Plans). Postman:
`docs/karigar-employer-app.postman_collection.json`. Base URL is
`{{base_url}}/api/v1`, auth as before (`Authorization: Bearer <token>`).

**Server status (29 Sep 2026):** §1 (unlocking from Find Workers) is already
live. Everything else ships with the next server deploy.

**Summary of what to change in the app**

| # | Screen | Change | Priority |
|---|---|---|---|
| 1 | Find Workers | Numbers are hidden until unlocked: add an "Unlock contact" button (`can_unlock`) | **Must**. Without it the employer cannot get any number from the database |
| 2 | Worker profile | Same unlock button on the profile (`can_unlock`) | **Must** |
| 3 | Find Workers | Two new tabs: **Database contacts** and **Applicant contacts**, with counts | **Must** |
| 4 | My contacts | Contact lists with filters, call / WhatsApp / email buttons | **Must** |
| 5 | My contacts | Plan banner per plan, and a "hidden until you renew" banner | Should |
| 6 | Applicants | Applicants come in **batches**: show the `access` banner | **Must** |
| 7 | Applicants | No job plan: only a count, with a Plans / Renew button | **Must** |
| 8 | Applicants | `403` on a hidden applicant | **Must** |
| 9 | Credits & Plans | Two groups: **job plans** and **database plans**; two current plans | **Must** |
| 10 | Credits & Plans | `job_plan_lapsed` → Renew prompt | Should |
| 10b | My Jobs | `hiring_paused` → "jobs paused" banner with Renew | **Must** |
| 11 | Credits card | Unlocks renew every cycle (`unlocks_reset_at`); database plan's own allowance | Should |
| 12 | Error handling | `422` with `code: no_plan` / `out_of_credits` on unlock | **Must** |
| 13 | **Worker app**: Apply | `422` with `code: job_not_hiring` | **Must** |

---

## 1. Find Workers: unlock a karigar's number 🔒

**What changed:** the directory used to show the phone number of the first N
results for free (N = the plan's database quota). Changing a filter showed a new
first N, so an employer could see far more numbers than the plan allowed. Now a
number shows only after the karigar is **unlocked**, and an unlock uses one
contact unlock from the plan.

`GET /employer/workers`: every row has two new fields.

```jsonc
{
  "id": 41,                   // worker PROFILE id
  "user_id": 88,
  "name": "Meena Devi",
  "phone": null,              // null until unlocked, and again once database access ends
  "locked": true,
  "contact_unlocked": false,  // new
  "can_unlock": true          // new: show the "Unlock contact" button
  // ...rest unchanged
}
```

The response also has `"contact_counts": { "database_total": 4, "applicants_total": 3 }`
for the tab badges (see §3). `access.has_plan` is true while a job plan **or a
database plan** opens the database.

| Row state | Show |
|---|---|
| `contact_unlocked: true` | The number, with Call / WhatsApp buttons |
| `can_unlock: true` | **Unlock contact** button |
| both false | Locked. "Beyond your plan" if `has_plan`, "Subscribe" if not |

`GET /employer/workers/{worker}`: the profile has the same `contact_unlocked` and
`can_unlock`. Show the unlock button there too.

### `POST /employer/workers/{worker}/unlock`
`{worker}` is the **profile id**.

```jsonc
// 200
{ "message": "Contact unlocked.",
  "worker": { "id": 41, "user_id": 88, "phone": "9876543210", "email": "…", "contact_unlocked": true },
  "credits": { /* CreditSummary: refresh the credits card */ } }

// 422: no plan opens the database
{ "message": "Subscribe to a plan to unlock karigar contacts.", "code": "no_plan" }

// 422: plan allowances and purchased credits all used up
{ "message": "You have reached your plan's contact unlock limit.",
  "code": "out_of_credits", "credits": { /* CreditSummary */ } }
```

On `no_plan` open the Plans screen. On `out_of_credits` offer an upgrade or a
credit top-up. Branch on `code`, not on `message`: the message is English.

A karigar already unlocked costs nothing: unlocking again returns `200`.

---

## 2. How unlocks are counted now

- **Pools.** The job plan and the database plan (§7) each have an unlock
  allowance, renewing on their own billing cycles. A Find Workers unlock spends
  the database plan first, an applicant unlock the job plan first, then the
  other plan, then a purchased credit.
- **No plan, no free unlocks.** Without any plan, unlocks need purchased
  credits. They used to be free and unlimited.
- **Karigars, not clicks.** A karigar is paid for once. If they later apply to
  another job, or were unlocked from the directory first, opening their
  application costs nothing.
- **A number shows while a plan covers it.** When the database access or the
  job plan ends, the numbers behind it hide (§6). Renewing shows them again
  without a new unlock.
- `CreditSummary` has `unlocks_reset_at`, and a `database_plan` block
  (`name`, `limit`, `used`, `remaining`, `renews_at`) when the account holds one.
- **Chat:** the employer can message a karigar unlocked from the directory, not
  only applicants (`POST /conversations` with `worker_id`).

---

## 3. My contacts: two new tabs 🔒

Next to Find Workers, add two tabs. Badge counts come from `contact_counts` on
`GET /employer/workers`, or from `usage` on the lists themselves.

| Tab | Endpoint | Shows |
|---|---|---|
| Find karigars | `GET /employer/workers` | The existing search |
| **Database contacts** (`database_total`) | `GET /employer/contacts/database` | Karigars unlocked from Find Workers |
| **Applicant contacts** (`applicants_total`) | `GET /employer/contacts/applicants` | Applicants to the employer's own jobs whose contact is unlocked |

Both lists use 20 rows per page. Every row has the number, so this is the
employer's phone book.

### Filters

| Param | Lists | Notes |
|---|---|---|
| `q` | both | Name or phone, partial match |
| `skill` | both | A whole skill, any case ("weaving" matches "Weaving") |
| `state`, `city` | both | Exact, from `/reference` |
| `sort` | both | `recent` (default), `oldest`, `name` |
| `period` | database | `all` (default) or `cycle` (unlocked this billing cycle) |
| `job` | applicants | One of the employer's job ids. The response's `jobs` feeds the picker |
| `stage` | applicants | `all`, `pending`, `shortlisted`, `interview`, `hired`, `rejected` |

`filters` in the response echoes what was applied, and is always an object.
An unknown `stage` or `sort` returns `422`.

### Row

```jsonc
{
  "worker_id": 88, "profile_id": 41,       // profile_id opens the worker profile
  "name": "Meena Devi", "avatar_url": "https://…",
  "phone": "9876543210", "email": "…",
  "city": "Jaipur", "state": "Rajasthan",
  "skills": ["Weaving", "Dyeing"], "experience_years": 6,
  "expected_wage": "800.00", "wage_type": "daily",

  // Database contacts only
  "unlocked_at": "2026-09-12T10:04:11+05:30",
  "unlocked_by": "Rahul",                  // who unlocked it: owner or team member

  // Applicant contacts only
  "application_id": 512,
  "job": { "id": 153, "title": "Handloom weaver" },
  "stage": "shortlisted",
  "applied_at": "2026-09-10T18:22:03+05:30"
}
```

Buttons per row, as on the website: **Call** (`tel:`), **WhatsApp**
(`https://wa.me/91<10 digits>`), **Email** (`mailto:`) when present, and
**View profile** (database) or **Applicants** for the job (applicants).

### Banners (`usage`)

```jsonc
{ "plan": "Pro", "database_plan": "Database Basic",
  "limit": 180, "used": 12, "remaining": 168, "purchased": 50,
  "pools": {
    "job":      { "plan": "Pro", "limit": 150, "used": 10, "remaining": 140, "resets_at": "…" },
    "database": { "plan": "Database Basic", "limit": 30, "used": 2, "remaining": 28, "resets_at": "…" }
  },
  "used_database": 8, "used_applicants": 4,
  "has_database_access": true, "has_job_plan": true, "job_plan_lapsed": false,
  "database_total": 23, "applicants_total": 17,
  "database_hidden": 0, "applicants_hidden": 0 }
```

- **Plan banner:** one line per entry in `pools`, e.g. *"**Pro**: 150 contact
  unlocks per month, **10** used, **140** left. Renews 2 Oct 2026."* Then *"This
  cycle: 8 from the database, 4 applicants. Plus 50 purchased credits."*
  `remaining: null` means unlimited.
- `pools` empty: no plan. Show "Subscribe to a job plan or a database plan to
  unlock karigar contacts" with a button to Plans.
- **Hidden banner:** on the Database tab when `database_hidden > 0`: *"Your
  Worker Database access has ended, so N database contacts are hidden. Renew a
  job or database plan to see them again. Karigars you shortlisted or hired stay
  visible."* On the Applicants tab when `applicants_hidden > 0`, the same with
  "Your job plan has ended". Both with a Renew button.

---

## 4. Plans 🔒

`GET /employer/plans` returns two kinds of plan. Every plan has a new `type`:

| Plan | `type` | Price + 18% GST | Job posts / month | Contact unlocks / month | Database access |
|---|---|---|---|---|---|
| Basic | job | ₹499 (₹588.82) | 5 | 25 | 1,000 |
| **Standard** (recommended) | job | ₹999 (₹1,178.82) | 10 | 60 | 3,000 |
| Pro | job | ₹1,999 (₹2,358.82) | 25 | 150 | 10,000 |
| Enterprise | job | ₹4,999 (₹5,898.82) | Unlimited | 500 | 50,000 |
| Database Basic | database | ₹299 (₹352.82) | — | 30 | 2,000 |
| Database Pro | database | ₹799 (₹942.82) | — | 100 | 10,000 |

- Show two groups: **Job plans** (post jobs, see applicants, open the database)
  and **Database plans** (only the Worker Database; they work alone or on top
  of a job plan).
- An account holds at most one plan of each type, so `is_current` can be true
  on two plans. `current` is the job plan, `current_database` the database plan.
- `job_plan_lapsed: true`: the job plan ran out, so jobs are paused and
  applicants hidden (§6). Show a **Renew** prompt.
- Take card text from `feature_list` as it is. Do not hard-code plans or
  their number; the admin can change prices and limits at any time.
- `purchasable: false` means payments are not configured on the server.
  Razorpay plans are created at checkout, so a new plan can be bought straight
  away.

---

## 5. Applicants come in batches 🔒

**What changed:** 100 applicants on a job no longer all show at once. The
employer sees the **first 20** (oldest first), then **15 more** each time every
applicant shown so far has a decision: shortlisted, interview, hired or
rejected. Both numbers are admin settings. A batch once shown stays shown.

`GET /employer/jobs/{job}/applicants` has a new `access` block, and `data` and
`counts` now cover only the visible applicants:

```jsonc
{
  "data": [ /* ApplicantResource, visible ones only */ ],
  "counts": { "all": 20, "pending": 14, "shortlisted": 4, "interview": 0, "hired": 1, "rejected": 1 },
  "access": {
    "total": 40,        // everyone who applied
    "visible": 20,
    "hidden": 20,
    "reason": "batch",  // null | batch | no_plan | plan_expired
    "undecided": 14,    // batch: shown applicants still without a decision
    "next_batch": 15    // batch: how many open next
  }
}
```

| `reason` | Banner |
|---|---|
| `null` | None |
| `batch` | "Showing 20 of 40 applicants. Shortlist, hire or reject the 14 still open to see the next 15." |
| `no_plan` | "20 applicants are waiting. Subscribe to a plan to see them." + **View plans** |
| `plan_expired` | "Your plan has ended, so 20 applicants are hidden. Renew to see them again; shortlisted and hired applicants stay visible." + **Renew plan** |

Refresh the list after a shortlist, hire or reject: that is what opens the next
batch.

---

## 6. When a plan ends 🔒

| Plan that ended | What happens | What stays |
|---|---|---|
| **Job plan** | Applicants hidden (`reason: plan_expired`). The employer's jobs are **paused**: out of search, and karigars cannot apply (§8). Applicant contacts hidden (`applicants_hidden`). | Applicants the employer shortlisted, interviewed or hired, with their numbers if unlocked |
| **Database access** (no job or database plan left) | Find Workers numbers hidden, `can_unlock: false` on karigars unlocked before, Database contacts hidden (`database_hidden`) | Karigars the employer shortlisted or hired |

An employer who **never** had a job plan (the free first post) sees applicant
counts only (`reason: no_plan`), but its job is not paused.

**My Jobs:** `GET /employer/jobs` returns `hiring_paused: true` while the job
plan has run out. Show a banner above the list: *"Your job plan has ended, so
your live jobs are paused: karigars can't find them or apply. Renew to open them
again."* with a **Renew plan** button. The home screen's `recent_applicants`
already leaves hidden applicants out.

Renewing brings everything back: jobs return to search, applicants and numbers
show again, and nothing needs unlocking a second time.

**`403` on a hidden applicant.** Every `{application}` route (`GET
/employer/applicants/{id}`, `/resume`, `/status`, `/shortlist`, `/unlock`,
`/interview`, `/screening-calls`) returns `403` with *"This applicant is not
visible on your plan yet."* for an applicant the employer cannot see. The lists
never include one, so this only happens with a stale screen or a notification
tap: refresh the list.

---

## 7. Database plans 🔒

For employers who hire straight from the Worker Database, or need more of it
than their job plan gives. Buy them like job plans (`POST
/employer/plans/{plan}/subscribe` with the database plan's id). They:

- open the Worker Database: their access adds to the job plan's (Database Basic
  on top of Pro = 10,000 + 2,000);
- bring their own unlocks, spent first on Find Workers karigars;
- post no jobs and show no applicants.

---

## 8. Worker app: applying to a paused job

`POST /jobs/{job}/apply` on a job whose employer's plan ran out returns:

```json
{ "message": "This job is not taking applications right now.", "code": "job_not_hiring" }
```

with status `422`. Show the message. Paused jobs also drop out of job search;
a job already open on screen (saved, or from a notification) can still hit
this.

---

## Checklist

**Employer app**
- [ ] Find Workers: unlock button on `can_unlock` rows; number on `contact_unlocked` rows
- [ ] Worker profile: unlock button
- [ ] `no_plan` / `out_of_credits` handling on unlock
- [ ] Tabs: Find karigars / Database contacts / Applicant contacts, with counts
- [ ] Both contact lists with filters, pagination, and Call / WhatsApp / Email
- [ ] Plan banner per pool, and the hidden-contacts banner
- [ ] Applicants: `access` banner for `batch`, `no_plan`, `plan_expired`; refresh after decisions
- [ ] Applicants: handle `403` on a hidden applicant
- [ ] Plans screen: job and database groups, any number of plans, `feature_list` text
- [ ] Plans screen: two current plans; Renew prompt on `job_plan_lapsed`
- [ ] My Jobs: paused banner on `hiring_paused`
- [ ] Credits card: `unlocks_reset_at` and the `database_plan` allowance

**Worker app**
- [ ] Apply: show the `job_not_hiring` message
