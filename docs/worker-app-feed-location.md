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
| 4 | After a change | Show the `jobs` that came back with the change (no second call), refresh the dashboard in the background, toast the `message` | **Must** |

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
- **A city name is answered at once** from our own list of 238 cities
  ("jaip" → Jaipur, "delhi" → Delhi and New Delhi; a state after a comma
  narrows it: "Udaipur, Tri"). Anything else, a locality, a PIN code or Hindi
  text, goes to the map service and takes about a second the first time.
- **Call it when the karigar presses Search, or after they stop typing for
  about 400 ms. Never on every key.** The map service behind it (OpenStreetMap)
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
  "location": { "mode": "chosen", "label": "Sanganer, Jaipur, Rajasthan", "latitude": 26.82, "longitude": 75.8 },
  "jobs": { "data": [ …JobResource, nearest first… ], "links": { …, "next": "https://superkarigar.com/api/v1/jobs?page=2" },
            "meta": { …, "total": 37 }, "feed": { "type": "for_you", "location": "chosen", "location_label": "Sanganer, Jaipur, Rajasthan", … } } }
```
**`jobs` is the feed's first page around the new place, exactly what
`GET /jobs` would return.** Show it straight away instead of calling
`GET /jobs` again. Its `links.next` points at `GET /jobs` for page 2. Send
`"all": true` when the karigar is looking at all categories.

Rules: `latitude` -90..90 and `longitude` -180..180, both required. `label`
is optional, ≤150 chars. Without it the server looks the name up, falling back
to "Pinned location", which adds about a second. Send the label you got from
`places`. Missing coordinates give `422`.

### `DELETE /worker/feed-location?lat=26.91&lng=75.79`
Back to the current location. Send the phone's position as `lat` / `lng`
(query string; optional `all=1`) to get the feed around it back in `jobs`,
the same shape as above. Without them `jobs` falls back to the profile's
position or city.
```json
{ "message": "Showing jobs near your current location.",
  "location": { "mode": "current", "label": null, "latitude": null, "longitude": null },
  "jobs": { "data": [ … ], "links": { … }, "meta": { … }, "feed": { "location": "current", … } } }
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

## Making the change feel instant

The server is in Europe, so every call from India costs about 0.2 s on the
way there and back, and a new connection about 0.4 s more. What helps most
on the app side:

1. **One HTTP client for the whole app, kept alive.** The server speaks
   HTTP/2, so a warm connection skips the 0.4 s. A new client per call
   pays it every time.
2. **Update the bar at once.** Put the new place in the "Jobs near …" bar
   when it is tapped. Don't wait for the answer to do that.
3. **Use `jobs` from the `PUT` / `DELETE` answer.** That makes the change one
   call instead of two.
4. **Show the last feed while loading.** Keep the last `GET /jobs` page on the
   device and show it immediately on opening; replace it when the fresh one
   arrives.
5. **Debounce the search box** (about 400 ms) and send the `label` from
   `places` with the `PUT`, so the server never has to name the place itself.

---

## Checklist

- [ ] "Jobs near …" bar on the Jobs tab and on Home
- [ ] Sheet: current location row, search (on submit or debounced, never per key), results, pick on map
- [ ] `PUT` with the picked result / pin; `DELETE` for current location
- [ ] After a change: show `jobs` from the answer, refresh home in the background, `message` as a toast
- [ ] One kept-alive HTTP client; last feed shown from the device while loading
- [ ] Bar reads `feed_location` (dashboard) or `feed.location` / `feed.location_label` (`GET /jobs`)
