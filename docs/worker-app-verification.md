# Worker app: KYC verification

This covers what changed in the worker app's KYC screen and what to build.
Base URL `/api/v1`. Every route here needs the Sanctum token.

## What's new

1. Aadhaar + PAN are still asked for, as before.
2. Either one can now be marked **"I don't have this"**. The worker then
   sends a different ID, a photo of it and a reason. An admin checks it by
   hand.
3. The admin's approve/reject decision reaches the worker as a push
   notification (type `kyc.reviewed`).
4. KYC stays **optional** for workers. A verified worker gets a badge and
   nothing else changes.

Both KYC routes still return **`404` while `features.verification_enabled` is
`false`** (from `GET /worker/dashboard`). That is the admin's on/off switch, not
an error: hide every KYC screen while it is off.

---

## 1. Data for the screen: `GET /reference` → `verification`

Build the screen from this data instead of hard-coding it.

```json
"verification": {
  "worker_documents": ["aadhaar", "pan"],
  "documents": [
    { "key": "aadhaar", "label": "Aadhaar card", "number_field": "aadhaar_number",
      "pattern": "^\\d{12}$", "hint": "12 digits",
      "alternates": [ { "key": "voter_id", "label": "Voter ID" }, { "key": "driving_licence", "label": "Driving licence" },
                      { "key": "passport", "label": "Passport" }, { "key": "ration_card", "label": "Ration card" },
                      { "key": "other", "label": "Other government ID" } ] },
    { "key": "pan", "label": "PAN card", "number_field": "pan_number",
      "pattern": "^[A-Z]{5}[0-9]{4}[A-Z]$", "hint": "ABCDE1234F",
      "alternates": [ { "key": "form_60", "label": "Form 60 (no PAN declaration)" }, { "key": "voter_id", "label": "Voter ID" },
                      { "key": "driving_licence", "label": "Driving licence" }, { "key": "passport", "label": "Passport" },
                      { "key": "other", "label": "Other government ID" } ] },
    { "key": "gst", ... }
  ],
  "business_types": [ ... ]
}
```

The worker app needs only `worker_documents` and the matching entries in
`documents`. Ignore `gst` and `business_types`; they belong to the employer
app.

- `number_field`: name of the multipart field that carries the number
- `pattern`: client-side validation
- `hint`: placeholder text
- `alternates`: options for the "Document you have" dropdown

---

## 2. `GET /kyc`

Before the first submission:

```json
{ "kyc": null, "required_documents": ["aadhaar", "pan"] }
```

After a submission:

```json
{
  "kyc": {
    "status": "pending",
    "status_label": "Pending review",
    "masked_pan": null,
    "masked_aadhaar": "XXXX XXXX 9012",
    "business_type": null,
    "documents": [
      { "type": "aadhaar", "label": "Aadhaar card", "missing": false,
        "number": "XXXX XXXX 9012", "has_file": true, "alternate": null },
      { "type": "pan", "label": "PAN card", "missing": true,
        "number": null, "has_file": false,
        "alternate": { "type": "voter_id", "label": "Voter ID", "number": "XXXXXX4567",
                       "has_file": true, "reason": "Never applied for a PAN" } }
    ],
    "has_missing_documents": true,
    "remarks": null,
    "reviewed_at": null,
    "submitted_at": "2026-10-01T09:30:00+05:30"
  },
  "required_documents": ["aadhaar", "pan"]
}
```

- `status` is one of `pending`, `verified` or `rejected`. When it is
  `rejected`, show `remarks` as the reason.
- `documents[]` shows what is on record. Each number comes back masked.
- The old `masked_pan` / `masked_aadhaar` fields are still returned. Build new
  screens from `documents[]`.

---

## 3. `POST /kyc` (`multipart/form-data`)

For **each document** (`aadhaar`, `pan`), send **one** of these two shapes.

### A. "I have it"

| Field | Rule |
| --- | --- |
| `aadhaar_number` / `pan_number` | required. Spaces are stripped and letters are upper-cased on the server. |
| `aadhaar_doc` / `pan_doc` | jpg / png / pdf, ≤ 5 MB. Required on the **first** submission. On a resubmission, leave it out to keep the file already on record. |

### B. "I don't have it"

| Field | Rule |
| --- | --- |
| `{doc}_missing` | `1` |
| `{doc}_alt_type` | required. A `key` from that document's `alternates`. |
| `{doc}_alt_number` | optional, ≤ 50 characters |
| `{doc}_alt_doc` | jpg / png / pdf, ≤ 5 MB. Required the first time. |
| `{doc}_reason` | required, ≤ 500 characters: why they don't have the document |

(`{doc}` = `aadhaar` or `pan`.) When `{doc}_missing=1`, the server ignores that
document's number and photo.

### Example: has Aadhaar, has no PAN

```text
aadhaar_number=123456789012
aadhaar_doc=@aadhaar.jpg
pan_missing=1
pan_alt_type=voter_id
pan_alt_number=XYZ1234567
pan_alt_doc=@voter.jpg
pan_reason=Never applied for a PAN
```

→ `201 { "message": "KYC submitted for review.", "kyc": { ...same shape as GET } }`

Validation errors come back as `422`. Each error is keyed by its field name,
for example `pan_reason` or `aadhaar_doc`.

Every submission (first or resubmit) puts the status back to `pending`.

---

## 4. Notification: `kyc.reviewed`

The worker gets this when an admin approves or rejects. It arrives as an FCM
push and also in `GET /notifications`.

```json
{ "type": "kyc.reviewed", "status": "verified", "url": "/kyc" }
```

- `verified`: "Your documents are verified. Your profile now shows the
  verified badge."
- `rejected`: the body carries the admin's reason. Open the KYC screen
  pre-filled.

On this push, refetch `GET /kyc`.

---

## 5. Screen to build

**KYC screen**

- One card each for Aadhaar and PAN: a number field plus a photo picker.
- An **"I don't have this"** toggle on each card. When it's on, replace the
  card's contents with:
  - a "Document you have" dropdown (`alternates`)
  - its number (optional)
  - a photo of it
  - "Why don't you have it?" (text)
- At the top, a status card from `GET /kyc`: Under review, Verified, or
  Rejected with the reason.
- Rejected → show the form again pre-filled. Photos already on record need
  not be uploaded again.
- Hide the whole screen while `features.verification_enabled` is `false`.

Postman: `docs/karigar-worker-app.postman_collection.json` → "KYC (optional)" → "Submit KYC". It
has disabled rows for the "I don't have it" case; enable them to try it.
