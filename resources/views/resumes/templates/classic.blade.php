{{-- Classic: a quiet serif page. Centred name over a double rule, then each
     section as a small-caps label with its content beneath. --}}
@php
    $ink = '#1f1a17';
    $contact = array_filter([$r['phone'] ?? null, $r['email'] ?? null, $r['location'] ?? null]);
    $skillRows = array_chunk(array_slice($r['skills'] ?? [], 0, 15), 3);
@endphp
<!DOCTYPE html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
<meta charset="utf-8">
<style>
    @page { footer: html_foot; }
    body { font-family: freeserif; font-size: 10.5pt; color: #2b2320; line-height: 1.5; }
    .name { font-size: 26pt; font-weight: bold; color: {{ $ink }}; text-align: center; line-height: 1.15; }
    .headline { font-size: 12pt; color: {{ $accent }}; text-align: center; margin-top: 1mm; }
    .contact { font-size: 9.5pt; color: #6b5f57; text-align: center; margin-top: 2mm; }
    .rule1 { border-bottom: 1.6pt solid {{ $accent }}; margin-top: 5mm; }
    .rule2 { border-bottom: 0.5pt solid {{ $accent }}; margin-top: 0.8mm; margin-bottom: 2mm; }
    .h2 { font-size: 10pt; font-weight: bold; color: {{ $accent }}; margin-top: 6mm; margin-bottom: 2mm; }
    .caps { text-transform: uppercase; letter-spacing: 1.5pt; }
    .hr { border-bottom: 0.4pt solid {{ $soft }}; margin-bottom: 2.5mm; }
    .role { font-size: 11.5pt; font-weight: bold; color: {{ $ink }}; }
    .where { font-size: 10pt; font-style: italic; color: #6b5f57; }
    .period { font-size: 9.5pt; color: {{ $accent }}; text-align: right; white-space: nowrap; }
    .job { margin-bottom: 4mm; }
    .skill { font-size: 10pt; padding: 0.8mm 0; }
    ul { margin: 1mm 0 0 0; padding-left: 5mm; }
    li { margin-bottom: 0.8mm; }
    .badge { font-size: 9pt; color: {{ $accent }}; text-align: center; margin-top: 2mm; }
    /* FreeSans has no ✔ and its bold cut no ■ — symbols come from DejaVu. */
    .sym { font-family: dejavusans; }
    .foot { font-family: freesans; font-size: 7.5pt; color: #a59a91; text-align: center; }
</style>
</head>
<body>
<htmlpagefooter name="foot"><div class="foot">{{ $L['made'] }}</div></htmlpagefooter>

@if ($photo)
    <div style="text-align: center; margin-bottom: 3mm;"><img src="{{ $photo }}" style="width: 26mm; height: 26mm;"></div>
@endif
<div class="name">{{ $r['name'] }}</div>
@if (! empty($r['headline']))<div class="headline">{{ $r['headline'] }}</div>@endif
@if ($contact)<div class="contact">{{ implode('   |   ', $contact) }}</div>@endif
@if ($verified)<div class="badge"><span class="sym">✔</span> {{ $L['verified'] }}</div>@endif
<div class="rule1"></div><div class="rule2"></div>

@if (! empty($r['summary']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['summary'] }}</div><div class="hr"></div>
    <div>{{ $r['summary'] }}</div>
@endif

@if ($skillRows)
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['skills'] }}</div><div class="hr"></div>
    <table width="100%" cellspacing="0" cellpadding="0">
        @foreach ($skillRows as $row)
            <tr>@foreach (array_pad($row, 3, null) as $skill)<td class="skill" width="33%">@if ($skill)<span style="color: {{ $accent }};">◆</span>&nbsp; {{ $skill }}@endif</td>@endforeach</tr>
        @endforeach
    </table>
@endif

@if (! empty($r['experience']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['experience'] }}</div><div class="hr"></div>
    @foreach ($r['experience'] as $job)
        <div class="job">
            <table width="100%" cellspacing="0" cellpadding="0"><tr>
                <td class="role">{{ $job['role'] }}</td>
                <td class="period">{{ $job['period'] }}</td>
            </tr></table>
            @if ($job['employer'] || $job['place'])<div class="where">{{ implode(', ', array_filter([$job['employer'], $job['place']])) }}</div>@endif
            @if ($job['points'])<ul>@foreach ($job['points'] as $point)<li>{{ $point }}</li>@endforeach</ul>@endif
        </div>
    @endforeach
@endif

@if (! empty($r['education']) || ! empty($r['languages']))
    <table width="100%" cellspacing="0" cellpadding="0"><tr>
        <td width="55%" style="vertical-align: top; padding-right: 6mm;">
            @if (! empty($r['education']))
                <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['education'] }}</div><div class="hr"></div>
                @foreach ($r['education'] as $line)<div>{{ $line }}</div>@endforeach
            @endif
        </td>
        <td width="45%" style="vertical-align: top;">
            @if (! empty($r['languages']))
                <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['languages'] }}</div><div class="hr"></div>
                <div>{{ implode(', ', $r['languages']) }}</div>
            @endif
        </td>
    </tr></table>
@endif

@if (! empty($r['extras']) || ! empty($r['expected_wage']))
    <div class="h2{{ $hi ? '' : ' caps' }}">{{ $L['more'] }}</div><div class="hr"></div>
    <ul>
        @foreach ($r['extras'] ?? [] as $line)<li>{{ $line }}</li>@endforeach
        @if (! empty($r['expected_wage']))<li>{{ $L['salary'] }}: {{ $r['expected_wage'] }}</li>@endif
    </ul>
@endif
</body>
</html>
