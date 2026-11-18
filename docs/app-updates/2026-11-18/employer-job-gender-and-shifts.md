# Employer app: job gender, and a shift section per shift type

QA round of 2026-11-18. Both are on `POST /employer/jobs` and
`PUT/PATCH /employer/jobs/{job}`, and both come back on every job
(`EmployerJobResource`, and `JobDetailResource` for karigars).

## 1. Gender: "Who can apply"

New optional field `gender`: `male` | `female` | `both`. Leave it out (or send
`null`) for "not specified".

Comes back as `gender` plus `gender_label` ("Male", "Female", "Male & female",
or `null`). The options with labels are in `GET /employer/jobs/form-options` →
`genders`, and the bare values in `GET /reference` → `genders`.

## 2. Shifts

`shift` keeps the same four values: `day`, `night`, `rotational`, `flexible`.
Which time fields to show depends on the one picked.

Suggested labels for the dropdown: **Day shift (fixed timings)**, **Night
shift (fixed timings)**, **Rotational shift (multiple shifts)**, **Flexible
shift (no fixed time)**.

### Day / Night: one time pair, pre-filled

Same fields as before: `shift_start`, `shift_end` (`"HH:MM"`, 24-hour; a night
shift may end before it starts). **Pre-fill the pickers** when the shift is
picked:

```json
"shift_defaults": { "day": { "start": "09:00", "end": "18:00" },
                    "night": { "start": "21:00", "end": "06:00" } }
```

(from `form-options` and `/reference`). The employer can change them. If they
switch between day and night without touching the times, swap in the other
default.

### Rotational: "+ Add Shift Timing"

Hide the single time picker. Show a list, starting with one row ("Shift 1"), a
"+ Add Shift Timing" button, and a remove ✕ on each row. Up to
`max_shift_timings` rows (6).

```json
"shift": "rotational",
"shift_timings": [ { "start": "06:00", "end": "14:00" },
                   { "start": "14:00", "end": "22:00" },
                   { "start": "22:00", "end": "06:00" } ]
```

The order is the shift order (1st, 2nd, 3rd…). Each row needs both times, and
they can't be equal. Errors are per row: `shift_timings.1.end` and so on. Don't
send `shift_start`/`shift_end` with a rotational job; the list replaces them.

Comes back as:

```json
"shift_timings": [ { "name": "1st shift", "start": "06:00", "end": "14:00", "label": "6:00 AM – 2:00 PM" }, … ],
"shift_hours_label": "1st 6:00 AM – 2:00 PM · 2nd 2:00 PM – 10:00 PM · 3rd 10:00 PM – 6:00 AM"
```

### Flexible: total hours + optional core hours

Hide the time pickers. Show:

- **Total working hours**: a number plus a per-day / per-week dropdown.
  `work_hours` (1–24 per day, 1–168 per week) and `work_hours_period`
  (`day` | `week`; `day` if left out).
- An optional toggle, **"Add Core Working Hours"**, with the line *"Time window
  when employee must be online/available"*. When on: `core_start` and
  `core_end` (`"HH:MM"`). Core hours need `work_hours`.

```json
"shift": "flexible", "work_hours": 8, "work_hours_period": "day",
"core_start": "12:00", "core_end": "16:00"
```

**Core hours must fit inside the working hours.** The core window can't be
longer than the hours in one day. For a weekly figure the server counts a
6-day week (40 hrs/week ≈ 6.7 hrs/day). Too long → 422 on `core_end`:

> Core hours (6 hrs) cannot be more than the working hours in a day (4 hrs).

Comes back as `work_hours`, `work_hours_period`, `core_start`, `core_end`
(all `null` unless the shift is flexible) and
`shift_hours_label: "8 hrs/day · Core 12:00 PM – 4:00 PM"`.

### Switching shift type

When the shift changes, the server drops what belonged to the old type. A job
that goes from flexible to day loses its hours and core window, and one that
goes from rotational to night loses its list. An older build that doesn't know
`shift_timings` / `work_hours` can still edit the job without wiping them.

### Showing the hours

`shift_hours_label` is now one line for **every** shift type. Show it wherever
the hours appear (job card, job detail, the karigar's app). Use `shift_timings`
when you want one row per shift.
