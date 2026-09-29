# Super Karigar **Employer app**: plans, GST, invoices and job drafts

The changes the **employer** app needs for this server release. Nothing here
affects the worker app.

Full reference: `docs/employer-app-api.md`. Base URL is `{{base_url}}/api/v1` and
auth is the same as before (`Authorization: Bearer <token>`). 🔒 means the call
needs auth.

**Summary of what to change in the app**

| # | Screen | Change | Priority |
|---|---|---|---|
| 1 | Credits & Plans | Show `feature_list` instead of the `features` keys | **Must**. The plan cards currently show "job post limit" with no number |
| 2 | Credits & Plans | Show the price with GST (`price_with_gst`) and the "Recommended" badge | **Must** |
| 3 | Checkout | Show the GST breakup from `amounts` (CGST + SGST, or IGST) | **Must** |
| 4 | Credits & Plans | Show job posts used this month (`job_posts`) | Should |
| 5 | Invoice | Show CGST / SGST / IGST, place of supply and SAC; add a "Download PDF" button (`pdf_url`) | **Must** |
| 6 | Post a job | Add a "Save as draft" button; drafts need only a title | **Must** |
| 7 | My jobs / Edit job | Mark drafts, and publish them with the same update call | **Must** |
| 8 | Error handling | New posting-limit message on `422` | **Must** |

---

## 1. Plan features: show `feature_list` 🔒

**Bug being fixed:** the Plans screen renders the keys of `features`, so the
employer sees "job post limit", "contact unlock limit" and "featured" with no
numbers, and cannot tell what the price buys.

`GET /employer/plans`: each plan now carries ready-to-show text:

```jsonc
{
  "id": 1,
  "name": "Basic",
  "slug": "basic",
  "price": 499,               // before GST
  "gst_amount": 89.82,        // new
  "price_with_gst": 588.82,   // new: what the employer actually pays
  "currency": "INR",
  "interval": "monthly",
  "features": { "job_post_limit": 5, "contact_unlock_limit": 20, "contact_database_limit": 1000, "featured": false },
  "feature_list": [           // new: show these lines as they are
    "5 job posts per month",
    "20 contact unlocks",
    "Access to 1,000 karigar contacts",
    "AI-ranked applicants",
    "GST invoice for every payment"
  ],
  "recommended": false,       // new: show a "Popular" / "Recommended" badge when true
  "is_current": false,
  "purchasable": true
}
```

**What to do**

- Render one ✓ row per string in `feature_list`. **Stop rendering `features`.**
  It stays in the response only for app logic.
- **Do not show `featured` as a feature.** It only means "highlight this card".
  Use `recommended` for the badge instead.
- The lines are in English for now, and the server may add or reword them. Do
  not hard-code them or parse numbers out of them.
- `0` in a limit means unlimited, and `feature_list` already says
  "Unlimited job posts".

## 2. Price with GST on the plan card 🔒

GST (18% today, set by admin) is added on top of `price`, and **the Razorpay
payment now charges the GST-inclusive amount**. Before this release it charged
only ₹499 while the invoice said ₹588.82.

Suggested card:

```
Basic                         ₹499 /month
                              + 18% GST · ₹588.82 total
✓ 5 job posts per month
✓ 20 contact unlocks
…
[ Choose Basic ]
```

The rate is in `payment.gst_percent`. When admin switches GST off it is `0` and
`price_with_gst == price`, so hide the "+ GST" line in that case.

`purchasable` is now `true` whenever payments are configured on the server.
Razorpay plans are created on demand at checkout, so a plan no longer shows as
unbuyable just because it has not been synced.

## 3. Checkout: GST breakup 🔒

`POST /employer/plans/{plan}/subscribe` (body `{ "coupon": "OPTIONAL" }`)
returns a fuller `amounts` object. Show it on the confirm step before opening
the Razorpay SDK:

```jsonc
"amounts": {
  "discount": 0,
  "subtotal": 499,          // taxable value (after any coupon)
  "gst_percent": 18,
  "gst": 89.82,
  "cgst": 44.91,            // new
  "sgst": 44.91,            // new
  "igst": 0,                // new
  "place_of_supply": "Haryana (06)",   // new
  "total": 588.82           // this is what Razorpay will charge
}
```

**Which GST lines to show:**

- If `cgst > 0`: show two lines, **CGST (9%)** and **SGST (9%)**. The half-rate
  is `gst_percent / 2`. This applies when the employer is in the same state as
  Super Karigar (Haryana).
- Otherwise, if `igst > 0`: show one line, **IGST (18%)**. This applies to any
  other state.
- If `gst == 0`: show no GST line.

The employer's state is read from their **GSTIN** on the business profile, else
from the **state** on their profile. Nudging employers to fill these in
(`PUT /employer/profile`) makes their invoices correct.

The Razorpay SDK call itself is unchanged: `subscription_id` plus
`razorpay_key`, then `POST /employer/plans/callback`.

## 4. Job posts used this month 🔒

`GET /employer/plans` has a new top-level key:

```jsonc
"job_posts": { "used": 3, "limit": 5, "unlimited": false, "resets_at": "2026-10-28T09:12:00+00:00" }
// null when the employer has no active plan
```

Suggested display under the current plan: **"3 of 5 job posts used this month ·
resets 28 Oct"**. Hide the count when `unlimited` is `true`.

**Behaviour change:** the limit now counts only jobs **published in the current
billing period**. Before this release it counted every job ever created, drafts
included, so a Basic employer was blocked forever after 5 jobs, even after
renewing.

## 5. Invoices: GST split and PDF 🔒

`GET /employer/invoices/{subscription}`: the `invoice` object gains these fields.
Existing fields are unchanged:

```jsonc
"invoice": {
  "number": "KRG-2026-00012",
  "date": "28 Sep 2026",
  "plan": { "name": "Basic", "interval": "monthly", "price": 499 },
  "subtotal": 499,
  "gst_percent": 18,
  "gst_amount": 89.82,
  "cgst_amount": 44.91,       // new (null on invoices issued before this release)
  "sgst_amount": 44.91,       // new
  "igst_amount": 0,           // new
  "place_of_supply": "Haryana (06)",  // new
  "sac": "998365",            // new
  "total": 588.82,
  …
},
"seller": { "name": "…", "address": "…", "gstin": "06AAFCP6967R1ZF", "email": "…", "state_code": "06", "sac": "998365" },
"buyer":  { … },
"pdf_url": "https://…/api/v1/employer/invoices/12/pdf"   // new
```

**What to do**

- **GST lines:** use the same rule as the checkout. If `cgst_amount > 0`, show
  CGST and SGST; else if `igst_amount > 0`, show IGST. If both are `null` (an
  old invoice), show one "GST (18%)" line with `gst_amount`.
- Print **Place of supply** and **SAC** on the invoice.
- `plan.price` is now what the plan cost on that invoice, not today's price.
- **Download PDF** button: `GET /employer/invoices/{subscription}/pdf` 🔒
  (this is the `pdf_url`) returns `application/pdf` with
  `Content-Disposition: attachment; filename="Invoice-KRG-2026-00012.pdf"`.
  Send the bearer token as usual, then save or share the file. You no longer
  need to build a PDF on the device.
- **Invoice email:** the server now emails the PDF invoice to the employer once
  the payment goes through. The app needs no code for this. It does not reach
  accounts that have only the placeholder email (`…@phone.karigar`), so a
  "Add your email to receive invoices" prompt on the profile is worth adding.

## 6. Post a job: "Save as draft" 🔒

`POST /employer/jobs` with `"status": "draft"` saves a draft. **A draft needs only
`title`.** `description` and `vacancies` can be left out while it is a draft.

```jsonc
// minimal draft
{ "title": "Mason for villa", "status": "draft" }
// 201 → { "message": "Draft saved.", "job": { …, "status": "draft", "is_draft": true } }
```

Drafts:

- are **not** shown to workers, do not notify anyone, and do not appear in
  search;
- **do not** count against the plan's job posts, and **do not** use up the free
  first post;
- can be saved even when the plan's job posts are used up.

**Screen:** two buttons at the bottom of the Post Job form:
`[ Save as draft ]` (outline) and `[ Post job ]` (primary). "Save as draft" can
skip client-side validation except for the title. "Post job" keeps the full
validation.

## 7. My jobs / Edit job: publishing a draft 🔒

`EmployerJobResource` (every job in `GET /employer/jobs`, `GET /employer/jobs/{job}`
and the `job` in responses) gains:

```jsonc
"is_draft": true,          // true until the job has been live once
"published_at": null       // ISO time it first went live; null for drafts
```

- **My jobs:** show a "Draft" chip when `status == "draft"`. The existing
  `?status=draft` filter still works.
- **Edit a draft:** show `[ Save draft ]` and `[ Publish ]` instead of a status
  picker. Publishing is the normal update call with the full job body and
  `"status": "active"`:

```jsonc
PUT /employer/jobs/{job}
{ …full job body…, "status": "active" }
// 200 → { "message": "Job posted.", "job": { …, "status": "active", "is_draft": false, "published_at": "…" } }
```

  At publish time the server checks the plan limit and notifies matching
  workers, the same as a new post.
- **Edit a live job (`is_draft == false`):** keep the status picker as today,
  showing only Active and Closed. Closing and reopening a job does not use
  another job post.
- The update response `message` is now one of `"Job posted."` (a draft was
  published), `"Draft saved."` or `"Job updated."`, so it can be shown as-is in
  a toast.

## 8. Posting-limit errors (`422`)

Returned by `POST /employer/jobs` with `status: "active"`, and by
`PUT /employer/jobs/{job}` when publishing a draft. **Nothing is saved.** Show
`message` as-is:

| When | `message` |
|---|---|
| Plan's job posts for this period are used up | `You have used all 5 job posts in your plan for this billing period. Save it as a draft, or upgrade your plan.` (the number is the plan's limit) |
| No active plan and the free first post is used or off | `Subscribe to a plan to post jobs.` (unchanged) |

For the first message, a good UX is a sheet with two actions:
**[ Save as draft ]**, which re-sends the same body with `"status": "draft"`,
and **[ See plans ]**.

---

### Checklist for the app release

- [ ] Plans: render `feature_list`; stop rendering `features` keys and `featured`
- [ ] Plans: "₹X + 18% GST · ₹Y total" from `price_with_gst` / `payment.gst_percent`
- [ ] Plans: "Recommended" badge from `recommended`
- [ ] Plans: "N of M job posts used" from `job_posts`
- [ ] Checkout: CGST+SGST or IGST lines, "Total payable" from `amounts.total`
- [ ] Invoice: GST split, place of supply, SAC; old invoices fall back to one GST line
- [ ] Invoice: "Download PDF" via `pdf_url` (bearer token)
- [ ] Post job: "Save as draft" + "Post job"; draft needs only a title
- [ ] My jobs: Draft chip; edit draft → "Save draft" / "Publish"
- [ ] 422 posting-limit sheet with "Save as draft" / "See plans"
- [ ] Profile: prompt for GSTIN / state (for correct GST) and a real email (for invoice mails)
