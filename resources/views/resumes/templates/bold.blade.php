{{-- Bold: a full-width colour header with the photo, a strip of stat tiles,
     skills as chips, and the work history with dates in their own column. --}}
@php
    $ink = '#1f1a17';
    $contact = array_filter([$r['phone'] ?? null, $r['email'] ?? null, $r['location'] ?? null]);
@endphp
<!DOCTYPE html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
<meta charset="utf-8">
<style>
    @page { footer: html_foot; }
    body { font-family: freesans; font-size: 10pt; color: #2b2320; line-height: 1.5; }
    .head { position: absolute; left: 0; top: 0; width: 210mm; height: 58mm; background-color: {{ $accent }}; }
    .headin { position: absolute; left: 16mm; top: 15mm; width: {{ $photo ? '135mm' : '178mm' }}; }
    .pic { position: absolute; left: 160mm; top: 11mm; width: 36mm; }
    .name { font-size: 28pt; font-weight: bold; color: #ffffff; line-height: 1.1; }
    .headline { font-size: 12.5pt; color: {{ $tint }}; margin-top: 2mm; }
    .contact { font-size: 9pt; color: #ffffff; margin-top: 4mm; }
    .vbadge { font-size: 8.5pt; font-weight: bold; color: {{ $accent }}; background-color: #ffffff; padding: 1.2mm 3mm; }
    .spacer { height: 46mm; }
    .tile { border-top: 2pt solid {{ $accent }}; background-color: {{ $tint }}; padding: 3mm 4mm; }
    .gap { width: 3mm; }
    .num { font-size: 18pt; font-weight: bold; color: {{ $accent }}; line-height: 1.1; }
    .lbl { font-size: 8pt; color: #6b5f57; }
    .h2 { font-size: 13pt; font-weight: bold; color: {{ $ink }}; margin-top: 7mm; margin-bottom: 3mm; }
    .bar { color: {{ $accent }}; font-size: 9pt; font-family: dejavusans; }
    .h2c { font-size: 13pt; font-weight: bold; color: {{ $ink }}; padding-bottom: 2mm; }
    .summary { font-size: 10.5pt; }
    .chip { font-size: 9.5pt; color: {{ $accent }}; background-color: {{ $tint }}; }
    .chips { line-height: 2.1; }
    .cellul { margin-top: 0; padding-left: 4mm; }
    .cellli { margin-bottom: 1mm; }
    .period { font-size: 9pt; font-weight: bold; color: {{ $accent }}; vertical-align: top; padding-top: 0.6mm; }
    .role { font-size: 11pt; font-weight: bold; color: {{ $ink }}; }
    .where { font-size: 9pt; color: #6b5f57; }
    .jobcell { vertical-align: top; padding-bottom: 5mm; border-left: 0.6pt solid {{ $soft }}; padding-left: 5mm; }
    ul { margin: 1mm 0 0 0; padding-left: 4mm; }
    li { margin-bottom: 0.8mm; }
    /* FreeSans has no ✔ and its bold cut no ■ — symbols come from DejaVu. */
    .sym { font-family: dejavusans; }
    .foot { font-size: 7.5pt; color: #a59a91; text-align: center; }
</style>
</head>
<body>
<htmlpagefooter name="foot"><div class="foot">{{ $L['made'] }}</div></htmlpagefooter>

<div class="head"></div>
<div class="headin">
    <div class="name">{{ $r['name'] }}</div>
    @if (! empty($r['headline']))<div class="headline">{{ $r['headline'] }}</div>@endif
    @if ($contact)<div class="contact">{{ implode('   •   ', $contact) }}</div>@endif
    @if ($verified)
        <table cellspacing="0" cellpadding="0" style="margin-top: 3mm;"><tr><td class="vbadge"><span class="sym">✔</span> {{ $L['verified'] }}</td></tr></table>
    @endif
</div>
@if ($photo)<div class="pic"><img src="{{ $photo }}" style="width: 36mm; height: 36mm;"></div>@endif
<div class="spacer"></div>

@if ($stats)
    <table width="100%" cellspacing="0" cellpadding="0"><tr>
        @foreach ($stats as $i => $s)
            @if ($i)<td class="gap"></td>@endif
            <td class="tile"><div class="num">{{ $s['value'] }}</div><div class="lbl">{{ $s['label'] }}</div></td>
        @endforeach
    </tr></table>
@endif

@if (! empty($r['summary']))
    <div class="h2"><span class="bar">■</span> {{ $L['summary'] }}</div>
    <div class="summary">{{ $r['summary'] }}</div>
@endif

@if (! empty($r['skills']))
    <div class="h2"><span class="bar">■</span> {{ $L['skills'] }}</div>
    <div class="chips">@foreach ($r['skills'] as $skill)<span class="chip">&nbsp;&nbsp;{{ $skill }}&nbsp;&nbsp;</span>&nbsp;&nbsp; @endforeach</div>
@endif

@if (! empty($r['experience']))
    <div class="h2"><span class="bar">■</span> {{ $L['experience'] }}</div>
    @foreach ($r['experience'] as $job)
        <table width="100%" cellspacing="0" cellpadding="0" style="page-break-inside: avoid;"><tr>
            <td class="period" width="30mm">{{ $job['period'] }}</td>
            <td class="jobcell">
                <div class="role">{{ $job['role'] }}</div>
                @if ($job['employer'] || $job['place'])<div class="where">{{ implode(', ', array_filter([$job['employer'], $job['place']])) }}</div>@endif
                @foreach ($job['points'] as $point)<div class="cellli"><span class="bar">•</span>&nbsp; {{ $point }}</div>@endforeach
            </td>
        </tr></table>
    @endforeach
@endif

<div style="height: 3mm;"></div>
<table width="100%" cellspacing="0" cellpadding="0"><tr>
    <td width="50%" style="vertical-align: top; padding-right: 6mm;">
        @if (! empty($r['education']))
            <div class="h2c"><span class="bar">■</span> {{ $L['education'] }}</div>
            @foreach ($r['education'] as $line)<div>{{ $line }}</div>@endforeach
        @endif
        @if (! empty($r['languages']))
            <div style="height: 2mm;"></div>
            <div class="h2c"><span class="bar">■</span> {{ $L['languages'] }}</div>
            <div>{{ implode(', ', $r['languages']) }}</div>
        @endif
    </td>
    <td width="50%" style="vertical-align: top;">
        @if (! empty($r['extras']) || ! empty($r['expected_wage']))
            <div class="h2c"><span class="bar">■</span> {{ $L['more'] }}</div>
            @foreach ($r['extras'] ?? [] as $line)<div class="cellli"><span class="bar">•</span>&nbsp; {{ $line }}</div>@endforeach
            @if (! empty($r['expected_wage']))<div class="cellli"><span class="bar">•</span>&nbsp; {{ $L['salary'] }}: {{ $r['expected_wage'] }}</div>@endif
        @endif
    </td>
</tr></table>
</body>
</html>
