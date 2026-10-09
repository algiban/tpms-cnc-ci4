<?php

namespace App\Services;

use App\Models\ProductionSlotModel;
use App\Models\TpmsAssignmentHistoryModel;
use App\Models\TpmsDeviceModel;
use DomainException;
use RuntimeException;

class TpmsRegistrationService
{
    public function register(array $payload): array
    {
        $db = db_connect();
        $devices = new TpmsDeviceModel();
        $slots = new ProductionSlotModel();
        $history = new TpmsAssignmentHistoryModel();

        $mac = $this->normalizeMac($payload['mac_address'] ?? '');
        $ip = trim((string) ($payload['ip_address'] ?? ''));
        $slotNo = isset($payload['slot_no']) && $payload['slot_no'] !== '' ? (int) $payload['slot_no'] : null;

        $slot = null;
        if ($slotNo !== null) {
            $slot = $slots->where('slot_no', $slotNo)->first();
            if (! $slot) {
                throw new RuntimeException("Slot {$slotNo} tidak ditemukan.");
            }
        }

        $db->transStart();
        $device = $devices->where('mac_address', $mac)->first();
        $now = date('Y-m-d H:i:s');

        if (! $device) {
            if ($slot !== null) {
                try {
                    (new ProductionRuntimeGuard($db))->assertSlotMutable(
                        (int) $slot['id'],
                        'menerima TPMS baru'
                    );
                } catch (DomainException $e) {
                    $db->transRollback();
                    throw $e;
                }
            }

            $token = bin2hex(random_bytes(16));
            $deviceId = $devices->insert([
                'mac_address' => $mac,
                'ip_address' => $ip ?: null,
                'firmware_version' => $payload['firmware_version'] ?? null,
                'hmi_version' => $payload['hmi_version'] ?? null,
                'token' => $token,
                'current_slot_id' => $slot['id'] ?? null,
                'device_status' => $payload['status'] ?? 'online',
                'connection_status' => 'unknown',
                'registered_at' => $now,
                'last_seen_at' => $now,
            ], true);

            $history->insert([
                'tpms_device_id' => $deviceId,
                'from_slot_id' => null,
                'to_slot_id' => $slot['id'] ?? null,
                'event_type' => 'register',
                'ip_address' => $ip ?: null,
                'notes' => 'Device registered from TPMS API.',
                'happened_at' => $now,
            ]);

            (new TpmsRuntimeLockService($db))->ensureDevice((int) $deviceId);
            $device = $devices->find($deviceId);

            (new ActivityLogService())->tpms(
                (int) $deviceId,
                'registered',
                null,
                $this->deviceSnapshot($device),
                [
                    'slot_id' => $slot['id'] ?? null,
                    'machine_id' => $slot['machine_id'] ?? null,
                    'source' => 'registration_api',
                    'actor_type' => 'device',
                    'severity' => 'info',
                    'message' => 'TPMS pertama kali terdaftar.',
                ]
            );
        } else {
            $oldSlotId = $device['current_slot_id'] ? (int) $device['current_slot_id'] : null;
            $newSlotId = $slot['id'] ?? null;

            /*
             * Re-registration normally only refreshes metadata. When a device is
             * actually moved to another slot, synchronize the move with its
             * production writer using the same per-device runtime lock.
             */
            if ($slotNo !== null && $oldSlotId !== $newSlotId) {
                // Slot moves touch both source and destination assignment state, so
                // use the maintenance barrier to prevent two devices racing into
                // the same slot and to synchronize with active production.
                (new TpmsRuntimeLockService($db))->lockAllDevices();
                $device = $devices->find((int) $device['id']);
                if (! $device) {
                    throw new RuntimeException('TPMS tidak ditemukan setelah runtime lock.');
                }
                $oldSlotId = $device['current_slot_id'] ? (int) $device['current_slot_id'] : null;
            }

            $update = [
                'ip_address' => $ip ?: $device['ip_address'],
                'firmware_version' => $payload['firmware_version'] ?? $device['firmware_version'],
                'hmi_version' => $payload['hmi_version'] ?? $device['hmi_version'],
                'device_status' => $payload['status'] ?? 'online',
                'last_seen_at' => $now,
            ];

            if ($slotNo !== null && $oldSlotId !== $newSlotId) {
                try {
                    $guard = new ProductionRuntimeGuard($db);
                    $guard->assertDeviceMutable((int) $device['id'], 'dipindahkan');
                    if ($oldSlotId !== null) {
                        $guard->assertSlotMutable($oldSlotId, 'melepas TPMS');
                    }
                    if ($newSlotId !== null) {
                        $guard->assertSlotMutable($newSlotId, 'menerima TPMS baru');
                    }
                } catch (DomainException $e) {
                    $db->transRollback();
                    throw $e;
                }
            }

            if ($slotNo !== null) {
                $update['current_slot_id'] = $newSlotId;
            }

            $devices->update($device['id'], $update);

            if ($slotNo !== null && $oldSlotId !== $newSlotId) {
                $newToken = bin2hex(random_bytes(16));
                $devices->update($device['id'], ['token' => $newToken]);
                $history->insert([
                    'tpms_device_id' => $device['id'],
                    'from_slot_id' => $oldSlotId,
                    'to_slot_id' => $newSlotId,
                    'event_type' => 'move',
                    'ip_address' => $ip ?: $device['ip_address'],
                    'notes' => 'Device moved to another slot via TPMS API.',
                    'happened_at' => $now,
                ]);

                $newSlot = $newSlotId !== null
                    ? $slots->find($newSlotId)
                    : null;

                (new ActivityLogService())->tpms(
                    (int) $device['id'],
                    'slot_changed',
                    ['slot_id' => $oldSlotId],
                    ['slot_id' => $newSlotId],
                    [
                        'slot_id' => $newSlotId,
                        'machine_id' => $newSlot['machine_id'] ?? null,
                        'source' => 'registration_api',
                        'actor_type' => 'device',
                        'severity' => 'info',
                        'message' => 'TPMS berpindah production slot.',
                    ]
                );
            }

            $device = $devices->find($device['id']);
        }

        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('Gagal menyimpan registrasi TPMS.');
        }

        return $device;
    }

    public function heartbeat(array $payload, string $token): array
    {
        $db = db_connect();
        $logger = new ActivityLogService();

        $mac = $this->normalizeMac($payload['mac_address'] ?? '');
        $token = trim($token);

        if ($token === '') {
            throw new DomainException(
                'Token perangkat tidak dikirim.',
                401
            );
        }

        /*
         * Phase 3 heartbeat query: device + slot/machine context in one SELECT.
         * The old path did SELECT device -> UPDATE -> SELECT device -> SELECT slot.
         */
        $device = $db
            ->table('tpms_devices d')
            ->select('d.*, s.machine_id AS assigned_machine_id')
            ->join('production_slots s', 's.id = d.current_slot_id', 'left')
            ->where('d.mac_address', $mac)
            ->get()
            ->getRowArray();

        if (! $device) {
            throw new DomainException(
                'Perangkat TPMS belum terdaftar.',
                401
            );
        }

        $storedToken = trim((string) ($device['token'] ?? ''));
        if ($storedToken === '' || ! hash_equals($storedToken, $token)) {
            throw new DomainException(
                'Token perangkat tidak valid.',
                401
            );
        }

        $status = strtolower(
            trim(
                (string) (
                    $payload['status']
                    ?? $device['device_status']
                    ?? 'online'
                )
            )
        );

        if (! in_array($status, ['online', 'run', 'idle', 'paused', 'alarm', 'offline', 'setting'], true)) {
            throw new DomainException(
                'Status TPMS tidak valid.',
                422
            );
        }

        $now = date('Y-m-d H:i:s');
        $oldConnection = strtolower(
            trim((string) ($device['connection_status'] ?? 'unknown'))
        );
        $oldStatus = strtolower(
            trim((string) ($device['device_status'] ?? 'offline'))
        );

        /* Build only meaningful changes first. */
        $changes = [];
        if ($oldStatus !== $status) {
            $changes['device_status'] = $status;
        }

        if (! empty($payload['ip_address'])) {
            $ip = trim((string) $payload['ip_address']);
            if (($device['ip_address'] ?? null) !== $ip) {
                $changes['ip_address'] = $ip;
            }
        }

        if (array_key_exists('firmware_version', $payload)) {
            $firmware = $payload['firmware_version'] !== ''
                ? trim((string) $payload['firmware_version'])
                : null;
            if (($device['firmware_version'] ?? null) !== $firmware) {
                $changes['firmware_version'] = $firmware;
            }
        }

        if (array_key_exists('hmi_version', $payload)) {
            $hmi = $payload['hmi_version'] !== ''
                ? trim((string) $payload['hmi_version'])
                : null;
            if (($device['hmi_version'] ?? null) !== $hmi) {
                $changes['hmi_version'] = $hmi;
            }
        }

        $touchDue = $this->heartbeatTouchDue($device);

        /*
         * Any real metadata/status write is also a liveness proof, so piggyback
         * last_seen_at on that UPDATE. Otherwise only touch it after the throttle
         * window. This makes frequent heartbeat calls SELECT-only most of the time.
         */
        if ($touchDue || $changes !== []) {
            $changes['last_seen_at'] = $now;
            $changes['last_connection_check_at'] = $now;
            $changes['connection_status'] = 'reachable';
        }

        if ($changes !== []) {
            $db
                ->table('tpms_devices')
                ->where('id', (int) $device['id'])
                ->update($changes);
        }

        /* No re-SELECT: merge the values we just persisted into the local row. */
        $updated = array_replace($device, $changes);

        $slotId = ! empty($updated['current_slot_id'])
            ? (int) $updated['current_slot_id']
            : null;
        $machineId = ! empty($updated['assigned_machine_id'])
            ? (int) $updated['assigned_machine_id']
            : null;

        $context = [
            'slot_id' => $slotId,
            'machine_id' => $machineId,
            'source' => 'heartbeat',
            'actor_type' => 'device',
        ];

        $newStatus = strtolower(
            trim((string) ($updated['device_status'] ?? 'offline'))
        );

        if ($oldStatus !== $newStatus) {
            $severity = $newStatus === 'alarm'
                ? 'warning'
                : ($newStatus === 'offline' ? 'critical' : 'info');

            $logger->tpms(
                (int) $device['id'],
                'status_changed',
                ['status' => $oldStatus],
                ['status' => $newStatus],
                $context + [
                    'severity' => $severity,
                    'message' => sprintf(
                        'Status TPMS berubah dari %s menjadi %s.',
                        $oldStatus,
                        $newStatus
                    ),
                    'metadata' => [
                        'heartbeat_at' => $now,
                    ],
                ]
            );

            if ($machineId !== null) {
                $logger->machine(
                    $machineId,
                    'operational_status_changed',
                    ['status' => $oldStatus],
                    ['status' => $newStatus],
                    [
                        'slot_id' => $slotId,
                        'tpms_device_id' => (int) $device['id'],
                        'source' => 'tpms_heartbeat',
                        'actor_type' => 'device',
                        'severity' => $severity,
                        'previous_status' => $oldStatus,
                        'new_status' => $newStatus,
                        'message' => sprintf(
                            'Status operasional machine berubah dari %s menjadi %s berdasarkan heartbeat TPMS.',
                            $oldStatus,
                            $newStatus
                        ),
                        'metadata' => [
                            'mac_address' => $updated['mac_address'] ?? null,
                            'heartbeat_at' => $now,
                        ],
                    ]
                );
            }
        }

        if (($device['ip_address'] ?? null) !== ($updated['ip_address'] ?? null)) {
            $logger->tpms(
                (int) $device['id'],
                'ip_changed',
                ['ip_address' => $device['ip_address'] ?? null],
                ['ip_address' => $updated['ip_address'] ?? null],
                $context + [
                    'severity' => 'info',
                    'message' => 'IP address TPMS berubah.',
                ]
            );
        }

        if (($device['firmware_version'] ?? null) !== ($updated['firmware_version'] ?? null)) {
            $logger->tpms(
                (int) $device['id'],
                'firmware_changed',
                ['firmware_version' => $device['firmware_version'] ?? null],
                ['firmware_version' => $updated['firmware_version'] ?? null],
                $context + [
                    'severity' => 'info',
                    'message' => 'Firmware TPMS berubah.',
                ]
            );
        }

        if (($device['hmi_version'] ?? null) !== ($updated['hmi_version'] ?? null)) {
            $logger->tpms(
                (int) $device['id'],
                'hmi_changed',
                ['hmi_version' => $device['hmi_version'] ?? null],
                ['hmi_version' => $updated['hmi_version'] ?? null],
                $context + [
                    'severity' => 'info',
                    'message' => 'Versi HMI TPMS berubah.',
                ]
            );
        }

        if ($oldConnection === 'unreachable') {
            $logger->tpms(
                (int) $device['id'],
                'connection_restored',
                ['connection_status' => $oldConnection],
                ['connection_status' => 'reachable'],
                $context + [
                    'severity' => 'info',
                    'message' => 'Koneksi TPMS kembali aktif melalui heartbeat.',
                ]
            );
        }

        unset($updated['assigned_machine_id']);

        return $updated;
    }

    private function heartbeatTouchDue(array $device): bool
    {
        if (strtolower((string) ($device['connection_status'] ?? 'unknown')) !== 'reachable') {
            return true;
        }

        $lastSeen = trim((string) ($device['last_seen_at'] ?? ''));
        if ($lastSeen === '') {
            return true;
        }

        $lastSeenTs = strtotime($lastSeen);
        if ($lastSeenTs === false) {
            return true;
        }

        $interval = max(1, (int) env('TPMS_LIVENESS_TOUCH_SECONDS', 10));

        return (time() - $lastSeenTs) >= $interval;
    }

    private function deviceSnapshot(array $device): array
    {
        return [
            'id' => $device['id'] ?? null,
            'mac_address' => $device['mac_address'] ?? null,
            'ip_address' => $device['ip_address'] ?? null,
            'firmware_version' => $device['firmware_version'] ?? null,
            'hmi_version' => $device['hmi_version'] ?? null,
            'current_slot_id' => $device['current_slot_id'] ?? null,
            'device_status' => $device['device_status'] ?? null,
            'connection_status' => $device['connection_status'] ?? null,
            'registered_at' => $device['registered_at'] ?? null,
            'last_seen_at' => $device['last_seen_at'] ?? null,
        ];
    }

    private function normalizeMac(string $mac): string
    {
        $mac = strtoupper(str_replace('-', ':', trim($mac)));
        if (! preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            throw new RuntimeException('MAC address tidak valid.');
        }
        return $mac;
    }
}
