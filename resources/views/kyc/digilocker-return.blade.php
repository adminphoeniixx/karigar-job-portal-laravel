{{-- Where DigiLocker lands the user. The apps close their in-app browser on
     reaching this URL; anyone who sees it is told to go back to the app. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $passed ? 'Aadhaar verified' : 'Verification not finished' }} · {{ config('app.name') }}</title>
    <style>
        :root { --bg: hsl(38 36% 95%); --fg: hsl(24 24% 11%); --muted: hsl(24 10% 40%); --primary: hsl(14 79% 42%); --bad: hsl(350 65% 45%); }
        @media (prefers-color-scheme: dark) { :root { --bg: hsl(24 22% 7%); --fg: hsl(36 28% 92%); --muted: hsl(30 10% 65%); --primary: hsl(16 80% 56%); --bad: hsl(350 70% 65%); } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
               background: var(--bg); color: var(--fg); font-family: Outfit, system-ui, -apple-system, sans-serif; text-align: center; }
        main { max-width: 360px; }
        .mark { width: 56px; height: 56px; margin: 0 auto 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
                font-size: 28px; font-weight: 700; color: #fff; background: {{ $passed ? 'var(--primary)' : 'var(--bad)' }}; }
        h1 { margin: 0 0 8px; font-size: 22px; font-weight: 700; }
        p { margin: 0; color: var(--muted); font-size: 15px; line-height: 1.5; }
        .brand { margin-top: 32px; font-size: 13px; font-weight: 600; letter-spacing: .04em; color: var(--muted); }
    </style>
</head>
<body>
<main>
    <div class="mark" aria-hidden="true">{{ $passed ? '✓' : '!' }}</div>
    <h1>{{ $passed ? 'Aadhaar verified' : 'Verification not finished' }}</h1>
    <p>{{ $message }}</p>
    <p style="margin-top:12px">You can close this page and go back to the Super Karigar app.</p>
    <div class="brand">SUPER कारीगर</div>
</main>
</body>
</html>
