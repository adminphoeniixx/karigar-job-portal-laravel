# Employer app: AI call that explains business verification (no API change)

Since 2026-11-06 the server can ring an employer whose business verification (KYC) is **not
started** or **rejected**. Only the account owner gets the call, never a team member. It comes
from the screening-call number (+91 80 3170 3250), and an AI voice explains the KYC screen in
Hindi. The admin starts the call, or the server does it 24 hours after signup once the admin
switch is on. **Nobody's AI call credits are charged.**

**There is no new endpoint, field or push.** The app needs no code change. But the AI reads out
the app's screen and button names, so the app has to keep these labels as they are:

| What the AI says | Where it must be in the app |
|---|---|
| "KYC वाले option में जाइए" / "menu में KYC Verification" | A "KYC" / "KYC Verification" entry the owner can find from Home or the menu |
| "business का type चुनिए" | The business type picker (Individual, Sole proprietorship, Partnership, LLP, Company, Trust, Other), from `GET /employer/kyc` |
| "Verify with DigiLocker" | The DigiLocker button for an individual, with this exact text |
| "हर document का number डालिए, photo या PDF लगाइए, Submit" | The number + file fields for each document, and the **Submit** button |
| "Aadhaar के आगे 'I don't have this'" | The alternate-document option next to each document, with this exact text |

The documents the AI asks for are the same as the ones `GET /employer/kyc` lists for the business
type: Aadhaar + PAN for an individual, Aadhaar + PAN + GST for a sole proprietorship, and the
business PAN + GST for everything else.

If one of these is renamed in the app, tell the backend so the call script says the new words.
