<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettlementBuilderService
{
    public function build(array $params): array
    {
        $client = trim((string)($params['client_name'] ?? ''));
        $carrier = trim((string)($params['carrier_name'] ?? ''));
        $start = (string)($params['start_date'] ?? '');
        $end = (string)($params['end_date'] ?? '');

        $clientIdParam = isset($params['client_id']) ? (int)$params['client_id'] : 0;
        $carrierIdParam = isset($params['carrier_id']) ? (int)$params['carrier_id'] : 0;

        $depositDate = $params['deposit_date']
            ?? ($params['expectdepositdate'] ?? ($params['paiddate'] ?? null));

        $depositDate = is_string($depositDate) ? trim($depositDate) : null;
        if ($depositDate === '') $depositDate = null;

        $factorPercent = round((float)($params['factor_percent'] ?? 0), 2);

        if ($client === '' || $carrier === '') {
            throw new \InvalidArgumentException('client_name and carrier_name are required');
        }
        if ($start === '' || $end === '') {
            throw new \InvalidArgumentException('start_date and end_date are required');
        }

        // ✅ IMPORTANT:
        // We accept edit flags, but we DO NOT update existing rows.
        // Every build is a NEW settlement row.
        $id = $this->createNewSettlement(
            $client,
            $carrier,
            $start,
            $end,
            $depositDate,
            $factorPercent,
            $clientIdParam > 0 ? $clientIdParam : null,
            $carrierIdParam > 0 ? $carrierIdParam : null
        );

        return ['id' => $id, 'reused' => false];
    }

    private function createNewSettlement(
        string $client,
        string $carrier,
        string $start,
        string $end,
        ?string $depositDate,
        float $factorPercent,
        ?int $clientIdParam = null,
        ?int $carrierIdParam = null
    ): int {
        $hasClientId = Schema::hasColumn('fatloads', 'client_id');
        $hasCarrierId = Schema::hasColumn('fatloads', 'id_carrier');

        $selectCols = [
            'id_load',
            'load_number',
            'ticket_number',
            'first_name',
            'last_name',
            'tons',
            'miles',
            'carrier_pay',
            'load_date as load_date_for_ui',
            DB::raw("STR_TO_DATE(load_date, '%m-%d-%Y') as load_date_sort"),
        ];

        if ($hasClientId) $selectCols[] = 'client_id';
        if ($hasCarrierId) $selectCols[] = 'id_carrier';

        $loads = DB::table('fatloads')
            ->select($selectCols)
            ->whereRaw("TRIM(client_name) = TRIM(?)", [$client])
            ->whereRaw("TRIM(carrier_name) = TRIM(?)", [$carrier])
            ->whereRaw("STR_TO_DATE(load_date, '%m-%d-%Y') BETWEEN ? AND ?", [$start, $end])
            ->orderBy('load_date_sort')
            ->get();

        // ✅ Resolve IDs (priority: params -> fatloads -> lookup by name)
        $clientTableId = $clientIdParam ?: null;
        $carrierTableId = $carrierIdParam ?: null;

        if ($loads->count() > 0) {
            if ($clientTableId === null && $hasClientId) {
                foreach ($loads as $l) {
                    if ($l->client_id !== null && $l->client_id !== '') {
                        $clientTableId = (int)$l->client_id;
                        break;
                    }
                }
            }

            if ($carrierTableId === null && $hasCarrierId) {
                foreach ($loads as $l) {
                    if ($l->id_carrier !== null && $l->id_carrier !== '') {
                        $carrierTableId = (int)$l->id_carrier;
                        break;
                    }
                }
            }
        }

        if ($carrierTableId === null) $carrierTableId = $this->carrierIdByName($carrier);
        if ($clientTableId === null) $clientTableId = $this->clientIdByName($client);

        // ✅ HARD FAIL if still unresolved (prevents NULL saves)
        if (!$clientTableId) {
            throw new \InvalidArgumentException("Client id could not be resolved for client_name='{$client}'.");
        }
        if (!$carrierTableId) {
            throw new \InvalidArgumentException("Carrier id could not be resolved for carrier_name='{$carrier}'.");
        }

        // Gross
        $gross = 0.0;
        foreach ($loads as $l) $gross += (float)($l->carrier_pay ?? 0);
        $gross = round($gross, 2);

        $factorCost = round($gross * ($factorPercent / 100.0), 2);

        // Misc totals (credits - charges)
        $misc = $this->calcMiscTotals($carrierTableId, $start, $end);
        $miscTotal = $misc['misc_total'];

        // Net includes misc
        $net = round($gross - $factorCost + $miscTotal, 2);

        $desc = sprintf('%s | %s', $client, $carrier);

        $hasExpectDepositDate = Schema::hasColumn('bill_settlements', 'expectdepositdate');
        $hasExpectDepositAmount = Schema::hasColumn('bill_settlements', 'expectdepositamount');

        return DB::transaction(function () use (
            $desc,
            $start,
            $end,
            $gross,
            $factorCost,
            $factorPercent,
            $net,
            $miscTotal,
            $loads,
            $clientTableId,
            $carrierTableId,
            $depositDate,
            $hasExpectDepositDate,
            $hasExpectDepositAmount
        ) {
            $depositPatch = [];
            if ($hasExpectDepositDate) $depositPatch['expectdepositdate'] = $depositDate;
            if ($hasExpectDepositAmount) $depositPatch['expectdepositamount'] = $net;

            $insert = [
                'desc' => $desc,
                'startdate' => $start,
                'enddate' => $end,
                'clientid' => $clientTableId,
                'carrierid' => $carrierTableId,
                'userid' => null,
                'grossamount' => $gross,
                'factorcostamount' => $factorCost,
                'factorpercent' => $factorPercent,
                'chargebackamount' => $miscTotal,
                'netamount' => $net,
            ];

            $insert = array_merge($insert, $depositPatch);

            // ✅ ALWAYS NEW ROW
            $id = (int)DB::table('bill_settlements')->insertGetId($insert);

            // Snapshot loads
            if ($loads->count() > 0) {
                $rows = [];
                foreach ($loads as $l) {
                    $rows[] = ['id_settlement' => $id, 'id_load' => (int)$l->id_load];
                }
                DB::table('bill_settlementloads')->insert($rows);
            }

            // Snapshot chargebacks links
            $this->syncSettlementChargebacks($id, $carrierTableId, $start, $end);

            return $id;
        });
    }

    private function syncSettlementChargebacks(int $settlementId, ?int $carrierId, string $start, string $end): void
    {
        if (!$carrierId) return;
        if (!Schema::hasTable('bill_settlementchargebacks')) return;
        if (!Schema::hasTable('bill_chargebacks')) return;

        $cbIdCol = null;
        foreach (['idbill_chargebacks', 'id_bill_chargebacks', 'id', 'chargeback_id'] as $c) {
            if (Schema::hasColumn('bill_chargebacks', $c)) { $cbIdCol = $c; break; }
        }
        if (!$cbIdCol) return;

        if (!Schema::hasColumn('bill_settlementchargebacks', 'id_settlement')) return;

        // Always replace links for THIS new settlement
        DB::table('bill_settlementchargebacks')
            ->where('id_settlement', $settlementId)
            ->delete();

        $ids = DB::table('bill_chargebacks')
            ->where('carrier_id', $carrierId)
            ->whereBetween('chargebackdate', [$start, $end])
            ->pluck($cbIdCol)
            ->values();

        if ($ids->count() === 0) return;

        $rows = [];
        foreach ($ids as $id) {
            $rows[] = ['id_settlement' => $settlementId, 'id_chargeback' => (int)$id];
        }

        DB::table('bill_settlementchargebacks')->insert($rows);
    }

    private function calcMiscTotals(?int $carrierTableId, string $start, string $end): array
    {
        if (!$carrierTableId || !Schema::hasTable('bill_chargebacks')) {
            return ['misc_charge_total' => 0.0, 'misc_credit_total' => 0.0, 'misc_total' => 0.0];
        }

        $rows = DB::table('bill_chargebacks')
            ->select(['debit', 'credit'])
            ->where('carrier_id', $carrierTableId)
            ->whereBetween('chargebackdate', [$start, $end])
            ->get();

        $charge = 0.0;
        $credit = 0.0;

        foreach ($rows as $r) {
            $charge += (float)($r->debit ?? 0);
            $credit += (float)($r->credit ?? 0);
        }

        $charge = round($charge, 2);
        $credit = round($credit, 2);

        return [
            'misc_charge_total' => $charge,
            'misc_credit_total' => $credit,
            'misc_total' => round($credit - $charge, 2),
        ];
    }

    public function view(int $id): ?array
    {
        $s = DB::table('bill_settlements as s')
            ->leftJoin('clients as cl', 'cl.client_id', '=', 's.clientid')
            ->leftJoin('carrier as ca', 'ca.id_carrier', '=', 's.carrierid')
            ->where('s.id_bill_settlements', $id)
            ->select([
                's.*',
                DB::raw('COALESCE(cl.client_name, "") as _client_name'),
                DB::raw('COALESCE(ca.carrier_name, "") as _carrier_name'),
            ])
            ->first();

        if (!$s) return null;

        $client = trim((string)($s->_client_name ?? ''));
        $carrier = trim((string)($s->_carrier_name ?? ''));

        if (($client === '' || $carrier === '') && !empty($s->desc) && str_contains($s->desc, '|')) {
            [$c2, $k2] = array_map('trim', explode('|', $s->desc, 2));
            if ($client === '') $client = $c2;
            if ($carrier === '') $carrier = $k2;
        }

        $loadIds = DB::table('bill_settlementloads')
            ->where('id_settlement', $id)
            ->pluck('id_load')
            ->values();

        $driverRows = [];
        if ($loadIds->count() > 0) {
            $driverExpr = "TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')))";

            $rows = DB::table('fatloads')
                ->select([
                    DB::raw("$driverExpr AS driver"),
                    DB::raw("COUNT(*) AS loads"),
                    DB::raw("COALESCE(SUM(tons),0) AS tons"),
                    DB::raw("COALESCE(SUM(miles),0) AS miles"),
                    DB::raw("COALESCE(SUM(carrier_pay),0) AS revenue"),
                ])
                ->whereIn('id_load', $loadIds->all())
                ->groupBy(DB::raw($driverExpr))
                ->orderByRaw("COALESCE(SUM(carrier_pay),0) DESC")
                ->orderByRaw("CASE WHEN $driverExpr = '' THEN 1 ELSE 0 END, $driverExpr ASC")
                ->get();

            foreach ($rows as $r) {
                $name = trim((string)$r->driver);
                $driverRows[] = [
                    'driver' => $name !== '' ? $name : 'Unknown',
                    'loads' => (int)$r->loads,
                    'tons' => round((float)$r->tons, 2),
                    'miles' => (int)$r->miles,
                    'revenue' => round((float)$r->revenue, 2),
                ];
            }
        }

        $loadRows = [];
        if ($loadIds->count() > 0) {
            $loads = DB::table('fatloads')
                ->select([
                    'id_load',
                    'load_number',
                    'ticket_number',
                    'first_name',
                    'last_name',
                    'tons',
                    'miles',
                    'carrier_pay',
                    'load_date as load_date_for_ui',
                    DB::raw("STR_TO_DATE(load_date, '%m-%d-%Y') as load_date_sort"),
                ])
                ->whereIn('id_load', $loadIds->all())
                ->orderBy('load_date_sort')
                ->get();

            foreach ($loads as $l) {
                $loadRows[] = [
                    'id_load' => (int)$l->id_load,
                    'date' => $l->load_date_for_ui,
                    'load_number' => $l->load_number,
                    'ticket_number' => $l->ticket_number,
                    'driver' => trim(($l->first_name ?? '') . ' ' . ($l->last_name ?? '')) ?: 'Unknown',
                    'tons' => round((float)($l->tons ?? 0), 2),
                    'miles' => (int)($l->miles ?? 0),
                    'carrier_pay' => round((float)($l->carrier_pay ?? 0), 2),
                ];
            }
        }

        // Misc rows
        $miscRows = [];
        $miscChargeTotal = 0.0;
        $miscCreditTotal = 0.0;

        $carrierTableId = !empty($s->carrierid) ? (int)$s->carrierid : $this->carrierIdByName($carrier);

        if ($carrierTableId && Schema::hasTable('bill_chargebacks')) {
            $cb = DB::table('bill_chargebacks as cb');

            $fkCol = null;
            foreach (['idbill_chargebacksources', 'id_chargebacksource', 'chargebacksource', 'chargebacksource_id', 'chargebacksourceid'] as $c) {
                if (Schema::hasColumn('bill_chargebacks', $c)) { $fkCol = $c; break; }
            }

            if ($fkCol && Schema::hasTable('bill_chargebacksources')) {
                $cb->leftJoin('bill_chargebacksources as src', "cb.$fkCol", '=', 'src.idbill_chargebacksources');
            }

            $select = [
                'cb.idbill_chargebacks',
                'cb.chargebackdate',
                'cb.description',
                'cb.debit',
                'cb.credit',
                'cb.carrier_id',
                DB::raw(($fkCol ? "src.sourcedescript" : "NULL") . " as charge_source_desc"),
            ];

            $cbs = $cb->select($select)
                ->where('cb.carrier_id', $carrierTableId)
                ->whereBetween('cb.chargebackdate', [$s->startdate, $s->enddate])
                ->orderBy('cb.chargebackdate')
                ->orderBy('cb.idbill_chargebacks')
                ->get();

            foreach ($cbs as $c) {
                $charge = round((float)($c->debit ?? 0), 2);
                $credit = round((float)($c->credit ?? 0), 2);

                $miscRows[] = [
                    'id' => (int)$c->idbill_chargebacks,
                    'date' => $c->chargebackdate,
                    'description' => $c->description,
                    'charge_source_desc' => $c->charge_source_desc ?? null,
                    'charge' => $charge,
                    'credit' => $credit,
                ];

                $miscChargeTotal += $charge;
                $miscCreditTotal += $credit;
            }
        }

        $miscChargeTotal = round($miscChargeTotal, 2);
        $miscCreditTotal = round($miscCreditTotal, 2);
        $miscTotal = round($miscCreditTotal - $miscChargeTotal, 2);

        $gross = round((float)($s->grossamount ?? 0), 2);
        $factorCost = round((float)($s->factorcostamount ?? 0), 4);
        $factorPercent = (float)($s->factorpercent ?? 0);

        $net = round($gross - $factorCost + $miscTotal, 2);

        $depositDateOut = Schema::hasColumn('bill_settlements', 'expectdepositdate') ? ($s->expectdepositdate ?? null) : null;
        $depositAmount = Schema::hasColumn('bill_settlements', 'expectdepositamount') ? (float)($s->expectdepositamount ?? 0) : null;

        return [
            'id' => (int)$s->id_bill_settlements,
            'client_name' => $client,
            'carrier_name' => $carrier,
            'startdate' => $s->startdate,
            'enddate' => $s->enddate,

            'clientid' => $s->clientid ?? null,
            'carrierid' => $s->carrierid ?? null,

            'grossamount' => $gross,
            'factorpercent' => $factorPercent,
            'factorcostamount' => $factorCost,

            'chargebackamount' => round((float)($s->chargebackamount ?? 0), 2),

            'netamount' => $net,

            'deposit_date' => $depositDateOut,
            'paiddate' => $depositDateOut,
            'paidamount' => $depositAmount,

            'driver_rows' => $driverRows,
            'load_rows' => $loadRows,

            'misc_rows' => $miscRows,
            'misc_charge_total' => $miscChargeTotal,
            'misc_credit_total' => $miscCreditTotal,
            'misc_total' => $miscTotal,
        ];
    }

    private function carrierIdByName(?string $carrierName): ?int
    {
        if (!$carrierName) return null;
        if (!Schema::hasTable('carrier')) return null;

        $nameCol = Schema::hasColumn('carrier', 'companyname') ? 'companyname'
            : (Schema::hasColumn('carrier', 'carrier_name') ? 'carrier_name'
                : (Schema::hasColumn('carrier', 'name') ? 'name' : null));

        $idCol = Schema::hasColumn('carrier', 'idcarrier') ? 'idcarrier'
            : (Schema::hasColumn('carrier', 'id_carrier') ? 'id_carrier'
                : (Schema::hasColumn('carrier', 'id') ? 'id' : null));

        if (!$nameCol || !$idCol) return null;

        $row = DB::table('carrier')
            ->whereRaw("TRIM($nameCol) = TRIM(?)", [$carrierName])
            ->select([$idCol])
            ->first();

        return $row ? (int)$row->{$idCol} : null;
    }

    private function clientIdByName(?string $clientName): ?int
    {
        if (!$clientName) return null;

        if (Schema::hasTable('clients')) {
            $nameCol = Schema::hasColumn('clients', 'companyname') ? 'companyname'
                : (Schema::hasColumn('clients', 'client_name') ? 'client_name'
                    : (Schema::hasColumn('clients', 'name') ? 'name' : null));

            $idCol = Schema::hasColumn('clients', 'client_id') ? 'client_id'
                : (Schema::hasColumn('clients', 'id_client') ? 'id_client'
                    : (Schema::hasColumn('clients', 'id') ? 'id' : null));

            if ($nameCol && $idCol) {
                $row = DB::table('clients')
                    ->whereRaw("TRIM($nameCol) = TRIM(?)", [$clientName])
                    ->select([$idCol])
                    ->first();

                if ($row) return (int)$row->{$idCol};
            }
        }

        if (!Schema::hasTable('client')) return null;

        $nameCol = Schema::hasColumn('client', 'companyname') ? 'companyname'
            : (Schema::hasColumn('client', 'client_name') ? 'client_name'
                : (Schema::hasColumn('client', 'name') ? 'name' : null));

        $idCol = Schema::hasColumn('client', 'idclient') ? 'idclient'
            : (Schema::hasColumn('client', 'id_client') ? 'id_client'
                : (Schema::hasColumn('client', 'id') ? 'id' : null));

        if (!$nameCol || !$idCol) return null;

        $row = DB::table('client')
            ->whereRaw("TRIM($nameCol) = TRIM(?)", [$clientName])
            ->select([$idCol])
            ->first();

        return $row ? (int)$row->{$idCol} : null;
    }
}
