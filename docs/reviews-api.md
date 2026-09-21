# Reviews API — worker app and employer app

Ratings go **both ways**: a worker rates the employer they worked for, and the
employer rates the worker they hired. Two endpoints, same rules, and one thing
that trips everyone up — see [Given vs received](#given-vs-received).

Base URL `{{base_url}}/api/v1`. All four endpoints need the usual
`Authorization: Bearer <token>` and `Accept: application/json`.

---

## The four endpoints

| | Worker app | Employer app |
| --- | --- | --- |
| **Leave a rating** | `POST /applications/{application}/review` | `POST /employer/applicants/{application}/review` |
| **Ratings I received** | `GET /worker/reviews` | `GET /employer/reviews` |

`{application}` is the **application id**, never the job id. Both apps already
have it on every applicant / application row.

---

## Leaving a rating

Same body for both directions:

```jsonc
// POST → 201
{ "rating": 4, "comment": "Payment time pe mila, kaam saaf tha." }

{ "message": "Review submitted.",
  "review": { "id": 11, "rating": 4, "comment": "…",
              "created_at": "2026-09-03T07:00:31+00:00", "created_ago": "0 seconds ago" } }
```

- `rating` — **required**, an integer 1–5.
- `comment` — optional, up to 1000 characters.

### When it fails

| Status | Why | What the app should do |
| --- | --- | --- |
| `403` | The application is not `accepted`. Only a completed hire can be rated. | Don't show the button at all — see [The button](#the-button). |
| `403` | The application isn't yours — not your job (employer) or not your application (worker). | Shouldn't happen from a correct screen. |
| `422` | Already rated this person for this job. Message: *"You have already reviewed this person for this job."* | Show the message; refresh the row so the button disappears. |

**One rating per person, per job.** There is a database constraint behind this
(`reviewer_id` + `reviewee_id` + `job_listing_id` is unique), so a retry after a
flaky network will come back `422`, not a duplicate.

There is no edit and no delete. A rating is final once posted — worth a
confirm step in the UI before sending.

---

## The button

Don't reimplement "accepted and not yet rated". `GET /worker/applications`
returns the answer on every row:

```jsonc
{ "id": 10, "status": "accepted", "can_review": false, "has_reviewed": true }
```

- **`can_review`** — show the "Rate employer" button. `true` only when the
  application is `accepted` **and** not yet rated.
- **`has_reviewed`** — this person has already been rated for this job.

Branch on `can_review` alone. It is the same rule the endpoint enforces, so a
screen driven by it never shows a button that would `403` or `422`.

After a successful post, `can_review` flips to `false` on the next fetch —
refresh the row rather than hiding the button only in local state.

> The employer side does not carry these two flags yet. If the employer app
> needs the same button behaviour, ask and we'll add them to
> `ApplicantResource` the same way.

---

## Reading ratings

Both list endpoints are paginated (15/page, newest first) and carry a summary:

```jsonc
{ "data": [
    { "id": 8, "rating": 4, "comment": "Kaam theek raha.",
      "created_at": "2026-08-25T06:04:01+00:00", "created_ago": "1 week ago",
      "reviewer": { "id": 1321, "name": "Test Employer" },
      "job": { "id": 147, "title": "Carpenter for Furniture Workshop" } } ],
  "links": { … }, "meta": { … },
  "summary": { "average": 4, "count": 1 } }
```

- `reviewer` is **who wrote it**. The person reading the list is the reviewee,
  so they are not named in the payload.
- `job` can be `null` if the job was deleted since.
- `created_ago` is pre-formatted; `created_at` is there for your own formatting.
- Use `summary` for the header ("4.0 ★ · 1 review") rather than computing it
  from `data` — `data` is only the first page.
- An employer's team members all read the **owner's** reviews, matching the rest
  of the employer app.

---

## Given vs received

This is the one that costs an afternoon:

> **`GET /worker/reviews` returns the ratings a worker has *received*, not the
> ones they have written.**

A worker who has rated three employers and been rated by nobody gets
`{"data": [], "summary": {"average": 0, "count": 0}}` — and that is correct, not
a bug. Their ratings live on each employer's `GET /employer/reviews`.

Quick check when a list looks wrong: `GET /auth/me` returns
`rating: { average, count }` for the signed-in user. If that says `count: 0`,
the empty list is real and you are looking at the wrong account or the wrong
direction.

There is **no endpoint for "reviews I have written"**. Nothing in either app
needs one today; say the word if a screen does.

---

## Worked example

```bash
B=https://superkarigar.com

# Worker token (test account, OTP is always 1234)
WT=$(curl -s -X POST "$B/api/v1/auth/otp/verify" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"phone":"9000000002","otp":"1234","role":"worker"}' \
  | python3 -c "import sys,json;print(json.load(sys.stdin)['token'])")

# Which applications can be rated?
curl -s "$B/api/v1/worker/applications" \
  -H "Accept: application/json" -H "Authorization: Bearer $WT" \
| python3 -c "
import sys,json
for r in json.load(sys.stdin)['data']:
    print(r['id'], r['status'], '| can_review:', r['can_review'], '| has_reviewed:', r['has_reviewed'])
"

# Rate the employer on one of them
curl -s -X POST "$B/api/v1/applications/30/review" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $WT" \
  -d '{"rating":4,"comment":"Payment time pe mila."}'

# Sending it twice → 422
# Ratings this worker has received (a different thing entirely)
curl -s "$B/api/v1/worker/reviews" \
  -H "Accept: application/json" -H "Authorization: Bearer $WT"
```

Employer side is the same shape with the employer token:

```bash
curl -s -X POST "$B/api/v1/employer/applicants/9/review" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -H "Authorization: Bearer $ET" \
  -d '{"rating":5,"comment":"Kaam accha kiya, time pe aaye."}'
```

---

## Checklist

- [ ] "Rate employer" button driven by **`can_review`**, not by status alone
- [ ] 1–5 star input; comment optional, capped at 1000 characters
- [ ] Confirm before sending — a rating cannot be edited or deleted
- [ ] Handle `422` (already rated) by refreshing the row, not by retrying
- [ ] Reviews screen header uses `summary`, not a count of `data`
- [ ] Empty list handled as a real state ("No ratings yet"), not an error
- [ ] Remember: `/worker/reviews` is ratings **received**
