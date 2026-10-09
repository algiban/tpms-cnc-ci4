<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsStressCleanup extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:stress-cleanup';
    protected $description = 'Hapus hanya TPMS dummy hasil tpms:stress-seed.';
    protected $usage = 'tpms:stress-cleanup [--execute]';
    protected $options = [
        '--execute' => 'Benar-benar menghapus. Tanpa flag ini hanya preview.',
    ];

    public function run(array $params)
    {
        try {
            if (ENVIRONMENT === 'production') {
                CLI::error('Ditolak: stress cleanup tidak boleh dijalankan saat CI_ENVIRONMENT=production.');
                return EXIT_ERROR;
            }

            $db = db_connect();
            $count = (int) $db->table('tpms_devices')
                ->like('token', 'stress-', 'after')
                ->countAllResults();

            CLI::write('TPMS Phase 8 Stress Cleanup', 'green');
            CLI::write('Stress devices ditemukan: ' . $count);

            if (CLI::getOption('execute') === null) {
                CLI::write('DRY RUN: tidak ada data yang dihapus.', 'yellow');
                CLI::write('Untuk menghapus: php spark tpms:stress-cleanup --execute');
                return EXIT_SUCCESS;
            }

            if ($count === 0) {
                CLI::write('Tidak ada stress device yang perlu dihapus.');
                return EXIT_SUCCESS;
            }

            // Seed polling tidak membuat production/slot. FK runtime lock ON DELETE CASCADE.
            $db->transBegin();
            $db->table('tpms_devices')->like('token', 'stress-', 'after')->delete();
            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new \RuntimeException('Cleanup transaction gagal.');
            }
            $db->transCommit();

            CLI::write('Deleted: ' . $count, 'green');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Stress cleanup gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
