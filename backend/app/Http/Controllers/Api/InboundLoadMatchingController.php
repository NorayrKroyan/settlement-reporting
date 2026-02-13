<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\InboundLoadMatchingService;

class InboundLoadMatchingController extends Controller
{
    public function queue(Request $request, InboundLoadMatchingService $svc)
    {
        $limit = (int)($request->query('limit', 200));
        $limit = max(1, min($limit, 500));

        $only = strtolower((string)$request->query('only', 'unprocessed')); // unprocessed|all
        $q = (string)$request->query('q', '');
        $match = strtoupper((string)$request->query('match', '')); // GREEN|YELLOW|RED

        $rows = $svc->buildQueue($limit, $only, $q, $match);

        return response()->json([
            'ok' => true,
            'count' => count($rows),
            'rows' => $rows,
        ]);
    }

    public function process(Request $request, InboundLoadMatchingService $svc)
    {
        $importId = (int)($request->input('import_id') ?? 0);
        if ($importId <= 0) {
            return response()->json(['ok' => false, 'error' => 'import_id is required'], 422);
        }

        $res = $svc->processImport($importId);

        $status = ($res['ok'] ?? false) ? 200 : 422;
        return response()->json($res, $status);
    }
}
