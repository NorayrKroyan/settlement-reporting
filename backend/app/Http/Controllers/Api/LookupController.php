<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LookupController extends Controller
{
    // Distinct client names from fatloads
    public function clients()
    {
        $clients = DB::table('fatloads')
            ->whereNotNull('client_name')
            ->where('client_name', '!=', '')
            ->distinct()
            ->orderBy('client_name')
            ->pluck('client_name')
            ->values();

        return response()->json(['data' => $clients]);
    }

    // Distinct carrier names (optionally filtered by client_name)
    public function carriers(Request $request)
    {
        $q = DB::table('fatloads')
            ->whereNotNull('carrier_name')
            ->where('carrier_name', '!=', '');

        $client = $request->query('client_name');
        if ($client) {
            $q->whereRaw("TRIM(client_name) = TRIM(?)", [$client]);
        }

        $carriers = $q->distinct()
            ->orderBy('carrier_name')
            ->pluck('carrier_name')
            ->values();

        return response()->json(['data' => $carriers]);
    }

    /**
     * GET /api/lookups/factor-percent?client_name=...&carrier_name=...
     *
     * Returns default factoring percent for the selection.
     *
     * Priority:
     * 1) If bill_settlements has historical settlements for (client|carrier), use the latest factorpercent
     * 2) Else fallback to config default (2.5)
     */
    public function factorPercent(Request $request)
    {
        $client = trim((string)$request->query('client_name', ''));
        $carrier = trim((string)$request->query('carrier_name', ''));

        $default = (float)config('settlements.default_factor_percent', 2.5);

        if ($client === '' || $carrier === '') {
            return response()->json(['data' => $default]);
        }

        $desc = $client . ' | ' . $carrier;

        $factor = null;

        if (Schema::hasTable('bill_settlements')) {
            $row = DB::table('bill_settlements')
                ->whereRaw("TRIM(`desc`) = TRIM(?)", [$desc])
                ->orderByDesc('id_bill_settlements')
                ->select(['factorpercent'])
                ->first();

            if ($row && $row->factorpercent !== null) {
                $factor = (float)$row->factorpercent;
            }
        }

        return response()->json(['data' => $factor ?? $default]);
    }
}
