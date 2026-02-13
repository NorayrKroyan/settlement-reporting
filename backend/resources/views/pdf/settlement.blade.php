<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Settlement Draft Notice</title>
    <style>
        @page { margin: 22pt; }

        /* FORCE SAME FONT EVERYWHERE (prevents serif fallback in DomPDF) */
        html, body, table, tr, td, th, div, span {
            font-family: DejaVu Sans, Arial, sans-serif !important;
        }

        body { font-size: 11px; color: #111; }

        /* Header */
        .header {
            background: #000;
            color: #fff;
            padding: 14pt 14pt;
            border-radius: 14pt;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .title { font-size: 28px; font-weight: 700; text-align: center; }
        .subtitle { font-size: 10px; text-align: left; margin-top: 2pt; color: #ddd; }
        .week { font-size: 12px; font-weight: 700; text-align: right; white-space: nowrap; }
        .client-gold { color: #c69b00; font-weight: 700; font-size: 12px; text-align: center; margin-top: 10pt; }

        .hr { border-top: 1px solid #e6e6e6; margin: 12pt 0; }

        /* 2-col / 3-col */
        .row4 { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .row4 td { width: 25%; vertical-align: top; }
        .mono { font-size: 10px; color: #666; }
        .bold { font-weight: 700; }

        .section-title { font-size: 11px; color: #444; letter-spacing: .4px; text-transform: uppercase; margin-bottom: 6pt; }

        /* 2-col section */
        .twocol { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .twocol td { vertical-align: top; }
        .leftcol { width: 68%; padding-right: 10pt; }
        .rightcol { width: 32%; }

        /* ===== Tables: REAL GRID + correct alignment ===== */
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th,
        table.grid td {
            border: 1px solid #e0e0e0;
            padding: 3pt 6pt;   /* tighter top/bottom */
            overflow: hidden;
            line-height: 1.1;  /* compact rows */
        }
        table.grid th { background: #f6f6f6; font-weight: 700; white-space: nowrap; text-align: left; }
        table.grid th.num, table.grid td.num { text-align: right; white-space: nowrap; }

        /* Repeat headers on new page */
        thead { display: table-header-group; }
        tfoot { display: table-row-group; }
        tr { page-break-inside: avoid; }

        /* Driver band shading (per driver group, not per row) */
        tr.band-alt td {
            background: #f0f4f9;   /* soft light blue-gray */
        }

        /* Totals box */
        .totals {
            border: 1px solid #e5e5e5;
            background: #fafafa;
            border-radius: 10pt;
            padding: 10pt;
        }
        .totals-row { width: 100%; border-collapse: collapse; }
        .totals-row td { padding: 4pt 0; }
        .totals-row td:last-child { text-align: right; white-space: nowrap; font-weight: 700; }
        .net {font-family: DejaVu Sans, Arial, sans-serif !important; font-size: 13px; font-weight: 700; }

        /* Misc watermark area */
        .miscwrap { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .miscwrap td { vertical-align: top; }

        /* WATERMARK TYPOGRAPHY MATCH (DomPDF-safe) */
        .watermark {
            font-family: DejaVu Sans, Arial, sans-serif !important;
            font-weight: 700 !important;
            color: #dc0000 !important;
            font-size: 28px !important;
            line-height: 1.05 !important;
            letter-spacing: 0.5px !important;
            text-align: center !important;
        }

        .misc-right {
            width: 32%;
            vertical-align: top;
            padding-top: 18px; /* same feel as sticky top:18px */
        }

        .row3 {
            width: 80%;
            margin: 0 auto;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .row3 td {
            width: 33.33%;
            vertical-align: top;
            text-align: center;
        }

        .row2 {
            width: 100%;
            border-collapse: collapse;
        }

        .row2 td {
            width: 50%;
            vertical-align: middle;
        }

        .inline-pair {
            display: inline-block;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 18px;      /* main size */
            line-height: 1.2;
        }

        .inline-pair .label {
            color: #666;
            font-size: 18px;
            margin-right: 6px;
        }

        .inline-pair .value {
            font-weight: 700;
            font-size: 18px;   /* slightly bigger */
            color: #000;
        }

        .align-right {
            text-align: right;
        }


    </style>
</head>
<body>
@php
    $money = function ($n) {
        $x = (float)($n ?? 0);
        $sign = ($x < 0) ? '-' : '';
        return $sign . '$' . number_format(abs($x), 2);
    };

    $fmt = function ($s) {
        if (!$s) return '';
        $s = (string)$s;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return $m[2].'-'.$m[3].'-'.$m[1];

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) {
            $mm = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $dd = str_pad($m[2], 2, '0', STR_PAD_LEFT);
            return $mm.'-'.$dd.'-'.$m[3];
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $s)) return $s;

        $ts = strtotime($s);
        if ($ts) return date('m-d-Y', $ts);

        return $s;
    };

    // Week # (based on END date)
    $week = null;
    if (!empty($s['enddate'])) {
        $ts = strtotime($s['enddate']);
        if ($ts) $week = (int) date('W', $ts);
    }

    $depositDateRaw = $s['deposit_date'] ?? $s['depositdate'] ?? $s['depositDate'] ?? ($s['depositDate'] ?? null);
    $depositDate = $fmt($depositDateRaw);

    $gross = (float)($s['grossamount'] ?? 0);
    $factPct = (float)($s['factorpercent'] ?? 0);
    $factoring = -1 * (float)($s['factorcostamount'] ?? 0);
    $miscTotal = (float)($s['misc_total'] ?? 0);
    $net = (float)($s['netamount'] ?? 0);

    $driverRows = $s['driver_rows'] ?? [];
    $miscRows = $s['misc_rows'] ?? [];
    $loadRows = $s['load_rows'] ?? [];

    usort($loadRows, function($a, $b) {
        $da = strtolower((string)($a['driver'] ?? ''));
        $db = strtolower((string)($b['driver'] ?? ''));
        if ($da < $db) return -1;
        if ($da > $db) return 1;

        $ta = strtotime((string)($a['date'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['date'] ?? '')) ?: 0;
        return $ta <=> $tb;
    });
@endphp

<div class="header">
    <table class="header-table">
        <tr>
            <td style="width: 30%;">
                @if(!empty($logoPath))
                    <img src="{{ $logoPath }}" style="height: 44px;">
                @endif
            </td>
            <td style="width: 44%;">
                <div class="title">Settlement Report</div>
            </td>
            <td style="width: 26%;" class="week">
                Week {{ $week ?? '' }}
            </td>
        </tr>
    </table>

    @if(!empty($s['client_name']))
        <table style="width:100%; margin-top:6pt; border-collapse:collapse;">
            <tr>
                <!-- LEFT: Subtitle -->
                <td style="width:60%; text-align:left; font-size:10px; color:#ddd;">
                    Please email invoices@voldhaul.com with any changes or corrections.
                </td>

                <!-- RIGHT: Client -->
                <td style="width:40%; text-align:right;">
                    <span style="color:#ffffff; font-weight:700;">Client:</span>
                    <span style="color:#c69b00; font-weight:700;">
                {{ $s['client_name'] }}
            </span>
                </td>
            </tr>
        </table>
    @endif

</div>


<table class="row2">
    <tr>
        <!-- LEFT -->
        <td>
            <div class="inline-pair">
                <span class="label">Carrier</span>
                <span class="value">{{ $s['carrier_name'] ?? '' }}</span>
            </div>
        </td>

        <!-- RIGHT -->
        <td class="align-right">
            <div class="inline-pair">
                <span class="label">Period</span>
                <span class="value">
                    {{ $fmt($s['startdate'] ?? '') }} → {{ $fmt($s['enddate'] ?? '') }}
                </span>
            </div>
        </td>
    </tr>
</table>

<table class="twocol">
    <tr>
        <td class="leftcol">
            <div class="section-title">Driver Earnings</div>

            <table class="grid">
                <thead>
                <tr>
                    <th style="width: 34%;">Driver</th>
                    <th style="width: 14%;" class="num">Loads</th>
                    <th style="width: 14%;" class="num">Tons</th>
                    <th style="width: 14%;" class="num">Miles</th>
                    <th style="width: 24%;" class="num">Revenue</th>
                </tr>
                </thead>
                <tbody>
                @php $prevDrv = null; $band = 0; @endphp
                @forelse($driverRows as $r)
                    @php
                        $drv = (string)($r['driver'] ?? '');
                        if ($drv !== $prevDrv) { $prevDrv = $drv; $band++; }
                        $cls = ($band % 2 === 0) ? 'band-alt' : '';
                    @endphp
                    <tr class="{{ $cls }}">
                        <td>{{ $r['driver'] ?? 'Unknown' }}</td>
                        <td class="num">{{ $r['loads'] ?? 0 }}</td>
                        <td class="num">{{ number_format((float)($r['tons'] ?? 0), 2) }}</td>
                        <td class="num">{{ $r['miles'] ?? 0 }}</td>
                        <td class="num">{{ $money($r['revenue'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" style="color:#666;">No driver rows found for this period.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </td>

        <td class="rightcol">
            <div class="section-title">Totals</div>

            <div class="totals">
                <table class="totals-row">
                    <tr>
                        <td>Total Driver Earnings</td>
                        <td>{{ $money($gross) }}</td>
                    </tr>
                    <tr>
                        <td>Factoring Fee ({{ number_format($factPct, 2) }}%)</td>
                        <td>{{ $money($factoring) }}</td>
                    </tr>
                    <tr>
                        <td>Misc Charges &amp; Credits</td>
                        <td>{{ $money($miscTotal) }}</td>
                    </tr>
                </table>

                <div class="hr" style="margin:10pt 0;"></div>

                <table class="totals-row">
                    <tr>
                        <td class="net">Net Deposit</td>
                        <td class="net">{{ $money($net) }}</td>
                    </tr>
                    <tr>
                        <td>
                            <div class="net">Deposit Date</div>
                        </td>
                        <td>
                            <div class="net">{{ $depositDate }}</div>
                        </td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="hr"></div>

<table class="miscwrap">
    <tr>
        <td style="width: 68%; padding-right:10pt;">
            <div class="section-title">Misc Charges and Credits</div>

            <table class="grid">
                <thead>
                <tr>
                    <th style="width:44%;">Description</th>
                    <th style="width:18%;">Date</th>
                    <th style="width:18%;" class="num">Charge</th>
                    <th style="width:20%;" class="num">Credit</th>
                </tr>
                </thead>
                <tbody>
                @forelse($miscRows as $m)
                    @php
                        $desc = $m['description'] ?? '';
                        if (!empty($m['charge_source_desc'])) $desc .= ' — ' . $m['charge_source_desc'];
                    @endphp
                    <tr>
                        <td>{{ $desc }}</td>
                        <td>{{ $fmt($m['date'] ?? '') }}</td>
                        <td class="num">{{ $money($m['charge'] ?? 0) }}</td>
                        <td class="num">{{ $money($m['credit'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="color:#666;">No misc charges/credits yet.</td>
                    </tr>
                @endforelse

                <tr>
                    <td colspan="2"><b>Total Misc</b></td>
                    <td class="num"><b>{{ $money($s['misc_charge_total'] ?? 0) }}</b></td>
                    <td class="num"><b>{{ $money($s['misc_credit_total'] ?? 0) }}</b></td>
                </tr>
                <tr>
                    <td colspan="2" style="color:#666;">Credits − Charges</td>
                    <td colspan="2" class="num"><b>{{ $money($s['misc_total'] ?? 0) }}</b></td>
                </tr>
                </tbody>
            </table>
        </td>

        <td class="misc-right">
            <div class="watermark">
                DRAFT COPY<br>FOR REVIEW
            </div>
        </td>
    </tr>
</table>

<div class="hr"></div>

<div class="section-title">Load Details</div>

<table class="grid">
    <thead>
    <tr>
        <th style="width:18%;">Driver</th>
        <th style="width:14%;">Date</th>
        <th style="width:14%;">Load #</th>
        <th style="width:14%;">Ticket #</th>
        <th style="width:14%;" class="num">Tons</th>
        <th style="width:14%;" class="num">Miles</th>
        <th style="width:12%;" class="num">Carrier Pay</th>
    </tr>
    </thead>
    <tbody>
    @php $prevDrv2 = null; $band2 = 0; @endphp
    @forelse($loadRows as $l)
        @php
            $drv2 = (string)($l['driver'] ?? '');
            if ($drv2 !== $prevDrv2) { $prevDrv2 = $drv2; $band2++; }
            $cls2 = ($band2 % 2 === 0) ? 'band-alt' : '';
        @endphp
        <tr class="{{ $cls2 }}">
            <td>{{ $l['driver'] ?? 'Unknown' }}</td>
            <td>{{ $fmt($l['date'] ?? '') }}</td>
            <td>{{ $l['load_number'] ?? '-' }}</td>
            <td>{{ $l['ticket_number'] ?? '-' }}</td>
            <td class="num">{{ number_format((float)($l['tons'] ?? 0), 2) }}</td>
            <td class="num">{{ $l['miles'] ?? 0 }}</td>
            <td class="num">{{ $money($l['carrier_pay'] ?? 0) }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="7" style="color:#666;">No loads linked to this settlement.</td>
        </tr>
    @endforelse
    </tbody>
</table>

</body>
</html>
