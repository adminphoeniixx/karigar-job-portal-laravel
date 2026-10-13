@php
    $label = fn (string $en, string $hiText) => $hi ? $hiText : $en;
    $contact = array_filter([$r['phone'] ?? null, $r['email'] ?? null, $r['location'] ?? null]);
@endphp
<!DOCTYPE html>
<html lang="{{ $hi ? 'hi' : 'en' }}">
<head>
<meta charset="utf-8">
<style>
    body { font-family: freesans; font-size: 10.5pt; color: #2a1f1a; line-height: 1.45; }
    .name { font-size: 22pt; font-weight: bold; color: #1c1410; }
    .headline { font-size: 12pt; color: #c2410c; margin-top: 2pt; }
    .contact { font-size: 9.5pt; color: #6b5f57; margin-top: 4pt; }
    .rule { border-bottom: 1.2pt solid #c2410c; margin: 10pt 0 4pt; }
    h2 { font-size: 9.5pt; font-weight: bold; text-transform: uppercase; color: #c2410c; margin: 12pt 0 4pt; }
    /* Spacing letters apart breaks Devanagari conjuncts, so English only. */
    .en h2 { letter-spacing: 1pt; }
    p { margin: 0; }
    .skills { margin: 0; }
    .job { margin-bottom: 8pt; }
    .role { font-weight: bold; font-size: 11pt; }
    .where { color: #6b5f57; font-size: 9.5pt; }
    ul { margin: 2pt 0 0 0; padding-left: 14pt; }
    li { margin-bottom: 1.5pt; }
    .foot { margin-top: 18pt; font-size: 8pt; color: #a59a91; text-align: center; }
</style>
</head>
<body class="{{ $hi ? 'hi' : 'en' }}">
    <div class="name">{{ $r['name'] }}</div>
    @if (! empty($r['headline']))<div class="headline">{{ $r['headline'] }}</div>@endif
    @if ($contact)<div class="contact">{{ implode('  ·  ', $contact) }}</div>@endif
    <div class="rule"></div>

    @if (! empty($r['summary']))
        <h2>{{ $label('Summary', 'परिचय') }}</h2>
        <p>{{ $r['summary'] }}</p>
    @endif

    @if (! empty($r['skills']))
        <h2>{{ $label('Skills', 'हुनर') }}</h2>
        <p class="skills">{{ implode('  ·  ', $r['skills']) }}</p>
    @endif

    @if (! empty($r['experience']))
        <h2>{{ $label('Work experience', 'काम का अनुभव') }}</h2>
        @foreach ($r['experience'] as $job)
            <div class="job">
                <table width="100%" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class="role">{{ $job['role'] }}</td>
                        <td class="where" style="text-align: right;">{{ $job['period'] }}</td>
                    </tr>
                </table>
                @if ($job['employer'] || $job['place'])
                    <div class="where">{{ implode(', ', array_filter([$job['employer'], $job['place']])) }}</div>
                @endif
                @if ($job['points'])
                    <ul>
                        @foreach ($job['points'] as $point)<li>{{ $point }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif

    @if (! empty($r['education']))
        <h2>{{ $label('Education & training', 'पढ़ाई और ट्रेनिंग') }}</h2>
        <ul>@foreach ($r['education'] as $line)<li>{{ $line }}</li>@endforeach</ul>
    @endif

    @if (! empty($r['languages']))
        <h2>{{ $label('Languages', 'भाषाएँ') }}</h2>
        <p>{{ implode(', ', $r['languages']) }}</p>
    @endif

    @if (! empty($r['extras']) || ! empty($r['expected_wage']))
        <h2>{{ $label('More', 'और जानकारी') }}</h2>
        <ul>
            @foreach ($r['extras'] ?? [] as $line)<li>{{ $line }}</li>@endforeach
            @if (! empty($r['expected_wage']))<li>{{ $label('Expected salary', 'अपेक्षित वेतन') }}: {{ $r['expected_wage'] }}</li>@endif
        </ul>
    @endif

    <div class="foot">superkarigar.com</div>
</body>
</html>
