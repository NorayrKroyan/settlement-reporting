<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SettlementHistoryController extends Controller
{
    /**
     * Plain history list: latest N settlements (default 50)
     * GET /api/settlements/history?limit=50
     */
    public function history(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $rows = DB::table('bill_settlements as s')
            ->leftJoin('clients as cl', function ($join) {
                $join->on('cl.client_id', '=', 's.clientid');
                // optional: only non-deleted clients
                // $join->where('cl.is_deleted', '=', 0);
            })
            ->leftJoin('carrier as ca', function ($join) {
                $join->on('ca.id_carrier', '=', 's.carrierid');
                // optional: only non-deleted carriers
                // $join->where('ca.is_deleted', '=', 0);
            })
            ->select([
                's.id_bill_settlements as id',
                's.clientid',
                DB::raw('COALESCE(cl.client_name, "") as client_name'),
                's.carrierid',
                DB::raw('COALESCE(ca.carrier_name, "") as carrier_name'),
                's.desc',
                's.startdate',
                's.enddate',
                's.expectdepositdate',
                's.factorpercent',
                's.grossamount',
                's.factorcostamount',
                's.chargebackamount',
                's.netamount',
            ])
            ->orderByDesc('s.id_bill_settlements')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * PDF of the same list shown in viewer
     * GET /api/settlements/history/pdf?limit=50
     */
    public function historyPdf(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $client = trim((string) $request->query('client', ''));
        $carrier = trim((string) $request->query('carrier', ''));

        // ✅ Date filters coming from frontend computeRange()
        $startDate = trim((string) $request->query('start_date', '')); // YYYY-MM-DD
        $endDate   = trim((string) $request->query('end_date', ''));   // YYYY-MM-DD

        $mode = (string) $request->query('mode', 'all'); // all | page
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, min((int) $request->query('page_size', 10), 200));

        $q = DB::table('bill_settlements as s')
            ->leftJoin('clients as cl', function ($join) {
                $join->on('cl.client_id', '=', 's.clientid');
            })
            ->leftJoin('carrier as ca', function ($join) {
                $join->on('ca.id_carrier', '=', 's.carrierid');
            })
            ->select([
                's.id_bill_settlements as id',

                's.clientid',
                DB::raw('COALESCE(cl.client_name, "") as client_name'),

                's.carrierid',
                DB::raw('COALESCE(ca.carrier_name, "") as carrier_name'),

                's.startdate',
                's.enddate',
                's.grossamount',
                's.factorcostamount',
                's.chargebackamount',
                's.netamount',
            ]);

        // ✅ Apply UI filters (exact match)
        if ($client !== '') {
            $q->where(DB::raw('COALESCE(cl.client_name, "")'), '=', $client);
        }
        if ($carrier !== '') {
            $q->where(DB::raw('COALESCE(ca.carrier_name, "")'), '=', $carrier);
        }

        // ✅ Apply date filters (rolling 14/21 days etc.)
        // NOTE: uses s.startdate; change to expectdepositdate if that's your business date.
        if ($startDate !== '') {
            $q->whereDate('s.startdate', '>=', $startDate);
        }
        if ($endDate !== '') {
            $q->whereDate('s.startdate', '<=', $endDate);
        }

        $q->orderByDesc('s.id_bill_settlements');

        // ✅ Export mode
        if ($mode === 'page') {
            $q->forPage($page, $pageSize);
        } else {
            $q->limit($limit);
        }

        $rows = $q->get();

        $rows = $rows->map(function ($r) {
            $r->adjustments = (float) ($r->chargebackamount ?? 0);
            return $r;
        });

        $pdf = Pdf::loadView('pdf.settlement_history', [
            'rows' => $rows,
            'limit' => $mode === 'page' ? $pageSize : $limit,
            'generatedAt' => now()->toDateTimeString(),

            'filterClient' => $client,
            'filterCarrier' => $carrier,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'mode' => $mode,
            'page' => $page,
        ])->setPaper('a4', 'portrait');

        $file = 'settlement_viewer_list_' . now()->format('Y-m-d_His') . '.pdf';
        return $pdf->download($file);
    }
}
