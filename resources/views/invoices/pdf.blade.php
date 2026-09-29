@php
    // DejaVu Sans is bundled with dompdf and is the one of its fonts that has ₹.
    $inr = fn ($v) => $v === null ? '—' : '₹'.number_format((float) $v, 2);
    $rate = (float) ($invoice['gst_percent'] ?? 0);
    $fmtRate = fn ($r) => rtrim(rtrim(number_format($r, 2), '0'), '.');
    $intra = (float) ($invoice['cgst_amount'] ?? 0) > 0;
    $split = $invoice['cgst_amount'] !== null || $invoice['igst_amount'] !== null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Tax Invoice {{ $invoice['number'] }}</title>
<style>
    @page { margin: 36px 40px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #2a1f1a; }
    .muted { color: #7a6e66; }
    .small { font-size: 9.5px; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .title { font-size: 20px; font-weight: bold; letter-spacing: 1px; }
    .rule { border-top: 1px solid #e3dccf; margin: 16px 0; }
    .label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #7a6e66; font-weight: bold; }
    .items th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.6px; color: #7a6e66; border-bottom: 1px solid #e3dccf; padding: 0 0 6px; }
    .items td { padding: 8px 0; border-bottom: 1px solid #efe9df; }
    .right, .items th.right { text-align: right; }
    .total td { font-size: 13px; font-weight: bold; border-bottom: 0; padding-top: 10px; }
</style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width: 60%;">
                @if (is_file($logo))
                    <img src="{{ $logo }}" style="height: 52px;" alt="Super Karigar">
                @endif
                <div style="margin-top: 10px; font-size: 12px; font-weight: bold;">{{ $seller['name'] }}</div>
                @if ($seller['address'])<div class="muted small" style="margin-top: 3px; width: 260px;">{{ $seller['address'] }}</div>@endif
                @if ($seller['gstin'])<div class="small" style="margin-top: 3px;"><b>GSTIN:</b> {{ $seller['gstin'] }}</div>@endif
                @if ($seller['email'])<div class="muted small">{{ $seller['email'] }}</div>@endif
            </td>
            <td class="right">
                <div class="title">TAX INVOICE</div>
                <div style="margin-top: 6px; font-weight: bold;">{{ $invoice['number'] }}</div>
                <div class="muted small">Date: {{ $invoice['date'] }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="head">
        <tr>
            <td style="width: 55%;">
                <div class="label">Billed to</div>
                <div style="margin-top: 4px; font-weight: bold;">{{ $buyer['name'] }}</div>
                @if ($buyer['address'])<div class="muted">{{ $buyer['address'] }}</div>@endif
                @if ($buyer['gstin'])<div style="margin-top: 2px;"><b>GSTIN:</b> {{ $buyer['gstin'] }}</div>@endif
                <div class="muted">{{ $buyer['phone'] ?: $buyer['email'] }}</div>
            </td>
            <td class="right">
                @if ($invoice['place_of_supply'])
                    <div class="label">Place of supply</div>
                    <div style="margin-top: 4px;">{{ $invoice['place_of_supply'] }}</div>
                @endif
                <div class="label" style="margin-top: 8px;">Payment reference</div>
                <div style="margin-top: 4px;">{{ $invoice['payment_ref'] ?: '—' }}</div>
                @if ($invoice['period']['from'])
                    <div class="muted small" style="margin-top: 6px;">Service period: {{ $invoice['period']['from'] }} – {{ $invoice['period']['to'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items" style="margin-top: 22px;">
        <thead>
            <tr><th>Description</th><th>SAC</th><th class="right">Amount</th></tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    <b>{{ $invoice['plan']['name'] }} plan — subscription</b>
                    <div class="muted small">Billed {{ $invoice['plan']['interval'] }}</div>
                </td>
                <td>{{ $invoice['sac'] ?: '—' }}</td>
                <td class="right">{{ $inr($invoice['plan']['price']) }}</td>
            </tr>
            @if ($invoice['discount'])
                <tr>
                    <td colspan="2">Coupon discount @if ($invoice['coupon_code'])<span class="muted">({{ $invoice['coupon_code'] }})</span>@endif</td>
                    <td class="right">− {{ $inr($invoice['discount']) }}</td>
                </tr>
            @endif
            <tr>
                <td colspan="2" class="muted">Taxable value</td>
                <td class="right">{{ $inr($invoice['subtotal']) }}</td>
            </tr>
            @if ($split && $intra)
                <tr><td colspan="2" class="muted">CGST ({{ $fmtRate($rate / 2) }}%)</td><td class="right">{{ $inr($invoice['cgst_amount']) }}</td></tr>
                <tr><td colspan="2" class="muted">SGST ({{ $fmtRate($rate / 2) }}%)</td><td class="right">{{ $inr($invoice['sgst_amount']) }}</td></tr>
            @elseif ($split)
                <tr><td colspan="2" class="muted">IGST ({{ $fmtRate($rate) }}%)</td><td class="right">{{ $inr($invoice['igst_amount']) }}</td></tr>
            @else
                <tr><td colspan="2" class="muted">GST ({{ $fmtRate($rate) }}%)</td><td class="right">{{ $inr($invoice['gst_amount']) }}</td></tr>
            @endif
            <tr class="total">
                <td colspan="2">Total paid</td>
                <td class="right">{{ $inr($invoice['total']) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="muted small" style="margin-top: 40px; text-align: center;">
        This is a computer-generated tax invoice and does not require a signature.
    </p>
</body>
</html>
