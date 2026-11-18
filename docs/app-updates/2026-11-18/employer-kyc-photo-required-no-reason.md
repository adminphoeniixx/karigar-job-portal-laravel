# Employer app: KYC needs a photo of every document, no "Why don't you have it?"

QA round of 2026-11-18. Two changes on the business verification screen
(`POST /employer/kyc`).

## 1. Photo / PDF and alternate ID number are now required

Before, an employer could submit with only the document numbers: the photo was
optional, and so was the number of an alternate ID. Now both are required.

| Field | Before | Now (employer) |
|---|---|---|
| `{doc}_doc` (photo / PDF of Aadhaar, PAN, GST) | optional | **required** |
| `{doc}_alt_number` (number of the document sent instead) | optional | **required** |
| `{doc}_alt_doc` (photo of the document sent instead) | required | required (unchanged) |

`{doc}` is `aadhaar`, `pan` or `gst`, whichever the business type asks for.

In these cases the photo is **not** asked for:

- **Aadhaar verified through DigiLocker.** No number and no photo, as before.
- **PAN that matches the one DigiLocker shared.** It is approved on the spot, so
  no photo is needed.
- **Resubmission with a photo already on file** (after a rejection, say). The
  stored photo is kept, so leave the field empty unless they want to replace it.
  `kyc.documents[].has_file` tells you whether one is on file.

Errors come back as a normal 422:

```json
{ "message": "Upload a photo or PDF of this document. (and 2 more errors)",
  "errors": {
    "pan_doc": ["Upload a photo or PDF of this document."],
    "gst_alt_number": ["Enter the number of the document you are sending instead."],
    "gst_alt_doc": ["Upload a photo of the document you are sending instead."] } }
```

**What to change in the app:** drop "(optional)" from the Photo / PDF field and
from the alternate ID's number, and mark both required. `GET /reference` now
says this, so you can read it instead of hard-coding it:

```json
"verification": {
  "photo_required": { "employer": true, "worker": false },
  "alternate_number_required": { "employer": true, "worker": false },
  …
}
```

## 2. "Why don't you have it?" is gone

When a document is marked "I don't have this" (`{doc}_missing: true`), no
reason is asked any more. Remove the text box. `{doc}_reason` is still
accepted if an older build sends it, but it is never required, and admins only
see it when one was sent.

## Test it

Employer 9000000001 / OTP 1234. On the KYC screen, choose "Sole
proprietorship" and submit with numbers but no photos. You should get
`aadhaar_doc`, `pan_doc` and `gst_doc` errors. Then mark GST "I don't have this",
pick Udyam and leave its number empty. You should get `gst_alt_number`.
