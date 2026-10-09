<?php

namespace App\Commands;

use App\Services\TpmsRetentionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsRetention extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:retention';
    protected $description = 'Preview atau jalankan Phase 6 retention cleanup dalam batch kecil.';
    protected $usage = 'tpms:retention [--execute] [--target production_api_events] [--batch 5000]';
    protected $options = [
        '--execute' => 'Benar-benar hapus data eligible. Tanpa flag ini selalu dry-run.',
        '--batch' => 'Ukuran batch delete (100-50000). Default dari TPMS_RETENTION_BATCH_SIZE.',
        '--target' => 'Opsional: hanya production_api_events, tool_lifetime_increment, tpms_logs, tool_logs, atau machine_logs.',
    ];

    public function run(array $params)
    {
        try {
            $execute = CLI::getOption('execute') !== null;
            $batchRaw = CLI::getOption('batch');
            $batch = $batchRaw !== null && is_numeric($batchRaw) ? (int) $batchRaw : null;
            $targetRaw = CLI::getOption('target');
            $target = $targetRaw !== null && trim((string) $targetRaw) !== '' ? trim((string) $targetRaw) : null;

            $service = new TpmsRetentionService();
            $result = $service->prune($execute, $batch, $target);

            CLI::write('TPMS Phase 6 Retention', 'green');
            CLI::write('Mode       : ' . ($execute ? 'EXECUTE' : 'DRY-RUN (tidak ada data dihapus)'), $execute ? 'yellow' : 'cyan');
            CLI::write('Batch size : ' . $result['batch_size']);
            CLI::write('Target     : ' . ($result['only_target'] ?? 'ALL configured targets'));
            CLI::newLine();

            $rows = [];
            foreach ($result['targets'] as $target) {
                $rows[] = [
                    $target['selected'] ? 'YES' : 'NO',
                    $target['label'],
                    $target['enabled'] ? $target['days'] . ' d' : 'KEEP',
                    $target['cutoff'] ?? '-',
                    number_format((int) $target['total_rows']),
                    number_format((int) $target['eligible_rows']),
                    number_format((int) $target['deleted_rows']),
                    (string) $target['batches'],
                ];
            }

            CLI::table($rows, ['Run?', 'Target', 'Retention', 'Cutoff', 'Rows', 'Eligible', 'Deleted', 'Batches']);

            if (! $execute) {
                CLI::newLine();
                CLI::write('Tidak ada row yang dihapus. Review hasil di atas, lalu jalankan:', 'yellow');
                CLI::write('php spark tpms:retention --execute');
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Retention gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
