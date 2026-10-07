# Super Karigar **Worker + Employer apps**: 4 APIs (update, maintenance, Terms & Policy, Help & Support)

7 Oct 2026. For **both** apps. All four are public: no token needed.
Base URL `{{base_url}}/api/v1`. Postman: "App launch checks" folder in both collections.

| What | Endpoint | Worker app | Employer app | Status |
|---|---|---|---|---|
| App update | `GET /app/update` | ✅ send `app=worker` | ✅ send `app=employer` | **New** |
| Maintenance | `GET /app/maintenance` | ✅ same call | ✅ same call | **New** |
| Terms & Privacy | `GET /legal`, `GET /legal/{terms\|privacy}` | ✅ same call | ✅ same call | Already live, unchanged |
| Help & Support | `GET /support?audience=…` | ✅ send `audience=worker` | ✅ send `audience=employer` | Already live, unchanged |

**Both apps use all four.** Only two calls differ by app: `app=` on the update
check and `audience=` on Help & Support. The other two are the same call from
both apps.

On every launch, call `/app/maintenance` and `/app/update` before anything else.


### 1. `GET /app/update?app={worker|employer}&platform={android|ios}&version=1.3.5` (new)
**For: both apps.** The worker app sends `app=worker`, the employer app sends
`app=employer`. Each app has its own latest/minimum version and store link for
Android and for iOS, so one app can be forced to update without the other.

`version` is the installed version name. A build suffix (`1.3.5+41`) is ignored.
```json
{ "app": "worker", "platform": "android",
  "installed_version": "1.3.5",
  "latest_version": "1.4.0",       // null when the admin has not set one
  "min_version": "1.2.0",          // null when there is no minimum
  "update_available": true,
  "force_update": false,
  "store_url": "https://play.google.com/store/apps/details?id=…",   // may be null
  "message": "Faster job feed." }   // may be null
```
- `force_update: true` (installed is below `min_version`): show a blocking
  screen with an "Update" button to `store_url`. Do not let the user continue.
- `update_available: true` with `force_update: false`: show a dismissible
  "New version available" prompt.
- Both false: nothing to show.
- `422` for an unknown `app` or `platform`, or a malformed `version`.

The admin sets the versions in Admin → Settings → Mobile apps.

### 2. `GET /app/maintenance` (new)
**For: both apps, same call.** There is one maintenance switch: when it is on,
both apps go into maintenance together.

```json
{ "maintenance": true,
  "message": "We are improving Super Karigar. Back soon.",
  "until": "2026-10-08T00:30:00+00:00" }   // ISO 8601, may be null
```
When off: `{ "maintenance": false, "message": null, "until": null }`.

**While maintenance is on, every other app API call returns `503`** with the same
body plus `"code": "maintenance"`. Handle it globally: on any `503` with
`code == "maintenance"`, show the maintenance screen (message, and "back by"
from `until` in local time), and poll `/app/maintenance` every minute or so
until it says `false`. These keep working during maintenance: `/app/update`,
`/app/maintenance`, `/legal`, `/legal/{document}`, `/support`.

### 3. Terms & Policy (already live)
**For: both apps, same call.** The Terms of use and Privacy policy are the same
for workers and employers.

#### `GET /legal`
Both documents without their bodies, for the settings row.
```json
{ "documents": [
  { "key": "terms", "title": "Terms of use", "summary": "The rules for using…",
    "updated_at": "2026-09-03", "updated_label": "3 September 2026", "web_url": null },
  { "key": "privacy", "title": "Privacy policy", "summary": "What we hold about you…",
    "updated_at": "2026-08-12", "updated_label": "12 August 2026",
    "web_url": "https://superkarigar.com/privacy" }
] }
```
`web_url` opens the same text in a browser. It can be `null`, so handle that.

#### `GET /legal/{document}`: `terms` or `privacy`
```json
{ "document": {
  "key": "privacy", "title": "Privacy policy",
  "updated_at": "2026-08-12", "updated_label": "12 August 2026",
  "summary": "…", "intro": "Super Karigar connects skilled karigars with…",
  "web_url": "https://superkarigar.com/privacy",
  "sections": [
    { "id": "what-we-collect", "title": "What we collect", "blocks": [
      { "type": "heading", "text": "Everyone" },
      { "type": "list", "items": ["Your mobile number…", "Your name…"] },
      { "type": "paragraph", "text": "We use your information to…" }
    ] }
  ]
} }
```
- There are only three block types: `paragraph` and `heading` have `text`, `list` has `items`.
- `heading` is a sub-heading inside a section. Show it smaller than the section `title`.
- Some sections can be missing (the identity-document section hides when the
  admin turns verification off), so build the screen from what comes back.
- `404` for any other document. Content is English only for now.

### 4. `GET /support?audience=worker|employer` (already live)
**For: both apps.** The worker app sends `audience=worker`, the employer app
sends `audience=employer`; each gets its own FAQs plus the shared ones.

```json
{ "channels": { "email": "support@superkarigar.com", "whatsapp": "919000000000",
                "phone": "…", "hours": "Monday to Saturday, 10 AM to 7 PM IST" },
  "faqs": [ { "id": "otp-not-received", "audience": "all",
              "question": "I did not get my OTP.", "answer": "The code takes a few seconds…" } ] }
```
- A channel that is not set up is left out. Show only the keys you get.
- `whatsapp` is digits with the country code and no `+`. Build the link as `https://wa.me/<digits>`.
- Send `audience=worker` from the worker app and `audience=employer` from the
  employer app: you get that app's questions plus the shared ones (`audience: "all"`).
  Anything else returns `422`.
- `id` stays the same, so you can link to one answer.

### Test checklist
- [ ] Launch calls `/app/maintenance` then `/app/update` with the real app, platform and version
- [ ] Force update blocks the app; optional update can be dismissed
- [ ] A `503` with `code: "maintenance"` anywhere shows the maintenance screen
- [ ] Settings → Terms, Privacy and Help screens render from these endpoints
