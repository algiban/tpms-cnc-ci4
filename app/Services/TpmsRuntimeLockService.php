<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;

/**
 * Fine-grained runtime locking for TPMS production.
 *
 * Rules:
 * - Production writers lock only their own device row.
 * - Rare web/master-data mutations that may affect active production can use
 *   lockAllDevices() as a maintenance barrier.
 * - Every caller must already own a database transaction before locking.
 *
 * This replaces the old single-row tpms_runtime_lock bottleneck without
 * removing the safety barrier between runtime writes and master-data writes.
 */
final class TpmsRuntimeLockService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Lock one TPMS device. Requests from different devices can proceed in
     * parallel while requests for the same device remain serialized.
     */
    public function lockDevice(int $deviceId): void
    {
        if ($deviceId <= 0) {
            throw new RuntimeException('Device runtime lock membutuhkan device_id valid.');
        }

        $this->ensureDeviceRows([$deviceId]);

        $row = $this->db->query(
            'SELECT device_id
             FROM tpms_device_runtime_locks
             WHERE device_id = ?
             FOR UPDATE',
            [$deviceId]
        )->getRowArray();

        if (! $row) {
            throw new RuntimeException('Runtime lock TPMS tidak tersedia untuk device #' . $deviceId . '.');
        }
    }

    /**
     * Maintenance barrier for rare mutations that may touch shared production
     * configuration. Locks all registered devices in deterministic order.
     *
     * Production traffic stays concurrent during normal operation; only an
     * admin mutation using this method temporarily waits for/blocks devices.
     */
    public function lockAllDevices(): void
    {
        $this->ensureAllDeviceRows();

        $this->db->query(
            'SELECT device_id
             FROM tpms_device_runtime_locks
             ORDER BY device_id
             FOR UPDATE'
        )->getResultArray();
    }

    /**
     * Pre-create a lock row for a newly registered device. This method does not
     * intentionally hold the row lock beyond the caller transaction semantics.
     */
    public function ensureDevice(int $deviceId): void
    {
        if ($deviceId <= 0) {
            throw new RuntimeException('device_id tidak valid.');
        }

        $this->ensureDeviceRows([$deviceId]);
    }

    /** @param list<int> $deviceIds */
    private function ensureDeviceRows(array $deviceIds): void
    {
        $deviceIds = array_values(array_unique(array_filter(
            array_map('intval', $deviceIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($deviceIds === []) {
            return;
        }

        foreach ($deviceIds as $deviceId) {
            // INSERT IGNORE is intentional: the PK is device_id. It makes the
            // migration backwards-safe for devices created after deployment.
            $this->db->query(
                'INSERT IGNORE INTO tpms_device_runtime_locks (device_id, updated_at)
                 SELECT id, NOW()
                 FROM tpms_devices
                 WHERE id = ?',
                [$deviceId]
            );
        }
    }

    private function ensureAllDeviceRows(): void
    {
        $this->db->query(
            'INSERT IGNORE INTO tpms_device_runtime_locks (device_id, updated_at)
             SELECT id, NOW()
             FROM tpms_devices'
        );
    }
}
