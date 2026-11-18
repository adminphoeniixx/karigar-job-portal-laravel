{{--
    Every error page (404, 403, 419, 500, 503 …). Laravel's own error views
    extend `errors::minimal`, and this file takes that name, so they all get
    the brand, a plain sentence and a way back instead of a bare "404 | Not
    Found". Self-contained on purpose: no Vite bundle, no database, so it
    still renders when those are what broke.
--}}
@php
    $code = trim($__env->yieldContent('code'));
    $help = match ($code) {
        '404' => 'This page does not exist, or the job is no longer open.',
        '403' => 'Your account does not have access to this page.',
        '419' => 'This page sat open too long. Go back, refresh, and try again.',
        '429' => 'Too many tries in a short time. Wait a minute and try again.',
        '503' => 'We are updating Super Karigar. Please come back in a few minutes.',
        default => 'Something went wrong on our side. Please try again in a moment.',
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - Super Karigar</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <style>
        :root { --paper: hsl(38 36% 95%); --ink: hsl(24 24% 11%); --muted: hsl(24 10% 40%); --line: hsl(32 18% 86%); --primary: hsl(14 79% 42%); }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; flex-direction: column; background: var(--paper); color: var(--ink); font-family: Outfit, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; -webkit-font-smoothing: antialiased; }
        header { padding: 20px 24px; border-bottom: 1px solid var(--line); }
        header img { display: block; height: 44px; width: auto; }
        main { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 24px; }
        .box { width: 100%; max-width: 520px; }
        .eyebrow { display: flex; align-items: center; gap: 12px; font-size: 12px; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: var(--primary); }
        .eyebrow::before { content: ''; width: 36px; height: 1px; background: var(--primary); }
        h1 { margin: 18px 0 0; font-size: clamp(36px, 8vw, 56px); line-height: 1.05; font-weight: 600; letter-spacing: -.02em; }
        p { margin: 16px 0 0; font-size: 16px; line-height: 1.6; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 32px; }
        a.btn { display: inline-flex; align-items: center; padding: 12px 20px; border-radius: 2px; font-size: 14px; font-weight: 600; text-decoration: none; }
        a.primary { background: var(--ink); color: var(--paper); }
        a.ghost { border: 1px solid var(--line); color: var(--ink); }
        a.btn:hover { opacity: .88; }
    </style>
</head>
<body>
    <header>
        <a href="/"><img src="/images/brand/wordmark.png" alt="Super Karigar" width="534" height="230"></a>
    </header>
    <main>
        <div class="box">
            <div class="eyebrow">Error {{ $code }}</div>
            <h1>@yield('message')</h1>
            <p>{{ $help }}</p>
            <div class="actions">
                <a class="btn primary" href="/">Go to home</a>
                <a class="btn ghost" href="/jobs">Browse jobs</a>
            </div>
        </div>
    </main>
</body>
</html>
