# Employer app: login and sign-up screen fixes (app side only)

QA round of 2026-11-18. These need no server change. The server-side items are
in the other files in this folder.

## Update popup keeps coming back

QA saw "Update the app" right after installing, and again after submitting the
sign-up form.

What the server answers: on 18 Nov the live settings (Admin → Settings → Mobile
apps) had employer Android and iOS at `latest_version: "1.2"`,
`min_version: "1.0"` and **no `store_url`**. So any build below 1.2 gets
`update_available: true, force_update: false` on every `/app/update` call, and
the popup's Update button has nowhere to go.

App fixes:

1. Call `/app/update` **once per cold start**, not when a screen opens or after
   a form submit.
2. When `force_update` is `false`, the popup is optional. Show it **once per
   `latest_version`**, remember "Later" for that version, and don't show it
   again until `latest_version` changes.
3. Hide the Update button when `store_url` is `null`.

On our side, the admin should set `latest_version` to the build actually in the
store, and fill in `store_url`.

## Login screen

| QA point | Change |
|---|---|
| "IN" and "+91" break onto two lines | Keep them on one line: 🇮🇳 +91 (flag next to the code). |
| Subtext | Replace "Employer · We'll send you a one-time code" with **"We'll send a verification code to this number"**. |
| Placeholder | No sample number. Use **"Enter the 10 digit mobile"**. |
| Errors | Show error messages **at the top of the screen**, not under the field (the keyboard hides them there). This applies to every form. |

## Sign-up form

| QA point | Change |
|---|---|
| Heading | "Set up your business" → **"Set up your profile"** |
| Step heading | "Who are you hiring as?" → **"Tell us about yourself"**, subheading **"Karigars will see this contact name on your job posts."** |
| Label | "You are hiring as" → **"I am hiring as"** |
| Location | Add a **"Use my current location"** button (GPS). Send `latitude` / `longitude` with `city` / `state`; the server accepts them on `PATCH /employer/profile`. |
| GST number takes fewer than 15 characters on the first try | The server accepts exactly 15 (`22ABCDE1234F1Z5`, any case). Check the field's max length, and that upper-casing or auto-formatting doesn't eat a character. Use `maxLength: 15` with a plain capital-letters keyboard. |
| "Finish Setup" lets a blank form through | See `employer-finish-setup-needs-whole-profile.md` (`finish: true`). |
| "Usually Hiring For" differs in Edit Business Profile | See the same file: the server returns what sign-up saved. |

## Not built yet (waiting on a product decision)

- **State → City → Zone → Area.** There is no zone data on the server yet.
- **"Kind of karigar" not following the Industry picked on step 2.** Today the
  industries (Construction, Logistics…) and the karigar categories (handmade
  crafts) are separate lists with no link between them. The fix is either
  craft-based industries with a mapping, or dropping the Industry step.

Nothing to change in the app for these two until that is decided.
