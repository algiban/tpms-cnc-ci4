<?php

namespace App\Commands;

use App\Services\TpmsRuntimeLockService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsLockStatus extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:lock-status';
    protected $description = 'Verifikasi kesiapan per-device runtime lock Phase 1.';
    protected $usage = 'tpms:lock-status';

    public function run(array $params)
    {
        try {
            $db = db_connect();

            if (! $db->tableExists('tpms_device_runtime_locks')) {
                CLI::error('Tabel tpms_device_runtime_locks belum ada. Jalankan: php spark migrate');
                return EXIT_ERROR;
            }

            // Repair missing rows without taking FOR UPDATE locks. This is safe
            // and mirrors the lazy row creation used by the runtime service.
            foreach ($db->table('tpms_devices')->select('id')->orderBy('id')->get()->getResultArray() as $row) {
                (new TpmsRuntimeLockService($db))->ensureDevice((int) $row['id']);
            }

            $deviceCount = $db->table('tpms_devices')->countAllResults();
            $lockCount = $db->table('tpms_device_runtime_locks')->countAllResults();
            $missing = $db->query(
                'SELECT d.id, d.mac_address
                 FROM tpms_devices d
                 LEFT JOIN tpms_device_runtime_locks l ON l.device_id = d.id
                 WHERE l.device_id IS NULL
                 ORDER BY d.id'
            )->getResultArray();

            CLI::write('TPMS Phase 1 Runtime Lock Status', 'green');
            CLI::write('Registered devices : ' . $deviceCount);
            CLI::write('Runtime lock rows  : ' . $lockCount);
            CLI::write('Missing lock rows  : ' . count($missing));

            if ($missing !== []) {
                CLI::newLine();
                CLI::table(
                    array_map(static fn (array $row): array => [
                        (string) $row['id'],
                        (string) $row['mac_address'],
                    ], $missing),
                    ['Device ID', 'MAC']
                );
                return EXIT_ERROR;
            }

            CLI::write('Status             : READY', 'green');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Gagal memeriksa runtime lock: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
