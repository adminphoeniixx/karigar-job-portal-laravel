# Super Karigar **Worker app**: leaving DigiLocker without sharing shows a plain message

**What was wrong:** a karigar who left DigiLocker without finishing (closed
it, or didn't tap **Allow** on the consent screen) got Decentro's developer
error:

> The provided initial_decentro_transaction_id is not valid or the session
> associated with it has expired. Please provide a valid
> initial_decentro_transaction_id or initiate a new session and try again.

**What it is now:** the return page and `GET /kyc/digilocker/{reference}`
say:

> DigiLocker did not share your Aadhaar. Try again and tap "Allow" at the
> end, or type your Aadhaar number instead.

`status` is `error` as before, so a new attempt is `POST /kyc/digilocker` again.

**App change needed:** none if the app already shows `message` when
`status` is not `passed`. If it shows its own text for `error`, show
`message` instead, and offer **Try again** and **Type Aadhaar number**.
