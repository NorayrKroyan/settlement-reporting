<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SettlementListQuery
{
    public function build(array $filters)
    {
        $q = DB::table('bill_settlements as s')
            ->select([
                's.id_bill_settlement',
                's.client_name',
                's.carrier_name',
                's.startdate',
                's.enddate',
                's.grossamount',
                's.factorcostamount',
                DB::raw('ABS(COALESCE(s.chargebackamount, s.adjustments, 0)) as chargebackamount'),
                's.netamount',
                's.created_at',
            ]);

        // Filters (add what you have in UI)
        if (!empty($filters['client_name'])) {
            $q->where('s.client_name', $filters['client_name']);
        }
        if (!empty($filters['carrier_name'])) {
            $q->where('s.carrier_name', $filters['carrier_name']);
        }
        if (!empty($filters['start_date'])) {
            $q->whereDate('s.startdate', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $q->whereDate('s.enddate', '<=', $filters['end_date']);
        }

        return $q;
    }
}
