<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InboundLoadMatchingService
{
    /**
     * Queue = read loadimports (default connection)
     * "processed" = found in production load_detail via (input_method='IMPORT', input_id=import_id)
     *
     * IMPORTANT: No new columns in loadimports.
     */
    public function buildQueue(int $limit, string $only, string $q, string $matchFilter): array
    {
        $select = ['id', 'jobname', 'payload_json', 'payload_original', 'created_at', 'updated_at'];

        foreach (['carrier', 'truck', 'terminal', 'state', 'delivery_time', 'load_number', 'ticket_number'] as $col) {
            if ($this->columnExists('loadimports', $col)) $select[] = $col;
        }

        $imports = DB::table('loadimports')
            ->select(array_values(array_unique($select)))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $out = [];
        foreach ($imports as $r) {
            $parsed = $this->parseImportRow($r);

            // processed fingerprint lives in production load_detail
            $processedDetail = $this->prod()
                ->table('load_detail')
                ->select(['id_load_detail', 'id_load'])
                ->where('input_method', 'IMPORT')
                ->where('input_id', (int)$r->id)
                ->first();

            if ($only === 'unprocessed' && $processedDetail) {
                continue;
            }

            // quick search
            if ($q !== '') {
                $hay = strtolower(
                    ($parsed['driver_name'] ?? '') . ' ' .
                    ($parsed['truck_number'] ?? '') . ' ' .
                    ($parsed['jobname'] ?? '') . ' ' .
                    ($parsed['terminal'] ?? '') . ' ' .
                    ($parsed['load_number'] ?? '') . ' ' .
                    ($parsed['ticket_number'] ?? '') . ' ' .
                    ($parsed['raw_carrier'] ?? '') . ' ' .
                    ($parsed['raw_truck'] ?? '') . ' ' .
                    ($parsed['raw_original'] ?? '')
                );
                if (strpos($hay, strtolower($q)) === false) continue;
            }

            // Step 1: driver match (production tables)
            $driverMatch = $this->matchDriver($parsed['driver_name'], $parsed['truck_number']);
            $confidence = $this->computeConfidence($driverMatch);

            if ($matchFilter !== '' && $confidence !== $matchFilter) continue;

            // Step 1b: terminal -> pull_point(s).pp_job (production tables)
            $pullPointMatch = $this->matchPullPointByTerminal($parsed['terminal']);

            // Step 1c: jobname -> pad_location.pl_job (production tables)
            $padLocationMatch = $this->matchPadLocationByJobname($parsed['jobname']);

            // Journey
            $journey = $this->buildJourney($pullPointMatch, $padLocationMatch);

            $out[] = [
                'import_id' => (int)$r->id,
                'created_at' => $r->created_at ?? null,
                'updated_at' => $r->updated_at ?? null,

                'driver_name' => $parsed['driver_name'],
                'truck_number' => $parsed['truck_number'],
                'jobname' => $parsed['jobname'],
                'terminal' => $parsed['terminal'],
                'load_number' => $parsed['load_number'],
                'ticket_number' => $parsed['ticket_number'],
                'state' => $parsed['state'],
                'delivery_time' => $parsed['delivery_time'],

                // evidence/debug
                'raw_carrier' => $parsed['raw_carrier'],
                'raw_truck' => $parsed['raw_truck'],
                'raw_original' => $parsed['raw_original'],

                'is_processed' => $processedDetail ? true : false,
                'processed_load_id' => $processedDetail?->id_load ? (int)$processedDetail->id_load : null,
                'processed_load_detail_id' => $processedDetail?->id_load_detail ? (int)$processedDetail->id_load_detail : null,

                'match' => [
                    'confidence' => $confidence,
                    'driver' => $driverMatch,
                    'pull_point' => $pullPointMatch,
                    'pad_location' => $padLocationMatch,
                    'journey' => $journey,
                ],
            ];
        }

        return $out;
    }

    /**
     * Phase 2: process one import into production `load` + `load_detail`.
     * Duplicate prevention via load_detail(input_method='IMPORT', input_id=import_id).
     */
    public function processImport(int $importId): array
    {
        $row = DB::table('loadimports')->where('id', $importId)->first();
        if (!$row) {
            return ['ok' => false, 'error' => "Import id={$importId} not found."];
        }

        // prevent duplicates
        $existing = $this->prod()
            ->table('load_detail')
            ->select(['id_load_detail', 'id_load'])
            ->where('input_method', 'IMPORT')
            ->where('input_id', $importId)
            ->first();

        if ($existing) {
            return [
                'ok' => true,
                'already_processed' => true,
                'id_load' => (int)$existing->id_load,
                'id_load_detail' => (int)$existing->id_load_detail,
            ];
        }

        $parsed = $this->parseImportRow($row);

        // driver must resolve
        $driverMatch = $this->matchDriver($parsed['driver_name'], $parsed['truck_number']);
        $resolvedDriver = $driverMatch['resolved'] ?? null;
        if (!$resolvedDriver) {
            return ['ok' => false, 'error' => 'No driver resolved. Cannot process.'];
        }

        // journey must be READY
        $pullPointMatch = $this->matchPullPointByTerminal($parsed['terminal']);
        $padLocationMatch = $this->matchPadLocationByJobname($parsed['jobname']);
        $journey = $this->buildJourney($pullPointMatch, $padLocationMatch);

        if (($journey['status'] ?? 'NONE') !== 'READY') {
            return ['ok' => false, 'error' => 'Journey is not READY. Cannot process.'];
        }

        $stateRaw = strtoupper(trim((string)($parsed['state'] ?? '')));
        $state = $this->normalizeState($stateRaw);

        $deliveryTime = $parsed['delivery_time'] ?? null;

        // weights -> net_lbs (load_detail)
        $weights = $this->extractWeightsFromPayload($row->payload_json ?? null);
        $netLbs = $weights['net_lbs'];

        $idContact = (int)$resolvedDriver['id_contact'];
        $idVehicle = $resolvedDriver['id_vehicle'] ? (int)$resolvedDriver['id_vehicle'] : null;

        $joinId = $journey['join_id'] ? (int)$journey['join_id'] : null;

        // load_date (MM-DD-YYYY) best-effort
        $loadDate = $this->guessLoadDate($deliveryTime, $row->created_at ?? null);

        // ---------- State-specific validations ----------
        // IN_TRANSIT: must have weight
        if ($state === 'IN_TRANSIT' && $netLbs === null) {
            return ['ok' => false, 'error' => 'IN_TRANSIT but no weight found in payload (expected total_weight / box_numbers / weight).'];
        }

        // DELIVERED: must have parseable delivery_time
        $deliveryMysql = null;
        if ($state === 'DELIVERED') {
            if (!$deliveryTime) {
                return ['ok' => false, 'error' => 'DELIVERED but delivery_time is missing in import.'];
            }
            $deliveryMysql = $this->toMysqlDatetime($deliveryTime);
            if (!$deliveryMysql) {
                return [
                    'ok' => false,
                    'error' => "DELIVERED but delivery_time is not parseable: '{$deliveryTime}'. Expected e.g. 02/12/2026 08:23 PM",
                ];
            }
        }

        return $this->prod()->transaction(function () use (
            $importId, $idContact, $idVehicle, $joinId, $state, $deliveryMysql, $loadDate, $netLbs, $parsed
        ) {
            // 1) create placeholder in production `load` (singular)
            $loadInsert = [
                'id_join' => $joinId,
                'id_contact' => $idContact,
                'id_vehicle' => $idVehicle,
                'is_deleted' => 0,
                'load_date' => $loadDate,   // varchar(10) in your DB
                'is_finished' => null,
            ];

            // DELIVERED -> set delivery_time + is_finished
            if ($state === 'DELIVERED' && $deliveryMysql) {
                $loadInsert['delivery_time'] = $deliveryMysql; // YYYY-MM-DD HH:MM:SS
                $loadInsert['is_finished'] = 1;
            }

            $idLoad = (int)$this->prod()->table('load')->insertGetId($loadInsert);

            // 2) create placeholder in production `load_detail` (fingerprint)
            $detailInsert = [
                'id_load' => $idLoad,
                'input_method' => 'IMPORT',
                'input_id' => $importId,
                'load_number' => $parsed['load_number'] ?? null,
                'ticket_number' => $parsed['ticket_number'] ?? null,
                'truck_number' => $parsed['truck_number'] ?? null,
            ];

            /**
             * Transit & Delivered logic:
             * - IN_TRANSIT: must store net_lbs (validated earlier)
             * - DELIVERED: store net_lbs if present (prevents 0 rows; safe)
             */
            if ($netLbs !== null) {
                $detailInsert['net_lbs'] = (int)round($netLbs);
            }

            $idLoadDetail = (int)$this->prod()->table('load_detail')->insertGetId($detailInsert);

            return [
                'ok' => true,
                'already_processed' => false,
                'id_load' => $idLoad,
                'id_load_detail' => $idLoadDetail,
            ];
        });
    }

    // ------------------ parsing ------------------

    private function parseImportRow(object $r): array
    {
        $data = [];
        $payloadJson = $r->payload_json ?? null;
        if ($payloadJson) {
            $decoded = json_decode($payloadJson, true);
            if (is_array($decoded)) $data = $decoded;
        }

        $jobname = $this->strOrNull($r->jobname ?? null) ?? $this->strOrNull($data['jobname'] ?? null);

        $terminal =
            $this->strOrNull($r->terminal ?? null)
            ?? $this->strOrNull($data['terminal'] ?? null);

        $state =
            $this->strOrNull($r->state ?? null)
            ?? $this->strOrNull($data['status'] ?? $data['state'] ?? null);

        $deliveryTime =
            $this->strOrNull($r->delivery_time ?? null)
            ?? $this->strOrNull($data['delivery_time'] ?? null)
            ?? $this->strOrNull($data['datetime_delivered'] ?? null);

        $loadNumber =
            $this->strOrNull($r->load_number ?? null)
            ?? $this->strOrNull($data['loadnumber'] ?? $data['load_number'] ?? null);

        $ticketNumber =
            $this->strOrNull($r->ticket_number ?? null)
            ?? $this->strOrNull($data['ticket_no'] ?? $data['ticket_number'] ?? null);

        $carrierStr =
            $this->strOrNull($r->carrier ?? null)
            ?? $this->strOrNull($data['carrier'] ?? null);

        $truckStr =
            $this->strOrNull($r->truck ?? null)
            ?? $this->strOrNull($data['truck_trailer'] ?? null)
            ?? $this->strOrNull($data['truck_number'] ?? null)
            ?? $this->strOrNull($data['truck'] ?? null);

        $originalStr = $this->strOrNull($r->payload_original ?? null);

        if (!$carrierStr && $originalStr) $carrierStr = $originalStr;
        if (!$truckStr && $originalStr) $truckStr = $originalStr;

        $driverName = $this->extractDriverName($carrierStr);
        $truckNumber = $this->extractTruckNumber($truckStr);

        return [
            'driver_name' => $driverName,
            'truck_number' => $truckNumber,
            'jobname' => $this->strOrNull($jobname),
            'terminal' => $this->strOrNull($terminal),
            'load_number' => $loadNumber,
            'ticket_number' => $ticketNumber,
            'state' => $state,
            'delivery_time' => $deliveryTime,

            'raw_carrier' => $carrierStr,
            'raw_truck' => $truckStr,
            'raw_original' => $originalStr,
        ];
    }

    private function extractWeightsFromPayload(?string $payloadJson): array
    {
        $net = null;
        $box1 = null;
        $box2 = null;

        if ($payloadJson) {
            $d = json_decode($payloadJson, true);

            if (is_array($d)) {
                // Primary: total_weight like "43,960"
                $net = $this->toFloatOrNull($d['total_weight'] ?? null);

                // Next: other direct totals
                if ($net === null) {
                    $net = $this->toFloatOrNull(
                        $d['total_lbs'] ?? $d['net_lbs'] ?? $d['netlbs'] ?? $d['net'] ?? null
                    );
                }

                // Fallback: parse "box_numbers" (ex: "11842,2008") and sum
                if ($net === null && isset($d['box_numbers'])) {
                    $parts = array_map('trim', explode(',', (string)$d['box_numbers']));
                    $nums = array_map(fn($x) => $this->toFloatOrNull($x), $parts);
                    $nums = array_values(array_filter($nums, fn($x) => $x !== null));
                    if (count($nums) >= 1) {
                        $box1 = $nums[0] ?? null;
                        $box2 = $nums[1] ?? null;
                        $sum = 0.0;
                        foreach ($nums as $n) $sum += $n;
                        $net = $sum > 0 ? $sum : null;
                    }
                }

                // Fallback: parse "weight" string if needed (e.g. "11842 (21,980)\n2008 (21,980)")
                if ($net === null && isset($d['weight'])) {
                    preg_match_all('/(^|\n)\s*([0-9,]+)\s*(?=\(|$)/m', (string)$d['weight'], $m);
                    if (!empty($m[2])) {
                        $nums = [];
                        foreach ($m[2] as $raw) {
                            $v = $this->toFloatOrNull($raw);
                            if ($v !== null) $nums[] = $v;
                        }
                        if (count($nums) >= 1) {
                            $box1 = $nums[0] ?? null;
                            $box2 = $nums[1] ?? null;
                            $sum = array_sum($nums);
                            $net = $sum > 0 ? $sum : null;
                        }
                    }
                }
            }
        }

        return [
            'box1' => $box1,
            'box2' => $box2,
            'net_lbs' => ($net !== null && $net > 0) ? $net : null,
        ];
    }

    private function toFloatOrNull($v): ?float
    {
        if ($v === null) return null;
        if (is_numeric($v)) return (float)$v;
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = preg_replace('/[^0-9.\-]/', '', $s);
        return is_numeric($s) ? (float)$s : null;
    }

    private function guessLoadDate(?string $deliveryTime, $createdAt): ?string
    {
        // returns MM-DD-YYYY as varchar(10)
        $dt = $deliveryTime ?: ($createdAt ? (string)$createdAt : null);
        if (!$dt) return null;

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $dt)) return $dt;

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dt, $m)) {
            return "{$m[2]}-{$m[3]}-{$m[1]}";
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $dt, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    private function toMysqlDatetime(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $s)) return $s;
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $s)) return $s . ':00';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s . ' 00:00:00';

        $formats = [
            'm/d/Y h:i A',
            'm/d/Y h:i:s A',
            'm-d-Y h:i A',
            'm-d-Y h:i:s A',
            'n/j/Y g:i A',
            'n/j/Y g:i:s A',
        ];

        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $s);
                return $dt->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                // continue
            }
        }

        // Only fallback if it looks ISO-ish
        if (str_contains($s, 'T') || preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            try {
                return Carbon::parse($s)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function normalizeState(string $stateRaw): string
    {
        $s = strtoupper(trim($stateRaw));
        $s = str_replace(' ', '_', $s);
        if ($s === 'INTRANSIT') $s = 'IN_TRANSIT';
        if ($s === 'IN-TRANSIT') $s = 'IN_TRANSIT';
        if ($s === 'DELIVERED') return 'DELIVERED';
        if ($s === 'IN_TRANSIT') return 'IN_TRANSIT';
        return $s; // keep original-like for display/debug
    }

    // ------------------ Driver parsing ------------------

    private function extractDriverName(?string $carrier): ?string
    {
        if (!$carrier) return null;

        $lines = preg_split("/\r\n|\n|\r/", $carrier);
        if (is_array($lines) && count($lines) >= 2) {
            $maybe = $this->strOrNull($lines[1]);
            if ($maybe) return $maybe;
        }

        if (preg_match('/\b([A-Z][a-z]+)\s+([A-Z][a-z]+)\b/', $carrier, $m)) {
            return $this->strOrNull($m[1] . ' ' . $m[2]);
        }

        return $this->strOrNull($carrier);
    }

    private function extractTruckNumber(?string $text): ?string
    {
        if (!$text) return null;

        if (preg_match('/Truck\s*#?:?\s*([A-Za-z0-9]+)/i', $text, $m)) {
            return $this->strOrNull($m[1]);
        }

        $t = trim($text);
        if ($t !== '' && preg_match('/^[A-Za-z0-9]+$/', $t)) {
            return $this->strOrNull($t);
        }

        return null;
    }

    // ------------------ Matching (PRODUCTION TABLES) ------------------

    private function matchDriver(?string $driverName, ?string $truckNumber): array
    {
        $nameDriver = null;
        $truckDriver = null;
        $notes = [];

        // A) name -> contact -> driver
        if ($driverName) {
            [$contact, $method, $note] = $this->findContactForDriverName($driverName);
            if ($note) $notes[] = $note;

            if ($contact) {
                $drv = $this->prod()->table('driver')
                    ->select(['id_driver', 'id_contact', 'id_vehicle'])
                    ->where('id_contact', $contact->id_contact)
                    ->first();

                if ($drv) {
                    $nameDriver = [
                        'method' => $method,
                        'id_driver' => (int)$drv->id_driver,
                        'id_contact' => (int)$drv->id_contact,
                        'id_vehicle' => $drv->id_vehicle ? (int)$drv->id_vehicle : null,
                    ];
                } else {
                    $notes[] = "Contact matched by name but no driver row found for id_contact={$contact->id_contact}.";
                }
            }
        }

        // B) truck -> vehicle -> driver
        if ($truckNumber) {
            $truckNorm = strtolower($this->normTruck($truckNumber));

            $veh = $this->prod()->table('vehicle')
                ->select(['id_vehicle', 'vehicle_number', 'vehicle_name'])
                ->where(function ($q) use ($truckNorm) {
                    $q->whereRaw("LOWER(TRIM(vehicle_number)) = ?", [$truckNorm])
                        ->orWhereRaw("LOWER(TRIM(vehicle_name)) = ?", [$truckNorm]);
                })
                ->first();

            if ($veh) {
                $drv = $this->prod()->table('driver')
                    ->select(['id_driver', 'id_contact', 'id_vehicle'])
                    ->where('id_vehicle', $veh->id_vehicle)
                    ->first();

                if ($drv) {
                    $truckDriver = [
                        'method' => 'TRUCK',
                        'id_driver' => (int)$drv->id_driver,
                        'id_contact' => (int)$drv->id_contact,
                        'id_vehicle' => (int)$drv->id_vehicle,
                    ];
                }
            }
        }

        $resolved = null;
        $status = 'NONE';

        if ($nameDriver && $truckDriver) {
            if ($nameDriver['id_driver'] === $truckDriver['id_driver']) {
                $status = 'CONFIRMED';
                $resolved = $nameDriver;
                $resolved['method'] = $nameDriver['method'] . '+TRUCK';
            } else {
                $status = 'CONFLICT';
                $resolved = $nameDriver; // default to name
                $notes[] = "Conflict: name matched driver {$nameDriver['id_driver']} but truck matched driver {$truckDriver['id_driver']}.";
            }
        } elseif ($nameDriver) {
            $status = 'NAME_ONLY';
            $resolved = $nameDriver;
        } elseif ($truckDriver) {
            $status = 'TRUCK_ONLY';
            $resolved = $truckDriver;
        }

        return [
            'status' => $status,
            'resolved' => $resolved,
            'by_name' => $nameDriver,
            'by_truck' => $truckDriver,
            'notes' => implode(' ', array_filter($notes)),
        ];
    }

    private function findContactForDriverName(string $driverName): array
    {
        $normFull = $this->norm($driverName);

        // EXACT
        $contact = $this->prod()->table('contact')
            ->select(['id_contact', 'first_name', 'last_name'])
            ->whereRaw("LOWER(TRIM(CONCAT(TRIM(first_name), ' ', TRIM(last_name)))) = ?", [$normFull])
            ->first();

        if ($contact) return [$contact, 'NAME_EXACT', ''];

        // LIKE (unique only)
        $likeCands = $this->prod()->table('contact')
            ->select(['id_contact', 'first_name', 'last_name'])
            ->whereRaw("LOWER(TRIM(CONCAT(TRIM(first_name), ' ', TRIM(last_name)))) LIKE ?", ['%' . $normFull . '%'])
            ->limit(20)
            ->get();

        if (count($likeCands) === 1) return [$likeCands[0], 'NAME_LIKE_UNIQUE', ''];

        // FUZZY
        [$first, $last] = $this->splitName($driverName);
        $firstN = $first ? $this->norm($first) : '';
        $lastN  = $last ? $this->norm($last) : '';

        if ($firstN === '' && $lastN === '') {
            return [null, 'NAME_NONE', 'Driver name is empty after normalization.'];
        }

        $qb = $this->prod()->table('contact')->select(['id_contact', 'first_name', 'last_name']);
        if ($lastN !== '') $qb->whereRaw("SOUNDEX(TRIM(last_name)) = SOUNDEX(?)", [$lastN]);
        else $qb->whereRaw("SOUNDEX(TRIM(first_name)) = SOUNDEX(?)", [$firstN]);

        $pool = $qb->limit(80)->get();

        if (count($pool) === 0 && $firstN !== '') {
            $pool = $this->prod()->table('contact')
                ->select(['id_contact', 'first_name', 'last_name'])
                ->whereRaw("LOWER(TRIM(first_name)) LIKE ?", [substr($firstN, 0, 4) . '%'])
                ->limit(80)
                ->get();
        }

        if (count($pool) === 0) {
            if (count($likeCands) > 1) return [null, 'NAME_NONE', 'Driver name ambiguous (multiple contacts match).'];
            return [null, 'NAME_NONE', 'No contact match found by exact/like/fuzzy.'];
        }

        $best = null;
        $bestScore = 9999;
        $secondScore = 9999;

        foreach ($pool as $c) {
            $full = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
            $score = $this->nameDistance($driverName, $full);

            if ($score < $bestScore) {
                $secondScore = $bestScore;
                $bestScore = $score;
                $best = $c;
            } elseif ($score < $secondScore) {
                $secondScore = $score;
            }
        }

        if ($best && $bestScore <= 2 && ($secondScore - $bestScore) >= 1) {
            return [$best, 'NAME_FUZZY', "Fuzzy name match used (distance={$bestScore})."];
        }

        if (count($likeCands) > 1) return [null, 'NAME_NONE', 'Driver name ambiguous (multiple contacts match).'];

        return [null, 'NAME_NONE', 'No contact match found by exact/like/fuzzy.'];
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') return [null, null];

        if (str_contains($name, ',')) {
            [$a, $b] = array_map('trim', explode(',', $name, 2));
            return [$b ?: null, $a ?: null];
        }

        $parts = preg_split('/\s+/u', $name);
        $parts = array_values(array_filter($parts, fn($p) => $p !== ''));

        if (count($parts) === 1) return [$parts[0], null];

        return [$parts[0], $parts[count($parts) - 1]];
    }

    private function nameDistance(string $a, string $b): int
    {
        $aN = $this->squashDoubles($this->norm($a));
        $bN = $this->squashDoubles($this->norm($b));
        return levenshtein($aN, $bN);
    }

    private function squashDoubles(string $s): string
    {
        return preg_replace('/(.)\1+/u', '$1', $s);
    }

    /**
     * Terminal -> pull_points/pull_point.pp_job (normalized exact, then LIKE)
     * Robin requirement: pp_job MUST match terminal string from JSON.
     */
    private function matchPullPointByTerminal(?string $terminal): array
    {
        $terminal = $this->strOrNull($terminal);
        if (!$terminal) {
            return ['status' => 'NONE', 'resolved' => null, 'candidates' => [], 'notes' => 'No terminal in import.'];
        }

        $table = $this->pickPullPointTable(); // pull_points or pull_point

        $t = strtolower(trim($terminal));
        $t = preg_replace('/\s+/u', ' ', $t);
        $t = preg_replace('/\s*-\s*/', '-', $t);

        // 1) normalized exact against pp_job
        $row = $this->prod()->table($table)
            ->select(['id_pull_point', 'pp_job'])
            ->where('is_deleted', 0)
            ->whereRaw("
                LOWER(
                    REPLACE(
                        REPLACE(TRIM(pp_job), ' - ', '-'),
                        ' -','-'
                    )
                ) = ?
            ", [$t])
            ->first();

        if ($row) {
            return [
                'status' => 'ONE',
                'resolved' => [
                    'id_pull_point' => (int)$row->id_pull_point,
                    'pp_job' => $row->pp_job,
                    'method' => 'NORMALIZED_EXACT',
                ],
                'candidates' => [],
                'notes' => '',
            ];
        }

        // 2) LIKE (contains)
        $cands = $this->prod()->table($table)
            ->select(['id_pull_point', 'pp_job'])
            ->where('is_deleted', 0)
            ->whereRaw('LOWER(TRIM(pp_job)) LIKE ?', ['%' . $t . '%'])
            ->limit(10)
            ->get();

        if (count($cands) === 1) {
            return [
                'status' => 'ONE',
                'resolved' => [
                    'id_pull_point' => (int)$cands[0]->id_pull_point,
                    'pp_job' => $cands[0]->pp_job,
                    'method' => 'LIKE_UNIQUE',
                ],
                'candidates' => [],
                'notes' => '',
            ];
        }

        if (count($cands) > 1) {
            return [
                'status' => 'MULTI',
                'resolved' => null,
                'candidates' => $cands->map(fn($x) => [
                    'id_pull_point' => (int)$x->id_pull_point,
                    'pp_job' => $x->pp_job,
                ])->all(),
                'notes' => 'Multiple pull points match.',
            ];
        }

        return ['status' => 'NONE', 'resolved' => null, 'candidates' => [], 'notes' => 'No pull point match found.'];
    }

    /**
     * Jobname -> pad_location.pl_job (LIKE both directions)
     */
    private function matchPadLocationByJobname(?string $jobname): array
    {
        $jobname = $this->strOrNull($jobname);
        if (!$jobname) {
            return ['status' => 'NONE', 'resolved' => null, 'candidates' => [], 'notes' => 'No jobname in import.'];
        }

        $norm = strtolower(trim($jobname));
        $norm = preg_replace('/\s+/u', ' ', $norm);
        $normLoose = preg_replace('/\s*\/\s*/u', '/', $norm);

        // EXACT
        $exact = $this->prod()->table('pad_location')
            ->select(['id_pad_location', 'pl_job'])
            ->where('is_deleted', 0)
            ->whereRaw("LOWER(TRIM(pl_job)) = ?", [$norm])
            ->first();

        if ($exact) {
            return [
                'status' => 'ONE',
                'resolved' => [
                    'id_pad_location' => (int)$exact->id_pad_location,
                    'pl_job' => $exact->pl_job,
                    'method' => 'EXACT',
                ],
                'candidates' => [],
                'notes' => '',
            ];
        }

        // LIKE (both directions)
        $cands = $this->prod()->table('pad_location')
            ->select(['id_pad_location', 'pl_job'])
            ->where('is_deleted', 0)
            ->where(function ($q) use ($norm, $normLoose) {
                $q->whereRaw("LOWER(TRIM(pl_job)) LIKE ?", ['%' . $norm . '%'])
                    ->orWhereRaw("LOWER(TRIM(pl_job)) LIKE ?", ['%' . $normLoose . '%'])
                    ->orWhereRaw("? LIKE CONCAT('%', LOWER(TRIM(pl_job)), '%')", [$norm])
                    ->orWhereRaw("? LIKE CONCAT('%', LOWER(TRIM(pl_job)), '%')", [$normLoose]);
            })
            ->limit(10)
            ->get();

        if (count($cands) === 1) {
            return [
                'status' => 'ONE',
                'resolved' => [
                    'id_pad_location' => (int)$cands[0]->id_pad_location,
                    'pl_job' => $cands[0]->pl_job,
                    'method' => 'LIKE_UNIQUE',
                ],
                'candidates' => [],
                'notes' => '',
            ];
        }

        if (count($cands) > 1) {
            return [
                'status' => 'MULTI',
                'resolved' => null,
                'candidates' => $cands->map(fn($x) => [
                    'id_pad_location' => (int)$x->id_pad_location,
                    'pl_job' => $x->pl_job,
                ])->all(),
                'notes' => 'Jobname ambiguous: multiple pad_location matches.',
            ];
        }

        return ['status' => 'NONE', 'resolved' => null, 'candidates' => [], 'notes' => 'No pad_location match found.'];
    }

    /**
     * If join table exists, attempt to resolve join.id_join using pp+pl.
     */
    private function buildJourney(array $pullPointMatch, array $padLocationMatch): array
    {
        $pp = $pullPointMatch['resolved'] ?? null;
        $pl = $padLocationMatch['resolved'] ?? null;

        if (!$pp || !$pl) {
            return [
                'status' => ($pp || $pl) ? 'PARTIAL' : 'NONE',
                'pull_point_id' => $pp ? (int)$pp['id_pull_point'] : null,
                'pad_location_id' => $pl ? (int)$pl['id_pad_location'] : null,
                'join_id' => null,
                'method' => ($pp || $pl) ? 'PARTIAL' : 'NONE',
            ];
        }

        $ppId = (int)$pp['id_pull_point'];
        $plId = (int)$pl['id_pad_location'];

        if ($this->columnExistsProd('join', 'id_pull_point') &&
            $this->columnExistsProd('join', 'id_pad_location') &&
            $this->columnExistsProd('join', 'id_join')) {

            $join = $this->prod()->table('join')
                ->select(['id_join'])
                ->where('is_deleted', 0)
                ->where('id_pull_point', $ppId)
                ->where('id_pad_location', $plId)
                ->first();

            if ($join) {
                return [
                    'status' => 'READY',
                    'pull_point_id' => $ppId,
                    'pad_location_id' => $plId,
                    'join_id' => (int)$join->id_join,
                    'method' => 'TERMINAL->PULL_POINT + JOBNAME->PAD_LOCATION + JOIN_LOOKUP',
                ];
            }

            return [
                'status' => 'MISSING_JOIN',
                'pull_point_id' => $ppId,
                'pad_location_id' => $plId,
                'join_id' => null,
                'method' => 'JOIN_NOT_FOUND',
            ];
        }

        return [
            'status' => 'READY',
            'pull_point_id' => $ppId,
            'pad_location_id' => $plId,
            'join_id' => null,
            'method' => 'TERMINAL->PULL_POINT + JOBNAME->PAD_LOCATION',
        ];
    }

    private function computeConfidence(array $driverMatch): string
    {
        return match ($driverMatch['status'] ?? 'NONE') {
            'CONFIRMED' => 'GREEN',
            'NAME_ONLY', 'TRUCK_ONLY', 'CONFLICT' => 'YELLOW',
            default => 'RED',
        };
    }

    // ------------------ utilities ------------------

    private function pickPullPointTable(): string
    {
        // support either schema name
        return $this->tableExistsProd('pull_points') ? 'pull_points' : 'pull_point';
    }

    private function tableExistsProd(string $table): bool
    {
        try {
            $rows = $this->prod()->select("SHOW TABLES LIKE ?", [$table]);
            return count($rows) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function norm(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    private function normTruck(string $s): string
    {
        $s = trim($s);
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', $s));
    }

    private function strOrNull($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $cols = DB::select("SHOW COLUMNS FROM `$table` LIKE ?", [$column]);
            return count($cols) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function columnExistsProd(string $table, string $column): bool
    {
        try {
            $cols = $this->prod()->select("SHOW COLUMNS FROM `$table` LIKE ?", [$column]);
            return count($cols) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function prod()
    {
        try {
            return DB::connection('productin');
        } catch (\Throwable $e) {
            return DB::connection();
        }
    }
}
