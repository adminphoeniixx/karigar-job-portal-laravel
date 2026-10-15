{{-- Modern: an ink sidebar (photo, contact, skills, languages, education)
     beside the main column (name, stats, profile, work timeline). The page
     margin keeps the main column clear of the band; the band itself is the
     page background ($band), so it continues onto page 2. --}}
@php
    $ink = '#241d19';
    $contact = array_filter([
        $L['phone'] => $r['phone'] ?? null,
        $L['email'] => $r['email'] ?? null,
        $L['location'] => $r['location'] ?? null,
    ]);
@endphp
<!DOCTYPE html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
<meta charset="utf-8">
<style>
    @page { footer: html_foot; background: url({{ $band }}) no-repeat 0 0; background-image-resize: 6; }
    body { font-family: freesans; font-size: 10pt; color: #2b2320; line-height: 1.5; }
    .side { position: absolute; left: 0; top: 0; width: 52mm; padding: 14mm 8mm 0 8mm; color: #efe7df; }
    .sh { font-size: 8.5pt; font-weight: bold; color: {{ $soft }}; margin-top: 6mm; margin-bottom: 2mm; padding-bottom: 1.2mm; border-bottom: 0.4pt solid #5a4d46; }
    .k { font-size: 7.5pt; color: #a99a8e; }
    .v { font-size: 9pt; color: #f5efe9; margin-bottom: 1.8mm; }
    .item { font-size: 9pt; margin-bottom: 1.2mm; color: #f5efe9; }
    .dot { color: {{ $soft }}; font-size: 7pt; }
    .photo { text-align: center; }
    .initials { width: 36mm; height: 36mm; border-radius: 18mm; margin: 0 auto; background-color: {{ $accent }}; color: #ffffff; font-size: 30pt; font-weight: bold; text-align: center; line-height: 36mm; }
    .badge { margin-top: 7mm; padding: 2mm 3mm; border: 0.6pt solid {{ $soft }}; font-size: 8.5pt; color: #ffffff; text-align: center; }
    .name { font-size: 25pt; font-weight: bold; color: {{ $ink }}; line-height: 1.15; }
    .headline { font-size: 12pt; color: {{ $accent }}; margin-top: 1.5mm; }
    .stats { margin-top: 6mm; }
    .tile { background-color: {{ $tint }}; padding: 3mm 4mm; }
    .gap { width: 2.5mm; }
    .num { font-size: 17pt; font-weight: bold; color: {{ $accent }}; line-height: 1.1; }
    .lbl { font-size: 8pt; color: #6b5f57; }
    .h2 { font-size: 9.5pt; font-weight: bold; color: {{ $accent }}; margin-top: 8mm; margin-bottom: 3mm; padding-bottom: 1.5mm; border-bottom: 0.6pt solid {{ $soft }}; }
    .caps { text-transform: uppercase; letter-spacing: 1.2pt; }
    .job { border-left: 1.2pt solid {{ $soft }}; padding: 0 0 4mm 5mm; margin-left: 1mm; }
    .role { font-size: 11pt; font-weight: bold; color: {{ $ink }}; }
    .where { font-size: 9pt; color: #6b5f57; }
    .period { font-size: 8.5pt; color: {{ $accent }}; font-weight: bold; }
    ul { margin: 1.5mm 0 0 0; padding-left: 4mm; }
    li { margin-bottom: 1mm; }
    /* FreeSans has no ✔ and its bold cut no ■ — symbols come from DejaVu. */
    .sym { font-family: dejavusans; }
    .foot { font-size: 7.5pt; color: #a59a91; text-align: right; }
</style>
</head>
<body class="{{ $hi ? 'hi' : 'en' }}">
<htmlpagefooter name="foot"><div class="foot">{{ $L['made'] }}</div></htmlpagefooter>

<div class="side">
    <div class="photo">
        @if ($photo)
            <img src="{{ $photo }}" style="width: 36mm; height: 36mm;">
        @else
            <div class="initials">{{ $initials }}</div>
        @endif
    </div>

    @if ($contact)
        <div class="sh{{ $hi ? '' : ' caps' }}">{{ $L['contact'] }}</div>
        @foreach ($contact as $k => $v)
            <div class="k">{{ $k }}</div>
            <div class="v">{{ $v }}</div>
        @endforeach
    @endif

    @if (! empty($r['skills']))
        <div class="sh{{ $hi ? '' : ' caps' }}">{{ $L['skills'] }}</div>
        @foreach (array_slice($r['skills'], 0, 12) as $skill)
            <div class="item"><span class="dot sym">■</span>&nbsp; {{ $skill }}</div>
        @endforeach
    @endif

    @if (! empty($r['languages']))
        <div class="sh{{ $hi ? '' : ' caps' }}">{{ $L['languages'] }}</div>
        <div class="item">{{ implode(' · ', $r['languages']) }}</div>
    @endif

    @if (! empty($r['education']))
        <div class="sh{{ $hi ? '' : ' caps' }}">{{ $L['education'] }}</div>
        @foreach ($r['education'] as $line)
            <div class="item">{{ $line }}</div>
        @endforeach
    @endif

    @if (! empty($r['expected_wage']))
        <div class="sh{{ $hi ? '' : ' caps' }}">{{ $L['salary'] }}</div>
        <div class="item">{{ $r['expected_wage'] }}</div>
    @endif

    @if ($verified)
        <div class="badge"><span class="sym">✔</span> {{ $L['verified'] }}</div>
    @endif
</div>

<div class="name">{{ $r['name'] }}</div>
@if (! empty($r['headline']))<div class="headline">{{ $r['headline'] }}</div>@endif

@if ($stats)
    <table class="stats" cellspacing="0" cellpadding="0"><tr>
        @foreach ($stats as $i => $s)
            @if ($i)<td class="gap"></td>@endif
            <td class="tile"><div class="num">{{ $s['value'] }}</div><div class="lbl">{{ $s['label'] }}</div></td>
        @endforeach
    </tr></table>
@endif

@if (! empty($r['summary']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['summary'] }}</div>
    <div>{{ $r['summary'] }}</div>
@endif

@if (! empty($r['experience']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['experience'] }}</div>
    @foreach ($r['experience'] as $job)
        <div class="job">
            @if ($job['period'])<div class="period">{{ $job['period'] }}</div>@endif
            <div class="role">{{ $job['role'] }}</div>
            @if ($job['employer'] || $job['place'])<div class="where">{{ implode(', ', array_filter([$job['employer'], $job['place']])) }}</div>@endif
            @if ($job['points'])
                <ul>@foreach ($job['points'] as $point)<li>{{ $point }}</li>@endforeach</ul>
            @endif
        </div>
    @endforeach
@endif

@if (! empty($r['extras']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['more'] }}</div>
    <ul>@foreach ($r['extras'] as $line)<li>{{ $line }}</li>@endforeach</ul>
@endif
</body>
</html>
