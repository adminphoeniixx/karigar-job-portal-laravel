# Employer app: tapping an applicant opens someone else's profile

**Bug, app side.** Fix needed in the app. The server change below only adds a field.

## What happens

On the applicants screen the employer taps an applicant to see their full
profile. The app calls:

```
GET /api/v1/employer/workers/{applicant.worker.id}
```

`applicant.worker.id` is the karigar's **user id**. `GET /employer/workers/{worker}`
takes a **worker profile id**. The two numbers are different, so the app opens a
**different karigar**: wrong name, empty profile (`expected_wage`, `city`,
`skills` all `null`) and `phone: null`, `contact_unlocked: false`, as if the
number were locked.

Real case, from the live logs (employer 7905412252):

| | id the app sent | who it opened |
|---|---|---|
| Applicant "atul sachan" | `worker.id = 1309` (user id) | profile 1309 = "Worker 3225", who never applied, so locked and empty |
| Correct call | `worker.profile_id = 1294` | atul sachan: Kanpur, ₹2,08,000, phone shown, `contact_unlocked: true` |

An applicant's number is **always** shown, with no unlock. If an applicant's
profile ever shows `contact_unlocked: false`, the app has opened the wrong
karigar.

## Server change (live with the next deploy)

Every applicant now carries `worker.profile_id`:

```json
"worker": {
  "id": 1309,            // user id: keep using it for chat, invites, etc.
  "profile_id": 1294,    // NEW: the id for GET /employer/workers/{worker}
  "name": "atul sachan",
  ...
}
```

It is on every response that returns an applicant:

- `GET /employer/jobs/{job}/applicants`
- `GET /employer/applicants/{application}`
- `GET /employer/shortlisted`
- `GET /employer/dashboard` → `recent_applicants`
- the `applicant` returned by status / shortlist / interview actions

`profile_id` is `null` only for a karigar with no profile yet (rare). In that
case don't open the profile screen; show the applicant card's own data.

## What to change in the app

1. **Applicant profile:** call `GET /employer/workers/{worker.profile_id}`, not
   `{worker.id}`.
2. **Better still:** the applicant detail already has everything the profile
   screen needs (name, phone, email, wage, skills, resume, AI score, screening
   call). Use `GET /employer/applicants/{application.id}` for an applicant and
   keep `GET /employer/workers/{id}` for Worker Database and matches cards.
3. **Database / matches cards are fine as they are:** there, `id` is already the
   profile id and `user_id` is the user id.

Which id goes where:

| Card | open profile with | user id (chat, invite) |
|---|---|---|
| Applicant | `worker.profile_id` | `worker.id` |
| Worker Database / matches | `id` | `user_id` |
| Database contacts list | `profile_id` | `worker_id` |

## Also seen in the logs (small)

The employer app logs in with `device_name: "Super Karigar Worker"`, so employer
tokens are named as if they came from the worker app. Send something like
`"Super Karigar Employer"` from the employer app. Nothing breaks today; it only
makes the token list confusing when debugging.

## Check

Log in as employer 7905412252, open job "Electrician" → applicant atul sachan →
profile. Expect name "atul sachan", phone shown, `contact_unlocked: true`.
