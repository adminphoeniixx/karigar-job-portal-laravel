# Employer app: Delete account in Settings

The employer app has no way to delete the account yet. Play Store requires
one inside the app, and our public page (superkarigar.com/delete-account)
already tells users to go to **Settings → Delete account**.

The API existed (`DELETE /account`), but only as one line in the API doc, and
for an employer it was unsafe: the plan's AutoPay kept charging after the
account was gone. On 19 Nov the server was fixed (see "What the server does"
at the end). The app needs three things: a Settings row, a confirm sheet, and
the delete call.

## 1. Settings row

Add **Delete account** as the last row in Settings, in red. Tapping it opens
the confirm sheet (step 2). Nothing is deleted until the user confirms there.

## 2. Confirm sheet: what will be lost

Call this when the sheet opens:

```
GET /api/v1/account/deletion        (Bearer token)
```

```json
{
  "role": "employer",
  "can_delete": true,
  "blocked_reason": null,
  "blocked_message": null,
  "team_member": false,
  "plans": [
    { "name": "Standard", "type": "job", "ends_at": "2026-12-12T06:30:00+00:00", "auto_renew": true }
  ],
  "ai_credits": 196,
  "jobs": { "total": 9, "live": 3 },
  "team_members": 2,
  "applications": null,
  "invoices_kept": 4,
  "warnings": [
    "Your Standard plan stops now and its AutoPay is cancelled. The days left are not refunded.",
    "Your 196 AI call credits are lost.",
    "Your 9 job posts and their applicants are deleted.",
    "2 team members lose access.",
    "Your profile, messages and documents are deleted, and you are signed out everywhere. This cannot be undone.",
    "Your invoices are kept, because tax law requires it."
  ]
}
```

| Field | Meaning |
|---|---|
| `can_delete` | `false`: this login cannot be deleted. Show `blocked_message` and hide the Delete button. |
| `blocked_reason` | `test_account` (the shared test numbers) or `admin_account`; `null` when it can be deleted. |
| `team_member` | `true`: a staff login under someone else's company. Only their own login goes; the company's plan, jobs and credits stay with the owner, so `plans` is empty and `jobs`, `ai_credits`, `team_members` are `null`. |
| `plans` | Plans running now. They stop at once. `auto_renew: true` means AutoPay is on, and the server cancels it. |
| `ai_credits` | Credits that will be lost. |
| `jobs` | All job posts (`total`) and how many are taking applications (`live`). All are deleted, with their applicants. |
| `team_members` | Staff logins that lose access. |
| `invoices_kept` | Invoices that stay (tax law). |
| `warnings` | Ready-made English lines, in order. Show them as a list. For Hindi / Hinglish, build your own lines from the fields above. |

Sheet layout:

- Title: **Delete your account?**
- The `warnings` list.
- A tick box, **"I understand this cannot be undone"**. Keep the red **Delete
  account** button disabled until it is ticked.
- A **Cancel** button.

## 3. Delete

```
DELETE /api/v1/account
Content-Type: application/json

{ "confirm": true }
```

If your HTTP client drops the body of a DELETE request, send
`DELETE /api/v1/account?confirm=1` instead. It does the same thing.

Show a loader on the button. The call can take a few seconds, because the
server stops the plan at Razorpay first.

| Status | Body | What the app does |
|---|---|---|
| `200` | `{ "message": "Your account has been deleted." }` | Step 4. |
| `403` | `{ "code": "test_account", "message": "This is a shared test account, so it cannot be deleted. …" }` | Show `message`. The user stays signed in. |
| `409` | `{ "code": "plan_cancel_failed", "message": "The plan's AutoPay could not be stopped at Razorpay, so nothing was deleted. Please try again in a few minutes." }` | Show `message`. Nothing was deleted; the user can try again. |
| `422` | `{ "errors": { "confirm": ["…"] } }` | The app forgot `confirm`. |

## 4. After a 200

The server has already revoked every token and removed the push tokens.

1. Clear the saved token and every cached employer screen (profile, jobs,
   plans, credits).
2. Don't call `POST /auth/logout`: the token is gone, and it would answer `401`.
3. Go to the login screen with a toast: **"Your account has been deleted."**

The same mobile number can sign up again later. It starts as a brand-new
account with nothing from before.

## Reviews written by a deleted account

A review stays when the account that wrote it is deleted. In
`GET /employer/reviews`, and in a karigar's reviews in the Worker Database,
such a review now comes as:

```json
"reviewer": { "id": null, "name": "Former user" }
```

Accept `reviewer.id` being `null`, and don't link that name to a profile.

## What the server does

So you can answer support questions:

- **Stops the plan first.** Every Razorpay subscription that could still
  charge is cancelled before anything is deleted. If Razorpay doesn't answer,
  the server returns `409` and deletes nothing.
- **Deletes** the profile, the company profile and logo, every job post and
  its applicants, chats, KYC documents, AI credits, team links, push tokens and
  screening call recordings.
- **Keeps** invoices (tax law), with the company name, address and GSTIN
  copied onto them, and reviews the employer wrote about karigars (shown as
  "Former user").
- **Team member:** only their own login is deleted; the company account is
  untouched.

## Test it

- The test numbers (9000000001 employer, 9000000002 karigar) **cannot be
  deleted**. `GET /account/deletion` answers `can_delete: false`, and
  `DELETE /account` answers `403` `test_account`. Use them to test the error
  path. They are protected because everyone tests with their demo data.
- To test a real deletion, sign up a new employer with your own mobile number
  (real OTP), then delete it from Settings. Afterwards the number logs in to an
  empty new account.
