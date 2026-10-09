<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsCapacitySnapshot extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:capacity-snapshot';
    protected $description = 'Snapshot metrik MySQL untuk membandingkan kondisi sebelum/sesudah Phase 8 load test.';
    protected $usage = 'tpms:capacity-snapshot [--json] [--output writable/stress/capacity.json]';
    protected $options = [
        '--json' => 'Cetak JSON ke stdout.',
        '--output' => 'Opsional simpan snapshot JSON ke file.',
    ];

    public function run(array $params)
    {
        try {
            $db = db_connect();
            $statusNames = [
                'Uptime', 'Threads_connected', 'Threads_running', 'Connections', 'Aborted_connects',
                'Questions', 'Queries', 'Slow_queries', 'Created_tmp_tables', 'Created_tmp_disk_tables',
                'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests',
                'Innodb_row_lock_current_waits', 'Innodb_row_lock_time', 'Innodb_row_lock_waits',
                'Bytes_received', 'Bytes_sent',
            ];
            $variableNames = [
                'max_connections', 'innodb_buffer_pool_size', 'slow_query_log', 'long_query_time',
            ];

            $status = $this->readNamed($db, 'SHOW GLOBAL STATUS LIKE ?', $statusNames);
            $variables = $this->readNamed($db, 'SHOW VARIABLES LIKE ?', $variableNames);

            $dbNameRow = $db->query('SELECT DATABASE() AS db_name')->getRowArray();
            $dbName = (string) ($dbNameRow['db_name'] ?? '');
            $size = null;
            if ($dbName !== '') {
                $row = $db->query(
                    'SELECT COALESCE(SUM(data_length + index_length),0) AS bytes FROM information_schema.tables WHERE table_schema = ?',
                    [$dbName]
                )->getRowArray();
                $size = (int) ($row['bytes'] ?? 0);
            }

            $snapshot = [
                'captured_at' => date(DATE_ATOM),
                'database' => $dbName,
                'database_bytes' => $size,
                'status' => $status,
                'variables' => $variables,
            ];

            $outputRaw = CLI::getOption('output');
            if ($outputRaw !== null && trim((string) $outputRaw) !== '') {
                $output = trim((string) $outputRaw);
                $dir = dirname($output);
                if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
                    throw new \RuntimeException('Tidak dapat membuat directory: ' . $dir);
                }
                file_put_contents($output, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
            }

            if (CLI::getOption('json') !== null) {
                CLI::write(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                return EXIT_SUCCESS;
            }

            $rows = [];
            foreach ($status as $key => $value) {
                $rows[] = [$key, (string) $value];
            }

            CLI::write('TPMS Phase 8 Capacity Snapshot', 'green');
            CLI::write('Database size: ' . ($size !== null ? $this->bytes($size) : 'N/A'));
            CLI::table($rows, ['MySQL global status', 'Value']);
            CLI::newLine();
            CLI::table(array_map(static fn ($k, $v): array => [$k, (string) $v], array_keys($variables), array_values($variables)), ['Variable', 'Value']);
            CLI::newLine();
            CLI::write('Ambil snapshot BEFORE dan AFTER stage. Counter global seperti Queries/Slow_queries/row_lock_waits dibandingkan sebagai delta.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Capacity snapshot gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }

    /** @return array<string, string|int> */
    private function readNamed($db, string $sql, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $row = $db->query($sql, [$name])->getRowArray();
            $value = $row['Value'] ?? $row['VARIABLE_VALUE'] ?? $row['VARIABLE_VALUE'] ?? null;
            if ($value === null) {
                $values = array_values($row ?? []);
                $value = $values[1] ?? '';
            }
            $out[$name] = is_numeric($value) ? (int) $value : (string) $value;
        }
        return $out;
    }

    private function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) $bytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }
        return number_format($value, 2) . ' ' . $units[$unit];
    }
}
