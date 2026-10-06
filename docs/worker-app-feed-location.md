# Worker app: change where the job feed looks ("Jobs near")

By default the job feed shows jobs nearest to where the phone is. A karigar
can now pick another place instead, for example a town they are moving to or
the area around a site. They can go back to "my current location" any time.

- The choice is **saved on the server**. The feed (`GET /jobs`) and the home
  screen's `latest_jobs` stay on it until the karigar changes it, on every
  device.
- It does **not** change the karigar's own address (`latitude` / `longitude` /
  `city` on the profile, which employers see).
- While a place is picked, it wins over the `lat` / `lng` the app sends with
  `GET /jobs`. Keep sending them anyway: the moment the karigar switches back
  to current location, they are used again.
- Category filtering is unchanged. Only the "nearest first" point moves.

Base URL `/api/v1`, Sanctum token, worker only. Ships with the next server
deploy.

---

## What to build

| # | Screen | What | Priority |
| --- | --- | --- | --- |
| 1 | Jobs tab, top | Bar: 📍 "Jobs near **Current location**" / "Jobs near **Sanganer, Jaipur**", with "Change ›" | **Must** |
| 2 | Home, under "Latest jobs near you" | Same bar | **Must** |
| 3 | "Show jobs near" sheet | "Use my current location" row (✓ when active), a search box with results, "Pick on map" | **Must** |
| 4 | After a change | Reload `GET /jobs` (and the dashboard) and toast the `message` | **Must** |

See `app-mockup/worker-app.html` (Jobs tab → "Change ›").

---

## API

### `GET /worker/feed-location`
```json
{ "location": { "mode": "current", "label": null, "latitude": null, "longitude": null } }
```
or, with a place picked:
```json
{ "location": { "mode": "chosen", "label": "Sanganer, Jaipur, Rajasthan", "latitude": 26.82, "longitude": 75.8 } }
```
The same object comes as `feed_location` on `GET /worker/dashboard`, so the
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
  about 600 ms. Never on every key.** The map service behind it (OpenStreetMap)
  allows about one request a second. The server caches answers, and the route
  allows 30 calls a minute per user (`429` after that).
- `"places": []` means nothing matched, or the map service is down. Show "No
  place found. Try a nearby town or the PIN code."

### `PUT /worker/feed-location`
Pick a place.
```json
// from the search results: send the result as it came
{ "latitude": 26.82, "longitude": 75.8, "label": "Sanganer, Jaipur, Rajasthan" }
// from "Pick on map": the pin only; the server names it
{ "latitude": 26.8231, "longitude": 75.8012 }
```
→ `200`
```json
{ "message": "Showing jobs near Sanganer, Jaipur, Rajasthan.",
  "location": { "mode": "chosen", "label": "Sanganer, Jaipur, Rajasthan", "latitude": 26.82, "longitude": 75.8 } }
```
Rules: `latitude` -90..90 and `longitude` -180..180, both required. `label`
is optional, ≤150 chars. Without it the server looks the name up, falling back
to "Pinned location". Missing coordinates give `422`.

### `DELETE /worker/feed-location`
Back to the current location.
```json
{ "message": "Showing jobs near your current location.",
  "location": { "mode": "current", "label": null, "latitude": null, "longitude": null } }
```

### What `GET /jobs` says about it
The feed's `feed` block now has `"location": "chosen"` and `location_label`
while a place is picked:
```json
"feed": { "type": "for_you", "categories": ["Weaving"], "location": "chosen", "location_label": "Sanganer, Jaipur, Rajasthan" }
```
`location` is one of `chosen | current | profile | city | none`. Use it for the
"Jobs near …" bar: `chosen` → the label, `current` → "Current location".

---

## Checklist

- [ ] "Jobs near …" bar on the Jobs tab and on Home
- [ ] Sheet: current location row, search (on submit or debounced, never per key), results, pick on map
- [ ] `PUT` with the picked result / pin; `DELETE` for current location
- [ ] Feed and home reload after a change, `message` shown as a toast
- [ ] Bar reads `feed_location` (dashboard) or `feed.location` / `feed.location_label` (`GET /jobs`)
