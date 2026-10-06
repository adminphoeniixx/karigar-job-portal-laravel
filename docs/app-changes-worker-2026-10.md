# Worker app: changes for the next release (Oct 2026)

Everything here goes live with the next server deploy. Base URL `/api/v1`,
Sanctum token, worker only. **No existing field was removed or renamed**, so
the current build keeps working. These are new fields, new endpoints and new
screens only.

Full API reference: `docs/worker-app-api.md`. Postman: `docs/karigar-worker-app.postman_collection.json`
(new "Feed location" requests added). Screen mockup: `app-mockup/worker-app.html`
(Jobs tab → "Change ›").

## Summary

| # | Screen | What to build | Priority |
| --- | --- | --- | --- |
| 1 | Jobs tab, top | Bar: 📍 "Jobs near **Current location**" / "Jobs near **Sanganer, Jaipur**", with "Change ›" | **Must** |
| 2 | Home, under "Latest jobs near you" | Same bar | **Must** |
| 3 | "Show jobs near" sheet | "Use my current location" row (✓ when active), a search box with results, "Pick on map" | **Must** |
| 4 | After a location change | Reload `GET /jobs` (and the dashboard), show `message` as a toast | **Must** |
| 5 | Jobs tab | When `GET /jobs` returns `"unavailable": true`, show the "not available" state with an "I'm available for work" button | **Must** |
| 6 | Home | When `available` is `false`, show the same state instead of "No jobs available near you yet" | **Must** |
| 7 | Home toggle | Under the Available switch: "No job alerts or jobs while off" | Nice |
| 8 | Everywhere KYC shows | Re-test that KYC hides on `features.verification_enabled: false` | **Check** |

---

## 1. Change where the job feed looks ("Jobs near")

By default the feed shows jobs nearest to the phone. A karigar can now pick
another place, for example a town they are moving to or the area around a
site, and go back to "my current location" any time.

- The choice is **saved on the server**. The feed (`GET /jobs`) and the home
  screen's `latest_jobs` use it until the karigar changes it, on every device.
- It does **not** change the karigar's own address (`latitude` / `longitude` /
  `city` on the profile, which employers see).
- While a place is picked, it takes priority over the `lat` / `lng` the app
  sends with `GET /jobs`. Keep sending them anyway: they are used again as soon
  as the karigar switches back to current location.
- Category filtering is unchanged. Only the "nearest first" point moves.

### `GET /worker/feed-location`
```json
{ "location": { "mode": "current", "label": null, "latitude": null, "longitude": null } }
```
or, with a place picked:
```json
{ "location": { "mode": "chosen", "label": "Sanganer, Jaipur, Rajasthan", "latitude": 26.82, "longitude": 75.8 } }
```
The same object comes as **`feed_location` on `GET /worker/dashboard`**, so the
home screen needs no extra call.

### `GET /places?q=Sanganer`
Search for the sheet's search box. `q` is 2 to 120 characters. Returns up to 5
places in India:
```json
{ "places": [
  { "label": "Sanganer, Jaipur, Rajasthan", "city": "Jaipur", "state": "Rajasthan", "latitude": 26.82, "longitude": 75.8 }
] }
```
- **Call it when the karigar presses Search, or after they stop typing for
  about 600 ms. Never on every key.** The map service behind it
  (OpenStreetMap) allows about one request a second. The server caches answers
  and the route allows 30 calls a minute per user (`429` after that).
- `"places": []` means nothing matched or the map service is down. Show "No
  place found. Try a nearby town or the PIN code."

### `PUT /worker/feed-location`
Pick a place.
```json
// from the search results: send the result as it came
{ "latitude": 26.82, "longitude": 75.8, "label": "Sanganer, Jaipur, Rajasthan" }
// from "Pick on map": just the pin; the server names it
{ "latitude": 26.8231, "longitude": 75.8012 }
```
→ `200`
```json
{ "message": "Showing jobs near Sanganer, Jaipur, Rajasthan.",
  "location": { "mode": "chosen", "label": "Sanganer, Jaipur, Rajasthan", "latitude": 26.82, "longitude": 75.8 } }
```
Rules: `latitude` -90..90 and `longitude` -180..180, both required (`422`
without them). `label` is optional, up to 150 chars. Without a label the server
looks the name up, and falls back to "Pinned location".

### `DELETE /worker/feed-location`
Back to the current location.
```json
{ "message": "Showing jobs near your current location.",
  "location": { "mode": "current", "label": null, "latitude": null, "longitude": null } }
```

### What `GET /jobs` says about it
The `feed` block now has `location` and `location_label`:
```json
"feed": { "type": "for_you", "categories": ["Weaving"], "location": "chosen", "location_label": "Sanganer, Jaipur, Rajasthan" }
```
`location` is one of `chosen | current | profile | city | none`. For the
"Jobs near …" bar: `chosen` → show the label, `current` → "Current location".

---

## 2. "Available for work" off now means left alone

When the karigar switches **Available for work** off
(`PATCH /worker/availability` with `{ "available": false }`), the server leaves
them alone until they switch it back on.

| | Available | **Not** available |
| --- | --- | --- |
| New job alerts (push, email, in-app) | yes | **none** |
| Employer invites | yes | **refused**, nothing arrives |
| Shortlist / interview / hire / reject / chat updates | push + email + in-app | **in-app list only** (`GET /notifications`), no push, no email |
| AI screening call | yes | **never rung** |
| Admin broadcast push to all / a city / a category | yes | **skipped** (a push sent to this karigar by name still arrives) |
| Job feed and search (`GET /jobs`) | jobs | **empty**, with `unavailable: true` |
| Home `latest_jobs` | up to 5 jobs | **empty** |

Jobs they already applied to stay reachable: `GET /jobs/{job}`, My
applications and saved jobs work as before. Switching back on needs nothing
extra and everything resumes. Alerts missed in between are not re-sent.

### `GET /jobs` while not available
```json
{
  "data": [],
  "links": { "...": "..." },
  "meta": { "total": 0, "...": "..." },
  "unavailable": true,
  "message": "You are not available for work. Switch it on to see jobs."
}
```

### What to build
- **Jobs tab:** when `unavailable` is `true`, hide the list, filters and
  search. Show the message with a button **"I'm available for work"** that calls
  `PATCH /worker/availability` `{ "available": true }` and then reloads the
  list.
- **Home:** when the profile's `available` is `false`, replace "Latest jobs
  near you" with the same message and button.

Suggested wording (all 8 languages are in the web's `jobs.unavailableTitle` /
`unavailableBody` / `unavailableCta` strings):
- Title: **You're not available for work** (Hindi: "आप अभी काम के लिए उपलब्ध नहीं हैं")
- Body: "While "Available for work" is off you get no job alerts and see no jobs. Switch it on whenever you're ready."
- Button: **I'm available for work** (Hindi: "मैं काम के लिए उपलब्ध हूँ")

---

## 3. Karigar verification can be off (admin switch)

Nothing new to read. The admin can now switch karigar verification off while
employers keep verifying, and the flag the app already uses follows it:

- `GET /worker/dashboard` → `features.verification_enabled: false` and
  `stats.kyc_status: null`.
- `GET /kyc` and `POST /kyc` → `404`.

**Re-test:** with the flag `false`, no KYC tile, menu entry, banner or "Get
verified" prompt may show anywhere.

---

## Checklist

- [ ] "Jobs near …" bar on the Jobs tab and on Home
- [ ] Sheet: current-location row, search (on submit or debounced, never per key), results, pick on map
- [ ] `PUT` with the picked result or pin, `DELETE` for current location
- [ ] Feed and home reload after a change, `message` shown as a toast
- [ ] Bar reads `feed_location` (dashboard) or `feed.location` / `feed.location_label` (`GET /jobs`)
- [ ] Jobs tab: "not available" state with the switch-on button
- [ ] Home: same state instead of "No jobs available near you yet"
- [ ] After switching on, the feed reloads with jobs
- [ ] With `features.verification_enabled: false`, no KYC anywhere
