<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Settlement Viewer List</title>
    <style>
        @page { margin: 10pt; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }

        .header { margin-bottom: 8pt; }
        .title { font-size: 12px; font-weight: 700; margin: 0 0 4pt 0; }
        .meta { font-size: 8px; color: #666; }

        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td {
            border: 1px solid #ddd;
            padding: 1px 2px;
            vertical-align: top;
            overflow: hidden;
            text-overflow: ellipsis;
            word-wrap: break-word;
        }
        th { background: #f3f3f3; font-weight: 700; text-align: left; white-space: nowrap; }
        .num { text-align: right; white-space: nowrap; }
        .row-alt td { background: #eef2f7; }

        /* widths tuned for LANDSCAPE like your UI */
        .w-id      { width: 3%;  }
        .w-client  { width: 12%; }
        .w-carrier { width: 23%; }
        .w-range   { width: 20%; }
        .w-gross   { width: 12%; }
        .w-factor  { width: 8%;  }
        .w-adj     { width: 8%;  }
        .w-net     { width: 10%;  }
    </style>
</head>
<body>
<div class="header">
    <div class="title">Settlements List</div>
    <div class="meta">
        Generated: {{ $generatedAt }} • Showing latest {{ $limit }} settlements
        @if(!empty($filterClient) || !empty($filterCarrier))
            • Filters:
            @if(!empty($filterClient)) Client={{ $filterClient }} @endif
            @if(!empty($filterCarrier)) Carrier={{ $filterCarrier }} @endif
        @endif
    </div>
</div>

<table>
    <thead>
    <tr>
        <th class="w-id">ID</th>
        <th class="w-client">Client</th>
        <th class="w-carrier">Carrier</th>
        <th class="w-range">Date Range</th>
        <th class="w-gross">Gross Amount</th>
        <th class="w-factor">Factoring</th>
        <th class="w-adj">Adjust.</th>
        <th class="w-net">Net Deposit</th>
    </tr>
    </thead>
    <tbody>
    @forelse($rows as $i => $r)
        @php
            $client = trim((string)($r->client_name ?? ''));
            if ($client === '') $client = (string)($r->clientid ?? '');

            $carrier = trim((string)($r->carrier_name ?? ''));
            if ($carrier === '') $carrier = (string)($r->carrierid ?? '');

            // ✅ direct DB fields (no recalculation)
            $gross  = (float)($r->grossamount ?? 0);
            $factor = (float)($r->factorcostamount ?? 0);

            // Remove minus from chargeback
            $adj    = abs((float)($r->chargebackamount ?? ($r->adjustments ?? 0)));

            $net    = (float)($r->netamount ?? 0);
        @endphp


        <tr class="{{ $i % 2 === 1 ? 'row-alt' : '' }}">
            <td>{{ $r->id }}</td>
            <td>{{ $client }}</td>
            <td>{{ $carrier }}</td>
            <td>{{ $r->startdate }} → {{ $r->enddate }}</td>
            <td class="num">${{ number_format($gross, 2) }}</td>
            <td class="num">${{ number_format($factor, 2) }}</td>
            <td class="num">${{ number_format($adj, 2) }}</td>
            <td class="num">${{ number_format($net, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="8">No settlements found.</td></tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
