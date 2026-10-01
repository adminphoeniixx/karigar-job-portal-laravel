# Employer app: Business verification

This covers what changed in the employer app's verification and what to build.
Base URL `/api/v1`. Every route here needs the Sanctum token.

## What's new

1. The employer first picks a **business type**, then fills in the **company
   details** (legal name + registered address).
2. Which documents are asked for **depends on the business type** (table
   below).
3. Any document can be marked **"I don't have this"**. The employer then sends
   an alternate document, a photo of it and a reason. An admin checks it by
   hand.
4. **An employer has to be verified before a job goes live.** Saving a draft
   still works. Publishing while unverified gets `422` with
   `code: "verification_required"`.
5. The admin's approve/reject decision reaches the employer as a push
   notification (type `kyc.reviewed`).

Both KYC routes still return **`404` while `features.verification_enabled` is
`false`**. That is the admin's on/off switch, not an error: hide every
verification screen while it is off.

---

## 1. Which documents for which business type

| Business type (`business_type`) | Documents |
| --- | --- |
| `individual`: hiring for myself | `aadhaar`, `pan` (personal) |
| `proprietorship`: sole proprietor | `aadhaar`, `pan` (owner's), `gst` |
| `partnership`: partnership firm | `pan` (the firm's), `gst` |
| `llp` | `pan`, `gst` |
| `private_limited` | `pan` (the company's), `gst` |
| `public_limited` | `pan`, `gst` |
| `other`: trust / society / other | `pan`, `gst` |

**Don't hard-code this table.** Read it from `GET /reference`, under the
`verification` key, so changes don't need an app release.

## 2. Data for the screen: `GET /reference` → `verification`

```json
"verification": {
  "business_types": [
    { "key": "individual", "label": "Individual (hiring for myself)", "documents": ["aadhaar", "pan"] },
    { "key": "proprietorship", "label": "Sole proprietorship", "documents": ["aadhaar", "pan", "gst"] },
    { "key": "partnership", "label": "Partnership firm", "documents": ["pan", "gst"] },
    { "key": "llp", "label": "LLP", "documents": ["pan", "gst"] },
    { "key": "private_limited", "label": "Private limited company", "documents": ["pan", "gst"] },
    { "key": "public_limited", "label": "Public limited company", "documents": ["pan", "gst"] },
    { "key": "other", "label": "Trust / Society / Other", "documents": ["pan", "gst"] }
  ],
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
    { "key": "gst", "label": "GST certificate", "number_field": "gstin",
      "pattern": "^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][A-Z0-9]{3}$", "hint": "22ABCDE1234F1Z5",
      "alternates": [ { "key": "udyam", "label": "Udyam registration" },
                      { "key": "shop_establishment", "label": "Shop & establishment licence" },
                      { "key": "trade_licence", "label": "Trade licence" },
                      { "key": "incorporation", "label": "Certificate of incorporation / partnership deed" },
                      { "key": "other", "label": "Other business proof" } ] }
  ],
  "worker_documents": [ ... ]
}
```

(`worker_documents` belongs to the worker app. Ignore it.)

- `business_types[].documents`: which document cards to show after a business
  type is picked
- `number_field`: name of the multipart field that carries the number
- `pattern`: client-side validation
- `hint`: placeholder text
- `alternates`: options for the "Document you have" dropdown

---

## 3. `GET /employer/kyc`

```json
{
  "business": {
    "business_type": "proprietorship",
    "legal_name": "Ramesh Furniture Works",
    "registered_address": "Plot 4, Basni, Jodhpur 342005",
    "company_name": "Ramesh Furniture"
  },
  "gstin": null,
  "required_documents": ["aadhaar", "pan", "gst"],
  "kyc": {
    "status": "pending",
    "status_label": "Pending review",
    "business_type": "proprietorship",
    "documents": [
      { "type": "aadhaar", "label": "Aadhaar card", "missing": false,
        "number": "XXXX XXXX 9012", "has_file": true, "alternate": null },
      { "type": "pan", "label": "PAN card", "missing": false,
        "number": "ABXXXXXK", "has_file": true, "alternate": null },
      { "type": "gst", "label": "GST certificate", "missing": true,
        "number": null, "has_file": false,
        "alternate": { "type": "udyam", "label": "Udyam registration", "number": "XXXXXXXXXXXXXXX2345",
                       "has_file": true, "reason": "Turnover is below the GST limit" } }
    ],
    "has_missing_documents": true,
    "remarks": null,
    "reviewed_at": null,
    "submitted_at": "2026-10-01T09:30:00+05:30"
  },
  "verification": {
    "required": true,
    "status": "pending",
    "can_post_jobs": false,
    "message": "Your business verification is under review. You can post jobs once it is approved — save this one as a draft for now."
  }
}
```

- `kyc` is `null` until the first submission. `verification.status` is then
  `not_submitted`.
- `verification.status` is one of `not_submitted`, `pending`, `rejected` or
  `verified`. When it is `rejected`, show `kyc.remarks` as the reason.
- `required_documents` reflects only the **saved** business type. When the
  user changes the business type on screen, take the documents from
  `/reference`.

---

## 4. `POST /employer/kyc` (`multipart/form-data`)

### Business details (always)

| Field | Rule |
| --- | --- |
| `business_type` | required. A `key` from `business_types`. |
| `legal_name` | required, ≤ 150. For `individual`, label it "Full name (as on PAN)". |
| `registered_address` | required, ≤ 500. For `individual`, label it "Address". Pre-fill it from the profile address. |

### For each document of the chosen type, send **one** of these two shapes

**A. "I have it"**

| Field | Rule |
| --- | --- |
| `aadhaar_number` / `pan_number` / `gstin` | required. Format from `pattern`. Spaces are stripped and letters are upper-cased on the server. |
| `aadhaar_doc` / `pan_doc` / `gst_doc` | jpg / png / pdf, ≤ 5 MB. Required on the **first** submission. On a resubmission, leave it out to keep the file already on record. |

**B. "I don't have it"**

| Field | Rule |
| --- | --- |
| `{doc}_missing` | `1` |
| `{doc}_alt_type` | required. A `key` from that document's `alternates`. |
| `{doc}_alt_number` | optional, ≤ 50 characters |
| `{doc}_alt_doc` | jpg / png / pdf, ≤ 5 MB. Required the first time. |
| `{doc}_reason` | required, ≤ 500 characters: why they don't have the document |

(`{doc}` = `aadhaar`, `pan` or `gst`.) When `{doc}_missing=1`, the server
ignores that document's number and photo.

**GSTIN ↔ PAN check:** characters 3–12 of a GSTIN are the holder's PAN. When
they don't match, the response is `422` on `gstin`: "This GSTIN does not
belong to the PAN given above."

### Example 1: private limited company

```text
business_type=private_limited
legal_name=Sri Sai Interiors Pvt Ltd
registered_address=12 MG Road, Bengaluru 560001
pan_number=AAFCS1234K
pan_doc=@pan.jpg
gstin=29AAFCS1234K1Z5
gst_doc=@gst.pdf
```

### Example 2: proprietor without GST who has Udyam

```text
business_type=proprietorship
legal_name=Ramesh Furniture Works
registered_address=Plot 4, Basni, Jodhpur 342005
aadhaar_number=123456789012
aadhaar_doc=@aadhaar.jpg
pan_number=ABCPR1234K
pan_doc=@pan.jpg
gst_missing=1
gst_alt_type=udyam
gst_alt_number=UDYAM-RJ-17-0012345
gst_alt_doc=@udyam.pdf
gst_reason=Turnover is below the GST limit
```

→ `201`, with the same body as `GET /employer/kyc` plus a `"message"`.

Every submission (first or resubmit) puts the status back to `pending`.

The business details also come back on `GET /employer/profile` (`business_type`,
`legal_name`, `registered_address`).

> **Breaking change:** `business_type`, `legal_name` and `registered_address`
> are now **required**. The current build, which sends only
> `gstin + pan_number`, will get `422`.

---

## 5. No job goes live without verification

The admin setting **"Employers must be verified to post jobs"** is on by
default. While it is on:

| Action | Unverified employer gets |
| --- | --- |
| `POST /employer/jobs` with `status=active` | `422 { "message": "...", "code": "verification_required" }` |
| `PATCH /employer/jobs/{id}` publishing a draft | same |
| `POST /employer/jobs/{id}/repost` | same |
| Save a draft (`status=draft`) | ✅ allowed |
| Jobs already live, applicants, chat, Find Workers | ✅ unaffected |

All of these `422`s now carry a `code`. Branch on the `code`, not on the
message:

| `code` | What to show |
| --- | --- |
| `verification_required` | the message plus a **"Verify business"** button → verification screen |
| `subscription_required` | the plans screen |
| `post_limit_reached` | upgrade the plan / save as a draft |
| `cannot_repost` | just the message |

### Dashboard: `GET /employer/dashboard`

Now carries these:

```json
"features": { "verification_enabled": true, "employer_verification_required": true },
"verification": {
  "required": true,
  "status": "not_submitted",
  "can_post_jobs": false,
  "message": "Verify your business (PAN / GST) to post jobs. You can save this one as a draft meanwhile."
}
```

When `verification.can_post_jobs` is `false`, show a banner with the
`message` on the **Home** screen and the **Post Job** screen. Don't wait for the
publish to fail. Keep the **Save draft** button enabled.

---

## 6. Notification: `kyc.reviewed`

The employer gets this when an admin approves or rejects. It arrives as an FCM
push and also in `GET /notifications`.

```json
{ "type": "kyc.reviewed", "status": "verified", "url": "/kyc" }
```

- `verified`: "Your business is verified. You can post jobs now."
- `rejected`: the body carries the admin's reason. Open the verification
  screen pre-filled.

On this push, refetch `GET /employer/dashboard` so the Post Job banner goes
away.

---

## 7. Screens to build or change

**Business verification screen**

1. **Business type** picker (from `/reference` → `business_types`).
2. **Legal name** + **Registered address**.
3. One card per entry in that type's `documents`: a number field plus a photo
   picker.
4. An **"I don't have this"** toggle on each card. When it's on, replace the
   card's contents with: "Document you have" dropdown (`alternates`), its
   number (optional), a photo, and "Why don't you have it?".
5. Submit → status card (Under review / Verified / Rejected + reason).

**Home + Post Job**

- A banner whenever `verification.can_post_jobs` is `false`, with a button to
  the verification screen.
- On `422` with `code=verification_required`: show the message plus a "Verify
  business" button. Save the job as a draft; don't lose what was typed.

**Everywhere**

- Handle the `kyc.reviewed` push.
- Hide everything while `features.verification_enabled` is `false`.

Postman: `docs/karigar-employer-app.postman_collection.json` → "Business
Verification (KYC)" → "Submit business verification". It has disabled rows
for the "I don't have it" case; enable them to try it.
