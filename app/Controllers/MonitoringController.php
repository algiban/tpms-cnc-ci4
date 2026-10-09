<?php

namespace App\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class MonitoringController extends BaseController
{
    private const DEVICE_OFFLINE_AFTER_SECONDS = 60;
    private const REFRESH_AFTER_MS = 2000;

    /**
     * Public monitoring page. Data is loaded from the read-only JSON endpoint.
     */
    public function index()
    {
        return view('monitoring/index', [
            'title' => 'Production Monitoring',
        ]);
    }

    /**
     * Read-only endpoint consumed by the monitoring page.
     *
     * IMPORTANT:
     * - Do not expose TPMS device tokens here.
     * - Do not use EspProductionService because it is an authenticated writer/API
     *   for the ESP device. Monitoring only reads database snapshots.
     */
    public function data()
    {
        $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');

        try {
            [$payload, $cacheStatus] = $this->monitoringPayload();

            $this->response
                ->setHeader('X-TPMS-Monitoring-Cache', $cacheStatus)
                ->setHeader(
                    'X-TPMS-Monitoring-Cache-TTL',
                    (string) max(0, (int) env('TPMS_MONITORING_CACHE_SECONDS', 1))
                );

            return $this->response->setJSON([
                'ok' => true,
                'data' => $payload,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Monitoring API: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Data monitoring tidak dapat dimuat.',
                ]);
        }
    }

    /**
     * Riwayat produksi terbaru untuk satu machine.
     * Endpoint ini dipisah dari polling utama supaya history tidak ikut ditarik
     * setiap 2 detik untuk seluruh machine.
     */
    public function history(int $machineId)
    {
        $this->response
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache');

        try {
            $db = db_connect();

            $machine = $db
                ->table('machines')
                ->select('id, code, name')
                ->where('id', $machineId)
                ->where('disposed_at', null)
                ->get()
                ->getRowArray();

            if (! $machine) {
                return $this->response
                    ->setStatusCode(404)
                    ->setJSON([
                        'ok' => false,
                        'message' => 'Machine tidak ditemukan.',
                    ]);
            }

            // History monitoring berorientasi pada shift detail, bukan header
            // productions. Satu production yang berjalan lintas shift akan
            // tampil sebagai row terpisah untuk setiap work_date + shift.
            $history = $db->query(
                "SELECT
                    d.id AS detail_id,
                    d.production_id,
                    d.work_date,
                    d.target_qty,
                    d.actual_qty,
                    d.good_qty,
                    d.reject_qty,
                    d.status AS detail_status,
                    d.started_at,
                    d.ended_at,

                    p.production_code,
                    p.mode,
                    p.process_no,
                    p.process_name_snapshot,
                    p.process_mode_snapshot,

                    part.part_number,
                    part.name AS part_name,

                    sh.id AS shift_id,
                    sh.code AS shift_code,
                    sh.name AS shift_name,
                    sh.start_time AS shift_start_time,
                    sh.end_time AS shift_end_time,

                    e.id AS operator_id,
                    e.nik AS operator_nik,
                    e.name AS operator_name

                 FROM production_shift_details d
                 JOIN productions p ON p.id = d.production_id
                 JOIN parts part ON part.id = p.part_id
                 LEFT JOIN shifts sh ON sh.id = d.shift_id
                 LEFT JOIN employees e ON e.id = d.operator_employee_id
                 WHERE p.machine_id = ?
                   AND COALESCE(p.mode, 'production') <> 'setting'
                 ORDER BY
                    d.work_date DESC,
                    COALESCE(d.started_at, d.created_at) DESC,
                    d.id DESC
                 LIMIT 15",
                [$machineId]
            )->getResultArray();

            $rows = array_map(static function (array $row): array {
                $target = (int) ($row['target_qty'] ?? 0);
                $gross = (int) ($row['actual_qty'] ?? 0);
                $good = (int) ($row['good_qty'] ?? 0);
                $progress = $target > 0
                    ? min(100, max(0, ($good / $target) * 100))
                    : 0;

                return [
                    'detail_id' => (int) $row['detail_id'],
                    'production_id' => (int) $row['production_id'],
                    'production_code' => $row['production_code'],
                    'work_date' => $row['work_date'],
                    'production_date' => $row['work_date'], // kompatibilitas UI lama
                    'shift_id' => $row['shift_id'] === null ? null : (int) $row['shift_id'],
                    'shift_code' => $row['shift_code'],
                    'shift_name' => $row['shift_name'] ?: '-',
                    'shift_start_time' => $row['shift_start_time'],
                    'shift_end_time' => $row['shift_end_time'],
                    'operator_id' => $row['operator_id'] === null ? null : (int) $row['operator_id'],
                    'operator_nik' => $row['operator_nik'],
                    'operator_name' => $row['operator_name'] ?: '-',
                    'part_number' => $row['part_number'],
                    'part_name' => $row['part_name'],
                    'process_no' => (int) ($row['process_no'] ?? 1),
                    'process_name' => $row['process_name_snapshot'] ?: 'Single Process',
                    'process_mode' => strtolower((string) ($row['process_mode_snapshot'] ?? 'auto')),
                    'target_qty' => $target,
                    'actual_qty' => $gross,
                    'gross_qty' => $gross,
                    'good_qty' => $good,
                    'reject_qty' => (int) ($row['reject_qty'] ?? 0),
                    'progress' => round($progress, 1),
                    'status' => $row['detail_status'],
                    'started_at' => $row['started_at'],
                    'completed_at' => $row['ended_at'],
                ];
            }, $history);

            return $this->response->setJSON([
                'ok' => true,
                'data' => [
                    'machine' => [
                        'id' => (int) $machine['id'],
                        'code' => $machine['code'],
                        'name' => $machine['name'],
                    ],
                    'history' => $rows,
                ],
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Monitoring history API: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Riwayat produksi tidak dapat dimuat.',
                ]);
        }
    }

    /**
     * Shared short-lived monitoring snapshot.
     *
     * A 1 second server-side cache is enough to collapse a burst of many
     * dashboard browsers into a single database snapshot while keeping the
     * UI effectively realtime (the browser refresh cadence is 2 seconds).
     *
     * The file lock prevents cache stampede across PHP-FPM workers: after a
     * miss only one worker rebuilds the snapshot, the others wait briefly and
     * then consume the value that worker stored.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function monitoringPayload(): array
    {
        $ttl = max(0, (int) env('TPMS_MONITORING_CACHE_SECONDS', 1));

        if ($ttl === 0) {
            return [$this->buildMonitoringPayload(), 'BYPASS'];
        }

        $cache = cache();
        $key = 'tpms_monitoring_snapshot_v6_shift_history';
        $cached = $cache->get($key);

        if (is_array($cached)) {
            return [$cached, 'HIT'];
        }

        $cacheDir = WRITEPATH . 'cache';
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $lockHandle = @fopen($cacheDir . DIRECTORY_SEPARATOR . 'tpms-monitoring-snapshot.lock', 'c+');

        // Cache still works if a filesystem lock cannot be created; only the
        // anti-stampede protection is skipped in that exceptional case.
        if ($lockHandle === false) {
            $payload = $this->buildMonitoringPayload();
            $cache->save($key, $payload, $ttl);

            return [$payload, 'MISS-NOLOCK'];
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                $payload = $this->buildMonitoringPayload();
                $cache->save($key, $payload, $ttl);

                return [$payload, 'MISS-NOLOCK'];
            }

            // Another PHP-FPM worker may have filled the cache while this
            // request was waiting for the regeneration lock.
            $cached = $cache->get($key);
            if (is_array($cached)) {
                return [$cached, 'HIT-AFTER-WAIT'];
            }

            $payload = $this->buildMonitoringPayload();
            $cache->save($key, $payload, $ttl);

            return [$payload, 'MISS'];
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * Build one authoritative monitoring payload from the database.
     *
     * This method deliberately does not know about the cache so it remains
     * easy to profile and can be used directly when caching is disabled.
     */
    private function buildMonitoringPayload(): array
    {
        $machines = $this->machineSnapshots();
        $currentShift = $this->currentShiftContext();
        $shiftProduction = $this->currentShiftProduction($currentShift);

        $summary = [
            'total' => count($machines),
            'running' => 0,
            'paused' => 0,
            'alarm' => 0,
            'idle' => 0,
            'offline' => 0,
            'setting' => 0,
        ];

        foreach ($machines as $machine) {
            $status = $machine['status_code'];

            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            } else {
                $summary['idle']++;
            }
        }

        // Field lama tetap dipertahankan untuk kompatibilitas view/consumer
        // lain, tetapi card monitoring utama memakai shift_production.
        $summary += \App\Services\MonitoringMetrics::runningProgress($machines);
        $summary['shift_production'] = $shiftProduction;

        return [
            'generated_at' => $this->now(),
            'refresh_after_ms' => max(500, (int) env('TPMS_MONITORING_REFRESH_MS', self::REFRESH_AFTER_MS)),
            'current_shift' => $currentShift,
            'summary' => $summary,
            'machines' => $machines,
        ];
    }

    /**
     * Shift aktif ditentukan dari jam server dan master shifts. Untuk shift
     * lintas tengah malam, work_date mengikuti tanggal mulai shift (misalnya
     * Shift 3 23:00-07:00 pada pukul 02:00 masih memakai work_date kemarin).
     */
    private function currentShiftContext(): ?array
    {
        $db = db_connect();
        $shifts = $db->table('shifts')
            ->select('id, code, name, start_time, end_time')
            ->orderBy('start_time')
            ->get()
            ->getResultArray();

        if (! $shifts) {
            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(config('App')->appTimezone));
        $time = $now->format('H:i:s');
        $matches = [];

        foreach ($shifts as $shift) {
            $start = (string) ($shift['start_time'] ?? '');
            $end = (string) ($shift['end_time'] ?? '');
            if ($start === '' || $end === '' || $start === $end) {
                continue;
            }

            $overnight = $start > $end;
            $active = (! $overnight && $time >= $start && $time < $end)
                || ($overnight && ($time >= $start || $time < $end));

            if (! $active) {
                continue;
            }

            $workDate = ($overnight && $time < $end)
                ? $now->modify('-1 day')->format('Y-m-d')
                : $now->format('Y-m-d');

            $matches[] = [
                'id' => (int) $shift['id'],
                'code' => $shift['code'],
                'name' => $shift['name'],
                'start_time' => $start,
                'end_time' => $end,
                'work_date' => $workDate,
            ];
        }

        // Monitoring harus tetap hidup walaupun master shift sedang belum valid.
        // Jika tidak ada tepat satu shift aktif, header memakai fallback UI lama.
        if (count($matches) !== 1) {
            log_message('warning', 'Monitoring current shift tidak unik. match_count={count}', [
                'count' => count($matches),
            ]);
            return null;
        }

        return $matches[0];
    }

    /**
     * Ring header Monitoring adalah aggregate production pada work_date dan
     * shift yang sedang aktif. Tidak membaca total header productions sehingga
     * pergantian shift langsung berpindah ke data shift baru.
     */
    private function currentShiftProduction(?array $shift): array
    {
        if ($shift === null) {
            return [
                'work_date' => null,
                'shift_id' => null,
                'shift_code' => null,
                'shift_name' => null,
                'gross_qty' => 0,
                'good_qty' => 0,
                'reject_qty' => 0,
                'target_qty' => 0,
                'detail_count' => 0,
                'percentage' => 0.0,
            ];
        }

        $db = db_connect();
        $row = $db->table('production_shift_details d')
            ->select(
                "COALESCE(SUM(d.actual_qty), 0) gross_qty,
                 COALESCE(SUM(d.good_qty), 0) good_qty,
                 COALESCE(SUM(d.reject_qty), 0) reject_qty,
                 COALESCE(SUM(d.target_qty), 0) target_qty,
                 COUNT(DISTINCT d.id) detail_count",
                false
            )
            ->join('productions p', 'p.id = d.production_id')
            ->where('d.work_date', $shift['work_date'])
            ->where('d.shift_id', (int) $shift['id'])
            ->where("COALESCE(p.mode, 'production') <> 'setting'", null, false)
            ->get()
            ->getRowArray() ?? [];

        $good = (int) ($row['good_qty'] ?? 0);
        $target = (int) ($row['target_qty'] ?? 0);

        return [
            'work_date' => $shift['work_date'],
            'shift_id' => (int) $shift['id'],
            'shift_code' => $shift['code'],
            'shift_name' => $shift['name'],
            'gross_qty' => (int) ($row['gross_qty'] ?? 0),
            'good_qty' => $good,
            'reject_qty' => (int) ($row['reject_qty'] ?? 0),
            'target_qty' => $target,
            'detail_count' => (int) ($row['detail_count'] ?? 0),
            'percentage' => $target > 0
                ? round(min(100, max(0, ($good / $target) * 100)), 1)
                : 0.0,
        ];
    }

    private function machineSnapshots(): array
    {
        $db = db_connect();

        /*
         * One row per active machine/slot.
         *
         * - p  = latest production that is not completed.
         * - d  = current operational shift detail. If no open detail exists,
         *        the latest completed detail is returned so the UI can show
         *        awaiting_next_shift.
         * - dev = latest device currently assigned to the slot.
         */
        $rows = $db->query(
            "SELECT
                s.id AS slot_id,
                s.slot_no,
                m.id AS machine_id,
                m.name AS machine_name, m.code AS machine_code, m.registration_code, m.serial_number,

                dev.id AS device_id,
                dev.connection_status AS device_connection_status,
                dev.device_status,
                dev.last_seen_at,

                p.id AS production_id, p.mode,
                p.production_code,
                p.target_qty AS production_target,
                p.target_duration_days,
                p.shifts_per_day,
                p.actual_qty AS production_actual,
                p.production_batch_id,
                p.process_no,
                p.process_name_snapshot,
                p.process_mode_snapshot,
                p.status AS production_status,
                p.started_at AS production_started_at,

                pb.final_good_qty AS batch_final_good_qty,

                part.id AS part_id,
                part.part_number,
                part.name AS part_name,
                part.process_count AS part_process_count,

                d.id AS detail_id,
                d.status AS detail_status,
                d.work_date AS detail_work_date,
                d.actual_qty AS shift_actual_qty,
                d.target_qty AS shift_target_qty,
                d.good_qty AS shift_good_qty,
                d.reject_qty AS shift_reject_qty,

                COALESCE(p.good_qty, 0) AS production_good_qty,
                COALESCE(p.reject_qty, 0) AS production_reject_qty,
                d.started_at AS shift_started_at,
                d.ended_at AS shift_ended_at,

                sh.id AS shift_id,
                sh.code AS shift_code,
                sh.name AS shift_name,

                e.id AS operator_id,
                e.name AS operator_name,
                pic.id AS pic_id,
                pic.name AS pic_name

             FROM production_slots s
             JOIN machines m
               ON m.id = s.machine_id

             LEFT JOIN tpms_devices dev
               ON dev.id = (
                    SELECT td.id
                    FROM tpms_devices td
                    WHERE td.current_slot_id = s.id
                    ORDER BY td.last_seen_at DESC, td.id DESC
                    LIMIT 1
               )

             LEFT JOIN productions p
               ON p.id = (
                    SELECT p2.id
                    FROM productions p2
                    WHERE p2.machine_id = m.id
                      AND p2.completed_at IS NULL
                      AND p2.status <> 'completed'
                    ORDER BY p2.id DESC
                    LIMIT 1
               )

             LEFT JOIN parts part
               ON part.id = p.part_id

             LEFT JOIN production_batches pb
               ON pb.id = p.production_batch_id

             LEFT JOIN production_shift_details d
               ON d.id = (
                    SELECT d2.id
                    FROM production_shift_details d2
                    WHERE d2.production_id = p.id
                    ORDER BY
                        CASE
                            WHEN d2.status IN ('running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting') THEN 0
                            ELSE 1
                        END,
                        d2.id DESC
                    LIMIT 1
               )

             LEFT JOIN shifts sh
               ON sh.id = d.shift_id

             LEFT JOIN employees e
               ON e.id = d.operator_employee_id

             LEFT JOIN employees pic
               ON pic.id = d.pic_employee_id

             WHERE m.disposed_at IS NULL
             ORDER BY s.slot_no ASC, m.name ASC"
        )->getResultArray();

        $detailIds = [];
        foreach ($rows as $row) {
            if (! empty($row['detail_id'])) {
                $detailIds[] = (int) $row['detail_id'];
            }
        }
        $detailIds = array_values(array_unique($detailIds));

        $toolsByDetail = [];
        $alarmsByDetail = [];

        if ($detailIds !== []) {
            $toolRows = $db
                ->table('production_tool_usages u')
                ->select(
                    'u.production_shift_detail_id,
                     u.tool_id,
                     u.position_snapshot,
                     u.set_lifetime_snapshot,
                     u.start_lifetime,
                     u.end_lifetime,
                     u.quantity_increment,
                     t.code,
                     t.name,
                     t.cutting_edge,
                     t.current_edge,
                     t.holder,
                     t.actual_lifetime,
                     t.status'
                )
                ->join('tools t', 't.id = u.tool_id')
                ->whereIn('u.production_shift_detail_id', $detailIds)
                ->orderBy('u.production_shift_detail_id', 'ASC')
                ->orderBy('t.actual_lifetime', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($toolRows as $tool) {
                $detailId = (int) $tool['production_shift_detail_id'];
                $limit = (int) $tool['set_lifetime_snapshot'];
                $actual = (int) $tool['actual_lifetime'];
                $remaining = $limit - $actual;
                $percentage = $limit > 0
                    ? min(100, max(0, ($actual / $limit) * 100))
                    : 0;

                $toolsByDetail[$detailId][] = [
                    'id' => (int) $tool['tool_id'],
                    'code' => $tool['code'],
                    'name' => $tool['name'],
                    'position' => $tool['position_snapshot'],
                    'cutting_edge' => max(1, (int) ($tool['cutting_edge'] ?? 1)),
                    'current_edge' => max(1, (int) ($tool['current_edge'] ?? 1)),
                    'holder' => $tool['holder'] ?? null,
                    
                    'actual_lifetime' => $actual,
                    'max_lifetime' => $limit,
                    'remaining' => $remaining,
                    'percentage' => round($percentage, 1),
                    'status' => $tool['status'],
                    'session_increment' => (int) $tool['quantity_increment'],
                    'start_lifetime' => (int) $tool['start_lifetime'],
                    'end_lifetime' => (int) $tool['end_lifetime'],
                ];
            }

            $alarmRows = $db
                ->table('production_alarms a')
                ->select(
                    'a.id,
                     a.production_shift_detail_id,
                     a.tool_id,
                     a.kind,
                     a.severity,
                     a.effect,
                     a.status,
                     a.message,
                     a.created_at,
                     a.updated_at,
                     a.resolved_at,
                     t.code AS tool_code,
                     t.name AS tool_name'
                )
                ->join('tools t', 't.id = a.tool_id', 'left')
                ->whereIn('a.production_shift_detail_id', $detailIds)
                ->where('a.resolved_at', null)
                ->orderBy('a.id', 'DESC')
                ->get()
                ->getResultArray();

            foreach ($alarmRows as $alarm) {
                $detailId = (int) $alarm['production_shift_detail_id'];
                $alarmsByDetail[$detailId][] = [
                    'id' => (int) $alarm['id'],
                    'tool_id' => $alarm['tool_id'] === null ? null : (int) $alarm['tool_id'],
                    'tool_code' => $alarm['tool_code'],
                    'tool_name' => $alarm['tool_name'],
                    'kind' => $alarm['kind'],
                    'severity' => $alarm['severity'],
                    'effect' => $alarm['effect'],
                    'status' => $alarm['status'],
                    'message' => $alarm['message'],
                    'created_at' => $alarm['created_at'],
                ];
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $detailId = $row['detail_id'] === null ? null : (int) $row['detail_id'];
            $tools = $detailId === null ? [] : ($toolsByDetail[$detailId] ?? []);
            $alarms = $detailId === null ? [] : ($alarmsByDetail[$detailId] ?? []);

            /*
             * Header productions menyimpan target keseluruhan.
             * FIX-08: production_shift_details.target_qty adalah target GOOD yang
             * dialokasikan untuk shift saat ini.
             */
            $productionTotalTarget = (int) ($row['production_target'] ?? 0);
            $productionTotalGross = (int) ($row['production_actual'] ?? 0);
            $productionTotalGood = (int) ($row['production_good_qty'] ?? 0);
            $productionTotalReject = (int) ($row['production_reject_qty'] ?? 0);
            $shiftTarget = (int) ($row['shift_target_qty'] ?? 0);
            $shiftGross = (int) ($row['shift_actual_qty'] ?? 0);
            $shiftGood = (int) ($row['shift_good_qty'] ?? 0);
            $shiftReject = (int) ($row['shift_reject_qty'] ?? 0);

            $currentTarget = $detailId !== null
                ? $shiftTarget
                : max(0, $productionTotalTarget - $productionTotalGood);

            $currentGood = $detailId !== null ? $shiftGood : 0;
            $currentGross = $detailId !== null ? $shiftGross : 0;

            $productionPercentage = $currentTarget > 0
                ? min(100, max(0, ($currentGood / $currentTarget) * 100))
                : 0;

            $processNo = (int) ($row['process_no'] ?? 1);
            $partProcessCount = max(1, (int) ($row['part_process_count'] ?? 1));
            $isFinalProcess = $processNo === $partProcessCount;
            $actualProductionTotal = max(
                (int) ($row['batch_final_good_qty'] ?? 0),
                $isFinalProcess ? $productionTotalGood : 0
            );

            $online = $this->deviceOnline($row);
            $statusCode = $this->statusCode($row, $alarms, $online);
            $result[] = [
                'id' => (int) $row['machine_id'],
                'machine_id' => (int) $row['machine_id'],
                'machine_name' => $row['machine_name'],
                'machine_code'=>$row['machine_code'], 'registration_code'=>$row['registration_code'], 'serial_number'=>$row['serial_number'], 'mode'=>$row['mode']??'production',
                'slot_id' => (int) $row['slot_id'],
                'slot' => 'SLOT ' . $row['slot_no'],
                'slot_no' => $row['slot_no'],

                'status_code' => $statusCode,
                'status' => $this->statusLabel($statusCode),
                'device' => [
                    'id' => $row['device_id'] === null ? null : (int) $row['device_id'],
                    'online' => $online,
                    'connection_status' => $row['device_connection_status'],
                    'device_status' => $row['device_status'],
                    'last_seen_at' => $row['last_seen_at'],
                ],

                'production_id' => $row['production_id'] === null ? null : (int) $row['production_id'],
                'production_code' => $row['production_code'],
                'part_id' => $row['part_id'] === null ? null : (int) $row['part_id'],
                'part_number' => $row['part_number'],
                'part_name' => $row['part_name'] ?: 'No Active Production',
                'production_batch_id' => $row['production_batch_id'] === null ? null : (int) $row['production_batch_id'],
                'process_no' => $row['production_id'] === null ? null : $processNo,
                'process_name' => $row['process_name_snapshot'] ?: null,
                'process_mode' => $row['process_mode_snapshot'] ?: null,
                'part_process_count' => $partProcessCount,
                'is_final_process' => $row['production_id'] !== null && $isFinalProcess,
                'actual_production_total' => $actualProductionTotal,
                /* Nilai utama card/modal = shift sekarang. */
                // production_actual dipertahankan untuk UI lama, nilainya GOOD.
                'production_actual' => $currentGood,
                'production_good' => $currentGood,
                'production_gross' => $currentGross,
                'production_target' => $currentTarget,
                'production_percentage' => round($productionPercentage, 1),
                'production_status' => $row['production_status'],
                'start_time' => $row['production_started_at'],

                /* Nilai kumulatif seluruh shift pada production yang sama. */
                // total_actual alias kompatibilitas = gross.
                'production_total_actual' => $productionTotalGross,
                'production_total_gross' => $productionTotalGross,
                'production_total_target' => $productionTotalTarget,
                'target_duration_days' => (int) ($row['target_duration_days'] ?? 1),
                'shifts_per_day' => (int) ($row['shifts_per_day'] ?? 1),
                'planned_shift_count' => (int) ($row['target_duration_days'] ?? 1) * (int) ($row['shifts_per_day'] ?? 1),
                'production_total_good' => $productionTotalGood,
                'production_total_reject' => $productionTotalReject,
                'production_remaining' => max(0, $productionTotalTarget - $productionTotalGood),

                'production_shift_detail_id' => $detailId,
                'detail_status' => $row['detail_status'],
                'shift_actual' => $shiftGross, // legacy alias = gross
                'shift_gross' => $shiftGross,
                'shift_good' => $shiftGood,
                'shift_reject' => $shiftReject,
                'shift_target' => $shiftTarget,
                'good_qty' => $shiftGood,
                'reject_qty' => $shiftReject,
                'shift_start_time' => $row['shift_started_at'],
                'shift_end_time' => $row['shift_ended_at'],
                'work_date' => $row['detail_work_date'],

                'shift_id' => $row['shift_id'] === null ? null : (int) $row['shift_id'],
                'shift' => $row['shift_name'] ?: '-',
                'shift_code' => $row['shift_code'],
                'operator_id' => ($row['mode'] ?? 'production') === 'setting'
                    ? ($row['pic_id'] === null ? null : (int) $row['pic_id'])
                    : ($row['operator_id'] === null ? null : (int) $row['operator_id']),
                'operator' => ($row['mode'] ?? 'production') === 'setting'
                    ? ($row['pic_name'] ?: '-')
                    : ($row['operator_name'] ?: '-'),
                'personnel_role' => ($row['mode'] ?? 'production') === 'setting' ? 'PIC' : 'Operator',

                'tools' => $tools,
                'plan_qty' => $currentTarget,
                'top_tools' => array_slice($tools, 0, 3),
                'alarms' => $alarms,
                'active_alarm_count' => count($alarms),
            ];
        }

        /*
         * Prioritas urutan monitoring:
         * 1. Machine yang memiliki production aktif.
         * 2. Status operasional (running -> paused -> alarm -> idle -> offline).
         * 3. Jumlah production actual terbesar.
         * 4. Nomor slot sebagai tie breaker.
         */
        $statusPriority = [
            'running' => 0,
            'paused' => 1,
            'alarm' => 2,
            'idle' => 3,
            'offline' => 4,
        ];

        usort($result, static function (array $a, array $b) use ($statusPriority): int {
            $aActive = $a['production_id'] !== null ? 0 : 1;
            $bActive = $b['production_id'] !== null ? 0 : 1;

            if ($aActive !== $bActive) {
                return $aActive <=> $bActive;
            }

            $aStatus = $statusPriority[$a['status_code']] ?? 99;
            $bStatus = $statusPriority[$b['status_code']] ?? 99;

            if ($aStatus !== $bStatus) {
                return $aStatus <=> $bStatus;
            }

            $productionCompare = ((int) $b['production_actual']) <=> ((int) $a['production_actual']);
            if ($productionCompare !== 0) {
                return $productionCompare;
            }

            return ((int) $a['slot_no']) <=> ((int) $b['slot_no']);
        });

        return $result;
    }

    private function deviceOnline(array $row): bool
    {
        if (empty($row['device_id'])) {
            return false;
        }

        if (strtolower(trim((string) ($row['device_status'] ?? ''))) === 'offline') {
            return false;
        }

        if (empty($row['last_seen_at'])) {
            return false;
        }

        $timezone = new DateTimeZone(config('App')->appTimezone);
        $lastSeen = new DateTimeImmutable((string) $row['last_seen_at'], $timezone);
        $now = new DateTimeImmutable('now', $timezone);

        $age = $now->getTimestamp() - $lastSeen->getTimestamp();
        $window = max(1, (int) env('TPMS_ONLINE_WINDOW_SECONDS', self::DEVICE_OFFLINE_AFTER_SECONDS));

        return $age >= 0 && $age <= $window;
    }

    private function statusCode(array $row, array $alarms, bool $online): string
    {
        if (! $online) {
            return 'offline';
        }

        // Status fisik dari heartbeat terbaru; status sesi produksi tetap terpisah.
        $reported = strtolower(trim((string) ($row['device_status'] ?? '')));
        $reportedStatus = match ($reported) {
            'run', 'running' => 'running',
            'pause', 'paused' => 'paused',
            'alarm', 'service_required' => 'alarm',
            'idle' => 'idle',
            'setting' => 'setting',
            default => null,
        };

        if ($reportedStatus !== null) {
            return $reportedStatus;
        }

        foreach ($alarms as $alarm) {
            if (($alarm['effect'] ?? null) === 'service_stop' || ($alarm['severity'] ?? null) === 'critical') {
                return 'alarm';
            }
        }

        $detailStatus = (string) ($row['detail_status'] ?? '');

        return match ($detailStatus) {
            'running' => 'running',
            'paused' => 'paused',
            'service_required', 'operator_change_required' => 'alarm',
            'awaiting_defects', 'completed' => 'idle',
            default => 'idle',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'running' => 'Running',
            'setting' => 'Setting',
            'paused' => 'Paused',
            'alarm' => 'Alarm / Service',
            'offline' => 'Offline',
            default => 'Idle',
        };
    }

    private function now(): string
    {
        return (new DateTimeImmutable(
            'now',
            new DateTimeZone(config('App')->appTimezone)
        ))->format('Y-m-d H:i:s');
    }
}
