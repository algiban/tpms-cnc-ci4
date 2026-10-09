<?php

namespace App\Commands;

use App\Services\TpmsRetentionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsStorageStatus extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:storage-status';
    protected $description = 'Tampilkan ukuran tabel dan jumlah data yang sudah melewati retention Phase 6.';
    protected $usage = 'tpms:storage-status';

    public function run(array $params)
    {
        try {
            $db = db_connect();
            $database = (string) $db->getDatabase();
            $inspection = (new TpmsRetentionService($db))->inspect();

            $sizeRows = $db->query(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? '
                . 'AND TABLE_NAME IN (?,?,?,?,?,?,?,?)',
                [
                    $database,
                    'production_api_events', 'tool_lifetime_logs', 'tpms_logs', 'tool_logs',
                    'machine_logs', 'production_cycles', 'production_runtime_intervals', 'production_alarms',
                ]
            )->getResultArray();

            $sizes = [];
            foreach ($sizeRows as $row) {
                $sizes[$row['TABLE_NAME']] = $row;
            }

            CLI::write('TPMS Phase 6 Storage Status', 'green');
            CLI::write('Database: ' . $database);
            CLI::newLine();

            $rows = [];
            $seen = [];
            foreach ($inspection['targets'] as $target) {
                $table = $target['table'];
                $seen[$table] = true;
                $size = $sizes[$table] ?? [];
                $bytes = (int) ($size['DATA_LENGTH'] ?? 0) + (int) ($size['INDEX_LENGTH'] ?? 0);
                $rows[] = [
                    $table,
                    number_format((int) ($size['TABLE_ROWS'] ?? $target['total_rows'])),
                    number_format($bytes / 1024 / 1024, 2) . ' MB',
                    $target['enabled'] ? $target['days'] . ' d' : 'KEEP',
                    number_format((int) $target['eligible_rows']),
                    $target['oldest'] ?? '-',
                ];
            }

            foreach (['production_cycles', 'production_runtime_intervals', 'production_alarms'] as $table) {
                if (isset($seen[$table]) || ! isset($sizes[$table])) {
                    continue;
                }
                $size = $sizes[$table];
                $bytes = (int) ($size['DATA_LENGTH'] ?? 0) + (int) ($size['INDEX_LENGTH'] ?? 0);
                $rows[] = [
                    $table,
                    number_format((int) ($size['TABLE_ROWS'] ?? 0)),
                    number_format($bytes / 1024 / 1024, 2) . ' MB',
                    'KEEP',
                    '-',
                    '-',
                ];
            }

            CLI::table($rows, ['Table', 'Rows~', 'Size', 'Retention', 'Eligible', 'Oldest']);
            CLI::newLine();
            CLI::write('Catatan: production_cycles/runtime/alarm tidak dihapus otomatis pada Phase 6 karena merupakan histori produksi.', 'yellow');
            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Storage status gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
