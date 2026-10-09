<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

final class ProductionRuntimeGuard
{
    private const ACTIVE_DETAIL_STATUSES = [
        'running',
        'paused',
        'service_required',
        'awaiting_defects',
        'operator_change_required',
        'setting',
    ];

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    public function assertMachineMutable(int $machineId, string $operation = 'diubah'): void
    {
        $this->assertNoBlockingProduction('p.machine_id', $machineId, "Machine tidak dapat {$operation}");
    }

    public function assertSlotMutable(int $slotId, string $operation = 'diubah'): void
    {
        $this->assertNoBlockingProduction('p.slot_id', $slotId, "Slot tidak dapat {$operation}");
    }

    public function assertDeviceMutable(int $deviceId, string $operation = 'dipindahkan'): void
    {
        $this->assertNoBlockingProduction('p.device_id', $deviceId, "TPMS tidak dapat {$operation}");
    }

    public function assertPartMutable(int $partId, string $operation = 'diubah'): void
    {
        $this->assertNoBlockingProduction('p.part_id', $partId, "Part tidak dapat {$operation}");
    }

    public function assertRackMutable(int $rackId, string $operation = 'diubah'): void
    {
        $this->assertNoBlockingProduction('p.rack_tool_id', $rackId, "Racktools tidak dapat {$operation}");
    }

    public function assertToolMutable(int $toolId, string $operation = 'diubah'): void
    {
        $row = $this->db
            ->table('production_tool_usages u')
            ->select('p.production_code, d.status detail_status')
            ->join('production_shift_details d', 'd.id = u.production_shift_detail_id')
            ->join('productions p', 'p.id = d.production_id')
            ->where('u.tool_id', $toolId)
            ->whereIn('d.status', self::ACTIVE_DETAIL_STATUSES)
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($row) {
            throw new DomainException(sprintf(
                'Tool tidak dapat %s karena masih digunakan production %s (%s).',
                $operation,
                $row['production_code'] ?? '-',
                $row['detail_status'] ?? '-'
            ), 409);
        }
    }

    public function assertEmployeeMutable(int $employeeId, string $operation = 'diubah'): void
    {
        $row = $this->db
            ->table('production_shift_details d')
            ->select('p.production_code, d.status detail_status')
            ->join('productions p', 'p.id = d.production_id')
            ->groupStart()
                ->where('d.operator_employee_id', $employeeId)
                ->orWhere('d.pic_employee_id', $employeeId)
                ->orWhere('d.unit_head_employee_id', $employeeId)
            ->groupEnd()
            ->whereIn('d.status', self::ACTIVE_DETAIL_STATUSES)
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($row) {
            throw new DomainException(sprintf(
                'Employee tidak dapat %s karena masih menjadi personel production %s (%s).',
                $operation,
                $row['production_code'] ?? '-',
                $row['detail_status'] ?? '-'
            ), 409);
        }
    }

    private function assertNoBlockingProduction(string $column, int $id, string $prefix): void
    {
        if ($id <= 0) {
            return;
        }

        $row = $this->db
            ->table('production_shift_details d')
            ->select('p.production_code, d.status detail_status')
            ->join('productions p', 'p.id = d.production_id')
            ->where($column, $id)
            ->whereIn('d.status', self::ACTIVE_DETAIL_STATUSES)
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($row) {
            throw new DomainException(sprintf(
                '%s karena masih dipakai production %s (%s).',
                $prefix,
                $row['production_code'] ?? '-',
                $row['detail_status'] ?? '-'
            ), 409);
        }
    }
}
