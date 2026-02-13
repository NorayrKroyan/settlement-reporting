<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettlementBuilderService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettlementController extends Controller
{
    public function build(Request $request, SettlementBuilderService $builder)
    {
        $v = Validator::make($request->all(), [
            'client_name'    => ['required', 'string', 'max:190'],
            'carrier_name'   => ['required', 'string', 'max:190'],

            'client_id'      => ['nullable', 'integer', 'min:1'],
            'carrier_id'     => ['nullable', 'integer', 'min:1'],

            'start_date'     => ['required', 'date'],
            'end_date'       => ['required', 'date', 'after_or_equal:start_date'],

            'deposit_date'   => ['required', 'date'],
            'factor_percent' => ['required', 'numeric', 'min:0', 'max:100'],

            // ✅ Vue sends these when editing/saving revision
            'force_rebuild'      => ['nullable', 'boolean'],
            'base_settlement_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'message' => 'Missing information, error',
                'errors'  => $v->errors(),
            ], 422);
        }

        $result = $builder->build([
            'client_name'    => $request->input('client_name'),
            'carrier_name'   => $request->input('carrier_name'),

            'client_id'      => $request->input('client_id'),
            'carrier_id'     => $request->input('carrier_id'),

            'start_date'     => $request->input('start_date'),
            'end_date'       => $request->input('end_date'),
            'deposit_date'   => $request->input('deposit_date'),
            'factor_percent' => round((float)($request->input('factor_percent') ?? 0), 2),

            // ✅ pass-through edit flags
            'force_rebuild'      => (bool)$request->input('force_rebuild', false),
            'base_settlement_id' => $request->input('base_settlement_id'),
        ]);

        return response()->json(['data' => $result], 201);
    }

    public function show(int $id, SettlementBuilderService $builder)
    {
        $view = $builder->view($id);
        if (!$view) {
            return response()->json(['message' => 'Settlement not found'], 404);
        }
        return response()->json(['data' => $view]);
    }

    /**
     * GET /api/settlements/{id}/pdf?inline=1
     * - inline=1 -> open in browser
     * - default  -> download
     */
    public function pdf(int $id, Request $request, SettlementBuilderService $builder)
    {
        $s = $builder->view($id);
        if (!$s) {
            return response()->json(['message' => 'Settlement not found'], 404);
        }

        $logoPath = public_path('brand/corp_logo_small.svg');
        $hasLogo = is_file($logoPath);

        $pdf = Pdf::loadView('pdf.settlement', [
            's' => $s,
            'logoPath' => $hasLogo ? $logoPath : null,
        ])->setPaper('a4', 'portrait');

        $file = 'settlement_' .
            preg_replace('/[^a-zA-Z0-9._-]+/', '_', ($s['client_name'] ?? 'client')) . '_' .
            preg_replace('/[^a-zA-Z0-9._-]+/', '_', ($s['carrier_name'] ?? 'carrier')) . '_' .
            ($s['startdate'] ?? '') . '_' . ($s['enddate'] ?? '') . '.pdf';

        if ($request->boolean('inline')) {
            return $pdf->stream($file);
        }

        return $pdf->download($file);
    }
}
