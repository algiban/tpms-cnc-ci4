<?php

namespace App\Models;

use CodeIgniter\Model;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;

final class DashboardModel extends Model
{
    protected $table = 'productions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    private const DEFAULT_DEVICE_ONLINE_WINDOW_SECONDS = 60;

    public function dashboard(): array
    {
        $timezone = new DateTimeZone(config('App')->appTimezone);
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->format('Y-m-d');

        $slots = $this->slotSnapshots();

        return [
            'generatedAt' => $now->format('d M Y, H:i'),
            'machineStatus' => $this->machineStatusSummary($slots),
            'todayProduction' => $this->todayProduction($today),
            'masterSummary' => $this->masterSummary(),
            'productionTrend' => $this->productionTrend($now, 90),
            'topProductionToday' => $this->topProductionToday($today, 5),
            
            'recentMachines' => $this->recentMachines($slots, 8),
        ];
    }

    private function todayProduction(string $today): array
    {
        /*
         * FIX-08: target detail adalah target GOOD per shift. Jika production yang
         * sama memiliki beberapa shift hari ini, target harian adalah jumlah target
         * seluruh shift pada hari tersebut.
         */
        $row = $this->db->query(
            "SELECT
                COALESCE(SUM(x.gross_qty), 0) AS gross_qty,
                COALESCE(SUM(x.good_qty), 0) AS good_qty,
                COALESCE(SUM(x.target_qty), 0) AS target_qty,
                COALESCE(SUM(x.reject_qty), 0) AS reject_qty,
                COUNT(DISTINCT x.machine_id) AS active_machines,
                COUNT(DISTINCT x.part_id) AS active_parts,
                COUNT(DISTINCT x.rack_tool_id) AS used_rack_tools
             FROM (
                SELECT
                    p.id AS production_id,
                    p.machine_id,
                    p.part_id,
                    p.rack_tool_id,
                    SUM(d.actual_qty) AS gross_qty,
                    SUM(CASE WHEN (p.plan_basis = 'cycle_7h' OR p.process_no = COALESCE(part.process_count, 1)) THEN d.good_qty ELSE 0 END) AS good_qty,
                    SUM(CASE WHEN (p.plan_basis = 'cycle_7h' OR p.process_no = COALESCE(part.process_count, 1)) THEN d.target_qty ELSE 0 END) AS target_qty,
                    SUM(d.reject_qty) AS reject_qty
                FROM production_shift_details d
                JOIN productions p
                    ON p.id = d.production_id
                JOIN parts part
                    ON part.id = p.part_id
                WHERE p.mode = 'production' AND COALESCE(d.work_date, p.production_date) = ?
                GROUP BY
                    p.id,
                    p.machine_id,
                    p.part_id,
                    p.rack_tool_id
             ) x",
            [$today]
        )->getRowArray() ?: [];

        $gross = (int) ($row['gross_qty'] ?? 0);
        $good = (int) ($row['good_qty'] ?? 0);
        $target = (int) ($row['target_qty'] ?? 0);
        $reject = (int) ($row['reject_qty'] ?? 0);

        return [
            // actual dipertahankan untuk kompatibilitas view lama, nilainya GOOD.
            'actual' => $good,
            'gross' => $gross,
            'good' => $good,
            'target' => $target,
            'reject' => $reject,
            'remaining' => max(0, $target - $good),

            'progress' => $target > 0
                ? round(($good / $target) * 100, 1)
                : 0,

            'nc_rate' => $gross > 0
                ? round(($reject / $gross) * 100, 1)
                : 0,

            'active_machines' => (int) ($row['active_machines'] ?? 0),
            'active_parts' => (int) ($row['active_parts'] ?? 0),
            'used_rack_tools' => (int) ($row['used_rack_tools'] ?? 0),
        ];
    }

    private function masterSummary(): array
    {
        $customers = $this->db
            ->table('customers')
            ->select(
                "COUNT(*) AS total,
                 SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active",
                false
            )
            ->get()
            ->getRowArray();

        $parts = $this->db
            ->table('parts')
            ->select(
                "COUNT(*) AS total,
                 SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active",
                false
            )
            ->get()
            ->getRowArray();

        $materials = $this->db
            ->table('materials')
            ->select('COUNT(*) AS total', false)
            ->get()
            ->getRowArray();

        return [

            'customers' => (int) ($customers['total'] ?? 0),
            'active_customers' => (int) ($customers['active'] ?? 0),

            'parts' => (int) ($parts['total'] ?? 0),
            'active_parts' => (int) ($parts['active'] ?? 0),

            'materials' => (int) ($materials['total'] ?? 0),
        ];
    }

    private function productionTrend(
        DateTimeImmutable $now,
        int $days
    ): array {
        $days = max(1, min(90, $days));

        $start = $now
            ->modify('-' . ($days - 1) . ' days')
            ->setTime(0, 0);

        $end = $now->setTime(0, 0);

        $rows = $this->db->query(
            "SELECT
                x.work_date,
                COALESCE(SUM(x.actual_qty), 0) AS actual_qty,
                COALESCE(SUM(x.target_qty), 0) AS target_qty
             FROM (
                SELECT
                    COALESCE(d.work_date, p.production_date) AS work_date,
                    p.id AS production_id,
                    SUM(CASE WHEN (p.plan_basis = 'cycle_7h' OR p.process_no = COALESCE(part.process_count, 1)) THEN d.good_qty ELSE 0 END) AS actual_qty,
                    SUM(CASE WHEN (p.plan_basis = 'cycle_7h' OR p.process_no = COALESCE(part.process_count, 1)) THEN d.target_qty ELSE 0 END) AS target_qty
                FROM production_shift_details d
                JOIN productions p
                    ON p.id = d.production_id
                JOIN parts part
                    ON part.id = p.part_id
                WHERE COALESCE(d.work_date, p.production_date)
                    BETWEEN ? AND ?
                GROUP BY
                    COALESCE(d.work_date, p.production_date),
                    p.id
             ) x
             GROUP BY x.work_date
             ORDER BY x.work_date ASC",
            [
                $start->format('Y-m-d'),
                $end->format('Y-m-d'),
            ]
        )->getResultArray();

        $indexed = [];

        foreach ($rows as $row) {
            $indexed[$row['work_date']] = [
                'actual' => (int) $row['actual_qty'],
                'target' => (int) $row['target_qty'],
            ];
        }

        $period = new DatePeriod(
            $start,
            new DateInterval('P1D'),
            $end->modify('+1 day')
        );

        $result = [];

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');

            $value = $indexed[$key] ?? [
                'actual' => 0,
                'target' => 0,
            ];

            $result[] = [
                'date' => $key,
                'label' => $date->format('d M'),
                'actual' => $value['actual'],
                'target' => $value['target'],
            ];
        }

        return $result;
    }

    private function topProductionToday(
        string $today,
        int $limit
    ): array {
        $limit = max(1, min(10, $limit));

        return $this->db->query(
            "SELECT
                p.id AS production_id,
                p.machine_id,
                m.code AS machine_code,
                m.name AS machine_name,

                s.slot_no,

                part.part_number,
                part.name AS part_name,

                c.name AS customer_name,

                SUM(d.good_qty) AS actual_qty,
                SUM(d.actual_qty) AS gross_qty,
                SUM(d.target_qty) AS target_qty,
                SUM(d.reject_qty) AS reject_qty

            FROM production_shift_details d

            JOIN productions p
                ON p.id = d.production_id

            JOIN machines m
                ON m.id = p.machine_id

            JOIN production_slots s
                ON s.id = p.slot_id

            JOIN parts part
                ON part.id = p.part_id

            JOIN customers c
                ON c.id = part.customer_id

            WHERE p.mode = 'production' AND COALESCE(d.work_date, p.production_date) = ?
              AND p.process_no = COALESCE(part.process_count, 1)

            GROUP BY
                p.id,
                p.machine_id,
                m.code,
                m.name,
                s.slot_no,
                part.id,
                part.part_number,
                part.name,
                c.name

            ORDER BY actual_qty DESC, target_qty DESC

            LIMIT {$limit}",
            [$today]
        )->getResultArray();
    }

    private function activeRackToolsToday(
        string $today,
        int $limit
    ): array {
        $limit = max(1, min(10, $limit));

        return $this->db->query(
            "SELECT
                p.id AS production_id,

                rt.id AS rack_tool_id,
                rt.code AS rack_tool_code,
                rt.name AS rack_tool_name,
                rt.status AS rack_tool_status,

                m.name AS machine_name,

                s.slot_no,

                part.part_number,
                part.name AS part_name,

                SUM(d.good_qty) AS actual_qty,
                SUM(d.actual_qty) AS gross_qty,
                SUM(d.target_qty) AS target_qty,
                MIN(d.started_at) AS started_at

            FROM productions p

            JOIN production_shift_details d
                ON d.production_id = p.id

            JOIN rack_tools rt
                ON rt.id = p.rack_tool_id

            JOIN machines m
                ON m.id = p.machine_id

            JOIN production_slots s
                ON s.id = p.slot_id

            JOIN parts part
                ON part.id = p.part_id

            WHERE p.completed_at IS NULL
              AND p.status <> 'completed'
              AND COALESCE(d.work_date, p.production_date) = ?

            GROUP BY
                p.id,
                rt.id,
                rt.code,
                rt.name,
                rt.status,
                m.name,
                s.slot_no,
                part.id,
                part.part_number,
                part.name

            ORDER BY
                MAX(d.started_at) DESC,
                p.id DESC

            LIMIT {$limit}",
            [$today]
        )->getResultArray();
    }

    private function slotSnapshots(): array
    {
        $rows = $this->db->query(
            "SELECT
                s.id AS slot_id,
                s.slot_no,
                s.name AS slot_name,
                s.machine_id,
                s.status AS slot_status,
                s.updated_at AS slot_updated_at,

                m.code AS machine_code,
                m.name AS machine_name,
                m.status AS machine_master_status,
                m.updated_at AS machine_updated_at,

                dev.id AS device_id,
                dev.device_status,
                dev.connection_status,
                dev.last_seen_at,

                p.id AS production_id,
                p.status AS production_status,
                p.rack_tool_id,

                part.part_number,
                part.name AS part_name,

                rt.code AS rack_tool_code,
                rt.name AS rack_tool_name,

                d.status AS detail_status

            FROM production_slots s

            LEFT JOIN machines m
                ON m.id = s.machine_id
               AND m.disposed_at IS NULL

            LEFT JOIN tpms_devices dev
                ON dev.id = (
                    SELECT td.id
                    FROM tpms_devices td
                    WHERE td.current_slot_id = s.id
                    ORDER BY
                        td.last_seen_at DESC,
                        td.id DESC
                    LIMIT 1
                )

            LEFT JOIN productions p
                ON p.id = (
                    SELECT p2.id
                    FROM productions p2
                    WHERE p2.slot_id = s.id
                      AND p2.completed_at IS NULL
                      AND p2.status <> 'completed'
                    ORDER BY p2.id DESC
                    LIMIT 1
                )

            LEFT JOIN parts part
                ON part.id = p.part_id

            LEFT JOIN rack_tools rt
                ON rt.id = p.rack_tool_id

            LEFT JOIN production_shift_details d
                ON d.id = (
                    SELECT d2.id
                    FROM production_shift_details d2
                    WHERE d2.production_id = p.id

                    ORDER BY
                        CASE
                            WHEN d2.status IN (
                                'running',
                                'paused',
                                'service_required',
                                'awaiting_defects',
                                'operator_change_required',
                                'setting'
                            )
                            THEN 0
                            ELSE 1
                        END,

                        d2.id DESC

                    LIMIT 1
                )

            ORDER BY s.slot_no ASC"
        )->getResultArray();

        foreach ($rows as &$row) {
            $row['dashboard_status'] = $this->statusCode($row);
        }

        unset($row);

        return $rows;
    }

    private function machineStatusSummary(array $slots): array
    {
        $summary = [
            'total_slots' => count($slots),

            'assigned' => 0,

            'running' => 0,
            'setting' => 0,
            'idle' => 0,
            'alarm' => 0,
            'offline' => 0,

            'empty' => 0,
        ];

        foreach ($slots as $slot) {
            if (empty($slot['machine_id'])) {
                $summary['empty']++;
                continue;
            }

            $summary['assigned']++;

            $status = $slot['dashboard_status'] ?? 'offline';

            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['idle']++;
            }
        }

        return $summary;
    }

    private function recentMachines(
        array $slots,
        int $limit
    ): array {
        $rows = array_values(
            array_filter(
                $slots,
                static fn(array $row): bool =>
                ! empty($row['machine_id'])
            )
        );

        usort(
            $rows,
            static function (array $a, array $b): int {
                $aTime = max(
                    strtotime(
                        (string) ($a['last_seen_at'] ?? '')
                    ) ?: 0,

                    strtotime(
                        (string) ($a['machine_updated_at'] ?? '')
                    ) ?: 0,

                    strtotime(
                        (string) ($a['slot_updated_at'] ?? '')
                    ) ?: 0
                );

                $bTime = max(
                    strtotime(
                        (string) ($b['last_seen_at'] ?? '')
                    ) ?: 0,

                    strtotime(
                        (string) ($b['machine_updated_at'] ?? '')
                    ) ?: 0,

                    strtotime(
                        (string) ($b['slot_updated_at'] ?? '')
                    ) ?: 0
                );

                return $bTime <=> $aTime;
            }
        );

        return array_slice(
            $rows,
            0,
            max(1, min(15, $limit))
        );
    }

    private function statusCode(array $row): string
    {
        if (empty($row['machine_id'])) {
            return 'empty';
        }

        if (! $this->deviceOnline($row)) {
            return 'offline';
        }

        $reported = strtolower(
            trim(
                (string) (
                    $row['device_status']
                    ?? ''
                )
            )
        );

        $reportedStatus = match ($reported) {
            'run',
            'running'
            => 'running',

            'alarm' => 'alarm',
            'setting' => 'setting',
            'service_required'
            => 'alarm',

            'pause',
            'paused',
            'idle'
            => 'idle',

            default
            => null,
        };

        if ($reportedStatus !== null) {
            return $reportedStatus;
        }

        return match ((string) (
            $row['detail_status']
            ?? ''
        )) {
            'running'
            => 'running',

            'setting' => 'setting',
            'service_required',
            'operator_change_required'
            => 'alarm',

            'paused',
            'awaiting_defects',
            'completed'
            => 'idle',

            default
            => 'idle',
        };
    }

    private function deviceOnline(array $row): bool
    {
        if (
            empty($row['device_id'])
            || empty($row['last_seen_at'])
        ) {
            return false;
        }

        if (
            strtolower(
                trim(
                    (string) (
                        $row['device_status']
                        ?? ''
                    )
                )
            ) === 'offline'
        ) {
            return false;
        }

        $timezone = new DateTimeZone(
            config('App')->appTimezone
        );

        $lastSeen = new DateTimeImmutable(
            $row['last_seen_at'],
            $timezone
        );

        $now = new DateTimeImmutable(
            'now',
            $timezone
        );

        $age =
            $now->getTimestamp()
            - $lastSeen->getTimestamp();

        $window = max(
            1,
            (int) env(
                'TPMS_ONLINE_WINDOW_SECONDS',
                self::DEFAULT_DEVICE_ONLINE_WINDOW_SECONDS
            )
        );

        return $age >= 0 && $age <= $window;
    }
}
