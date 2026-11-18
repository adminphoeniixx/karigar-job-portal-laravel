# Employer app: "Finish setup" refuses a blank profile

QA round of 2026-11-18. QA tapped "Finish Setup" with every field blank and the
app let them through. There is a "Skip for now" button for skipping, so
"Finish Setup" should insist on a complete profile.

## What changed on the server

`PUT/PATCH /employer/profile` takes a new flag, **`finish: true`**. Send it only
from the last sign-up step's "Finish Setup" button. Then the profile must have
all of these, either sent in this request or saved by an earlier step:

| Field | Message when missing |
|---|---|
| `name` (contact person) | Enter your name. |
| `hiring_as` | Choose who you are hiring as. |
| `company_name` (**not** asked when `hiring_as` is `individual`) | Enter your business name. |
| `industry` | Choose your industry. |
| `hiring_categories` (at least one) | Pick at least one kind of karigar you hire. |
| `city` | Choose your city. |
| `state` | Choose your state. |

Otherwise you get a 422 with one error per missing field:

```json
{ "message": "Enter your name. (and 6 more errors)",
  "errors": { "name": ["Enter your name."], "hiring_as": ["Choose who you are hiring as."], … } }
```

Without `finish`, nothing changes. Each step can still save just its own
fields, and **"Skip for now" must not send `finish`**. Edit Business Profile
doesn't send it either.

## What to change in the app

1. "Finish Setup" → send `finish: true` along with the last step's fields.
2. On 422, show the errors **at the top of the screen** (that was QA's other
   point) and take the employer back to the step that holds the first missing
   field.
3. Better still, check the same fields in the app before calling the server, so
   the button can say what is missing without a round trip.

## "Usually Hiring For" shows something else in Edit Business Profile

QA's account is employer profile 21, saved on 17 Nov at 11:30 IST. The server
stored `hiring_categories: ["Electrician"]` and returns exactly that from
`GET /employer/profile`. The server keeps and returns what the app sends, so
the mismatch is in the app: either sign-up sends a different value from the
one shown as picked, or Edit Profile shows its own list instead of
`hiring_categories`. Please check both screens use the **same category names**
from `GET /reference` → `job_categories` (as `hiring_categories`).
