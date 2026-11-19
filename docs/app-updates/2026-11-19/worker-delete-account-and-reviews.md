# Worker app: Delete account summary, test login refused, reviews by deleted accounts

Server changes of 19 Nov. Delete account works as before
(`DELETE /account` with `{ "confirm": true }`); these four points are new.

## 1. Optional: what will be lost, for the confirm sheet

```
GET /api/v1/account/deletion        (Bearer token)
```

```json
{
  "role": "worker",
  "can_delete": true,
  "blocked_reason": null,
  "blocked_message": null,
  "team_member": false,
  "plans": [],
  "ai_credits": null,
  "jobs": null,
  "team_members": null,
  "applications": 3,
  "invoices_kept": 0,
  "warnings": [
    "Your 3 job applications are deleted.",
    "Your profile, messages and documents are deleted, and you are signed out everywhere. This cannot be undone."
  ]
}
```

Show `warnings` as a list on the confirm sheet. They are English; for Hindi /
Hinglish, build your own lines from `applications`. When `can_delete` is
`false`, show `blocked_message` and hide the Delete button.

## 2. The test login can't be deleted any more

9000000002 (and the employer test number) are shared by everyone who tests, so
the server refuses to delete them:

```json
// DELETE /account → 403
{ "code": "test_account", "message": "This is a shared test account, so it cannot be deleted. Sign up with your own number to try deleting an account." }
```

Show `message`; the user stays signed in. To test a real deletion, sign up with
your own number.

## 3. `?confirm=1` works too

If your HTTP client drops the body of a DELETE request, send
`DELETE /api/v1/account?confirm=1`. It does the same thing.

## 4. Reviews written by a deleted account

A review stays when the employer who wrote it deletes their account. In
`GET /worker/reviews` it now comes as:

```json
"reviewer": { "id": null, "name": "Former user" }
```

Accept `reviewer.id` being `null`. `job` can be `null` too, because the job
went with the employer's account. That could already happen when an employer
deleted a job.

## No app change needed for

With the account, the server now also deletes the karigar's résumé, KYC
documents, photo and screening call recordings from storage. Before, only the
database rows went.
