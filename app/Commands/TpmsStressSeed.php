<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use RuntimeException;
use Throwable;

final class TpmsStressSeed extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:stress-seed';
    protected $description = 'Buat device TPMS dummy khusus polling stress test. Tidak membuat production.';
    protected $usage = 'tpms:stress-seed [--devices 100]';
    protected $options = [
        '--devices' => 'Jumlah stress device yang harus tersedia (1-1000). Default 100.',
    ];

    public function run(array $params)
    {
        try {
            if (ENVIRONMENT === 'production') {
                CLI::error('Ditolak: stress seed tidak boleh dijalankan saat CI_ENVIRONMENT=production.');
                return EXIT_ERROR;
            }

            $raw = CLI::getOption('devices');
            $target = $raw !== null && is_numeric($raw) ? (int) $raw : 100;
            $target = max(1, min(1000, $target));

            $db = db_connect();
            if (! $db->tableExists('tpms_devices')) {
                throw new RuntimeException('Tabel tpms_devices tidak ditemukan. Jalankan migration terlebih dahulu.');
            }
            if (! $db->tableExists('tpms_device_runtime_locks')) {
                throw new RuntimeException('Tabel tpms_device_runtime_locks belum ada. Jalankan php spark migrate.');
            }

            $existing = (int) $db->table('tpms_devices')
                ->like('token', 'stress-', 'after')
                ->countAllResults();

            $toCreate = max(0, $target - $existing);
            if ($toCreate === 0) {
                CLI::write('TPMS Phase 8 Stress Seed', 'green');
                CLI::write('Stress devices tersedia : ' . $existing);
                CLI::write('Target                   : ' . $target);
                CLI::write('Tidak ada row baru yang diperlukan.');
                return EXIT_SUCCESS;
            }

            $now = date('Y-m-d H:i:s');
            $rows = [];
            $usedMacs = [];

            // Ambil suffix tertinggi supaya rerun tetap idempotent dan tidak bentrok.
            $stressRows = $db->table('tpms_devices')
                ->select('mac_address, token')
                ->like('token', 'stress-', 'after')
                ->get()
                ->getResultArray();
            foreach ($stressRows as $row) {
                $usedMacs[strtoupper((string) $row['mac_address'])] = true;
            }

            $created = 0;
            $candidate = 1;
            while ($created < $toCreate) {
                // Locally administered unicast MAC: 02:54:50:xx:xx:xx
                $n = $candidate++;
                $mac = sprintf(
                    '02:54:50:%02X:%02X:%02X',
                    ($n >> 16) & 0xFF,
                    ($n >> 8) & 0xFF,
                    $n & 0xFF
                );
                if (isset($usedMacs[$mac])) {
                    continue;
                }
                $usedMacs[$mac] = true;

                $rows[] = [
                    'mac_address' => $mac,
                    'ip_address' => null,
                    'firmware_version' => 'stress-test',
                    'hmi_version' => 'stress-test',
                    'token' => 'stress-' . bin2hex(random_bytes(16)),
                    'production_api_key_hash' => null,
                    'current_slot_id' => null,
                    'device_status' => 'idle',
                    'connection_status' => 'unknown',
                    'registered_at' => $now,
                    'last_seen_at' => null,
                    'last_connection_check_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $created++;
            }

            $db->transBegin();
            foreach (array_chunk($rows, 200) as $chunk) {
                if (! $db->table('tpms_devices')->insertBatch($chunk)) {
                    throw new RuntimeException('Gagal insert batch stress device.');
                }
            }

            // Lock rows dibuat sekaligus untuk semua stress device yang baru/ada.
            $db->query(
                "INSERT IGNORE INTO tpms_device_runtime_locks (device_id, updated_at)
                 SELECT id, NOW()
                 FROM tpms_devices
                 WHERE token LIKE 'stress-%'"
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Transaction stress seed gagal.');
            }
            $db->transCommit();

            $finalCount = (int) $db->table('tpms_devices')
                ->like('token', 'stress-', 'after')
                ->countAllResults();
            $lockCount = (int) $db->table('tpms_device_runtime_locks l')
                ->join('tpms_devices d', 'd.id = l.device_id')
                ->like('d.token', 'stress-', 'after')
                ->countAllResults();

            CLI::write('TPMS Phase 8 Stress Seed', 'green');
            CLI::write('Created this run : ' . $toCreate);
            CLI::write('Stress devices   : ' . $finalCount);
            CLI::write('Runtime locks    : ' . $lockCount);
            CLI::write('Slot assignment  : none (polling-only seed)');
            CLI::newLine();
            CLI::write('Gunakan manifest dengan --stress-only supaya TPMS asli tidak ikut stress test.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Stress seed gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
