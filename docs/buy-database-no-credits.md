# Contact credits removed: "Buy Database" instead (Oct 2026)

The credits system is gone. Karigar phone numbers are unlocked only through a
plan. The employer buys the **Worker Database** as its own plan. Each database
plan opens a set number of karigar contacts to browse, and the employer can
unlock some of them each month. Unlocking shows that karigar's phone number.

Example: **Database Basic** opens 1,000 contacts, and 50 of them can be
unlocked a month.

This applies to the employer app (API), the web, and the HTML mockup.

---

## 1. Database plans

| Plan | Price (before GST) | Contacts in database | Unlocks / month |
| --- | --- | --- | --- |
| Database Basic | ₹299 | 1,000 | 50 |
| Database Standard (most popular) | ₹599 | 3,000 | 125 |
| Database Pro | ₹999 | 10,000 | 300 |

- Defined in `database/seeders/PlanSeeder.php` (`type: database`). Admin can change prices and limits in **Admin → Plans**.
- Unlocks renew every billing cycle. A karigar unlocked once stays unlocked and never costs again.
- A database plan works on its own or alongside a job plan. Worker Database unlocks spend the database plan first, applicant unlocks spend the job plan first.
- Job plans (Basic / Standard / Pro) still include some database access as before.
- No plan = no unlocks.

## 2. What was removed

| Removed | Notes |
| --- | --- |
| Purchased credits / top-ups | `POST /employer/credits/top-up`, `POST /employer/credits/callback` now `404` |
| Job boost | `POST /employer/jobs/{job}/boost` now `404`; boost ran on credits. A job's `boost` field stays in the payload but never turns active |
| `credit_packs`, `boost_tiers` | Gone from `GET /employer/plans`, `GET /reference` and `config/billing.php` |
| `credits` field in responses | Renamed to `unlocks` (same shape minus `balance`, `purchased`, `plan_label`) |
| `422` code `out_of_credits` | Now `unlock_limit_reached` |
| `App\Models\CreditPurchase` | Model deleted. The `credit_purchases` table and `employer_profiles.credit_balance` column are left in place as history |

## 3. Employer app: what to change

### Home card

Replace the "0 contact credits / Free plan · unlock karigar numbers / Buy" card.
`GET /employer/dashboard` now returns `database`:

```json
// no plan that opens the database
"database": {
  "active": false, "plan": null, "plan_type": null, "contacts": 0,
  "unlock_limit": 0, "unlocks_used": 0, "unlocks_remaining": 0, "renews_at": null,
  "title": "Worker Database",
  "subtitle": "Buy a database plan to see karigar numbers",
  "cta": "Buy Database"
}

// with Database Basic
"database": {
  "active": true, "plan": "Database Basic", "plan_type": "database",
  "contacts": 1000, "unlock_limit": 50, "unlocks_used": 12, "unlocks_remaining": 38,
  "renews_at": "2026-11-06T10:00:00+05:30",
  "title": "1,000 karigar contacts",
  "subtitle": "38 of 50 unlocks left · Database Basic",
  "cta": "View plans"
}
```

- Show `title`, `subtitle` and a button labelled `cta` exactly as sent.
- `cta` becomes `"Upgrade"` when `unlocks_remaining` is `0`.
- `plan_type: "job"` means no database plan is held and the job plan's own database access is shown.
- `unlocks_remaining: null` means the plan does not meter unlocks.
- Tapping the card or the button opens the plans screen on the **database plans**.

### Plans screen ("Buy Database")

- `GET /employer/plans`: show the plans with `type: "database"` first when opened from "Buy Database". Use each plan's `feature_list` lines as they are.
- The response now has `unlocks` and `database` (the same card). `credits`, `credit_packs` and `boost_tiers` are gone.
- Buying is unchanged: `POST /employer/plans/{plan}/subscribe`, then `POST /employer/plans/callback` (which now returns `unlocks` + `database`).
- Remove the "Just need a few unlocks? +25 credits" top-up row.

### Boost

Remove the "Boost job" action, the Boost button on Manage Job, and the Boost sheet.

### Unlocking a number

Applies to `POST /employer/applicants/{application}/unlock`,
`POST /employer/workers/{worker}/unlock` and `GET /employer/workers`:

- Read `unlocks` instead of `credits`. `GET /employer/workers` also returns `database`.
- `422 unlock_limit_reached`: show the message and offer the database plans (upgrade).
- `422 no_plan`: unchanged, open the database plans.

**UnlockSummary** (`unlocks`):

```json
{ "unmetered": false, "plan_limit": 75, "plan_remaining": 40, "unlocks_used": 35,
  "unlocks_reset_at": "2026-08-28T10:00:00+05:30", "plan": "Basic",
  "database_plan": { "name": "Database Basic", "limit": 50, "used": 12, "remaining": 38,
                     "renews_at": "2026-10-10T09:00:00+05:30" },
  "directory_quota": 2000 }
```

## 4. Web

- **Employer dashboard**: new "Worker Database" card under the stat tiles, the same data as the app (`database` prop). Its button ("Buy Database" / "View plans" / "Upgrade") goes to `/subscription#database`.
- **Pricing page** (`/subscription`): the database plans section has `id="database"` and scrolls into view when opened with `#database`.
- **Find Workers**: the no-plan banner says "Buy a database plan…" with a **Buy Database** button. The upgrade link and the worker profile's "View plans" also go to the database plans.
- **Find Workers / Contacts**: purchased-credit counts removed.
- **Help Centre**: the "What are credits for?" FAQ is replaced by "How do I see karigar phone numbers?".
- **Terms**: "Plans, credits and payments" is now "Plans and payments", with no mention of credits.
- Dashboard strings added in all 8 languages (`dashboard.workerDatabase`, `buyDatabase`, `databaseContacts`, `databaseUnlocksLeft`, …).

## 5. Backend

- `App\Services\CreditWallet` renamed to **`App\Services\ContactUnlocks`**. Purchased credits (`purchased`, `spend`, `add`, `balance`) are removed.
- New `ContactUnlocks::database()` builds the Worker Database card used by the API and the web.
- Changed files:
  - `Api/Employer/{Dashboard,Billing,Job,Applicant,WorkerDirectory}Controller`
  - `Api/ReferenceController`
  - `DashboardController`
  - `Employer/{WorkerDirectory,Applicant}Controller`
  - `ContactList`
  - `routes/api.php`
  - `config/billing.php`
  - `HelpCentre`
  - `LegalDocuments`
  - `PlanSeeder`
- Mockup: `app-mockup/employer-app.html`. The home card and the "Buy Database" screen show the three database plans. Boost and top-up are removed.
- Docs: `docs/employer-app-api.md` §13 ("Plans & Worker Database"), the Postman collection (top-up and boost requests removed), `docs/app-changes-employer-2026-10.md` §4.

## 6. Tests

- `EmployerAppApiTest`: the dashboard shows the `database` card, not `credits`; the top-up and boost routes return `404`.
- `PlanAccessRulesTest`: the card is filled from the database plan and switches to "Upgrade" when unlocks run out; no plan → `unlock_limit_reached`.
- `PlanCatalogueTest`: three database plans are seeded (1,000 / 50 on Basic).
- `WorkerDirectoryUnlockTest`: reads `unlocks` and `unlock_limit_reached`.

## 7. Deploy

1. Deploy the code first.
2. **Then** seed the database plans on the server:
   ```
   php artisan db:seed --class=PlanSeeder
   ```
   Do not seed before the deploy. The old code reads a database plan's `job_post_limit: 0` as unlimited job posts and would sell it as a job plan.
3. No migration needed. No columns were added or dropped.
4. Ship the app update in the same release. The old build reads `credits`, which no longer exists.
