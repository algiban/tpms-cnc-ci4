<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

class TpmsDbBaseline extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:db-baseline';
    protected $description = 'Ambil snapshot read-only kondisi MySQL/MariaDB untuk Phase 0.';
    protected $usage = 'tpms:db-baseline';

    public function run(array $params)
    {
        try {
            $db = db_connect();
            $database = (string) $db->getDatabase();
            $versionRow = $db->query('SELECT VERSION() AS version')->getRowArray();

            $variables = $this->showMap($db, 'VARIABLES', [
                'max_connections',
                'innodb_buffer_pool_size',
                'slow_query_log',
                'long_query_time',
                'wait_timeout',
            ]);

            $status = $this->showMap($db, 'GLOBAL STATUS', [
                'Threads_connected',
                'Threads_running',
                'Connections',
                'Questions',
                'Slow_queries',
                'Created_tmp_tables',
                'Created_tmp_disk_tables',
                'Innodb_row_lock_current_waits',
                'Innodb_row_lock_waits',
                'Innodb_row_lock_time',
            ]);

            $tables = $db->query(
                'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE '
                . 'FROM information_schema.TABLES '
                . 'WHERE TABLE_SCHEMA = ? '
                . 'ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC',
                [$database]
            )->getResultArray();

            $lockWaitCount = null;
            try {
                $lockRow = $db->query(
                    'SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits'
                )->getRowArray();
                $lockWaitCount = (int) ($lockRow['total'] ?? 0);
            } catch (Throwable) {
                // MariaDB/older MySQL may expose lock waits differently.
            }

            $snapshot = [
                'generated_at' => date(DATE_ATOM),
                'database' => $database,
                'database_version' => (string) ($versionRow['version'] ?? 'unknown'),
                'variables' => $variables,
                'status' => $status,
                'current_data_lock_waits' => $lockWaitCount,
                'tables' => array_map(static function (array $row): array {
                    $data = (int) ($row['DATA_LENGTH'] ?? 0);
                    $index = (int) ($row['INDEX_LENGTH'] ?? 0);
                    return [
                        'table' => $row['TABLE_NAME'],
                        'rows_estimate' => (int) ($row['TABLE_ROWS'] ?? 0),
                        'data_mb' => round($data / 1024 / 1024, 2),
                        'index_mb' => round($index / 1024 / 1024, 2),
                        'total_mb' => round(($data + $index) / 1024 / 1024, 2),
                        'data_free_mb' => round(((int) ($row['DATA_FREE'] ?? 0)) / 1024 / 1024, 2),
                    ];
                }, $tables),
            ];

            $file = WRITEPATH . 'logs/tpms-db-baseline-' . date('Y-m-d-His') . '.json';
            file_put_contents(
                $file,
                json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            );

            CLI::write('TPMS Phase 0 Database Baseline', 'green');
            CLI::write('Database: ' . $database);
            CLI::write('Version : ' . $snapshot['database_version']);
            CLI::write('Output  : ' . $file);
            CLI::newLine();

            CLI::table(
                array_map(
                    static fn (string $key, mixed $value): array => [$key, (string) $value],
                    array_keys($variables),
                    array_values($variables)
                ),
                ['Variable', 'Value']
            );

            CLI::newLine();
            CLI::table(
                array_map(
                    static fn (string $key, mixed $value): array => [$key, (string) $value],
                    array_keys($status),
                    array_values($status)
                ),
                ['Status', 'Value']
            );

            CLI::newLine();
            CLI::write('Current data lock waits: ' . ($lockWaitCount === null ? 'N/A' : (string) $lockWaitCount));
            CLI::newLine();

            $topTables = array_slice($snapshot['tables'], 0, 20);
            CLI::table(
                array_map(static fn (array $t): array => [
                    $t['table'],
                    $t['rows_estimate'],
                    $t['data_mb'],
                    $t['index_mb'],
                    $t['total_mb'],
                ], $topTables),
                ['Table', 'Rows~', 'Data MB', 'Index MB', 'Total MB']
            );
        } catch (Throwable $e) {
            CLI::error('Gagal mengambil DB baseline: ' . $e->getMessage());
        }
    }

    private function showMap($db, string $kind, array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $row = $db->query('SHOW ' . $kind . ' LIKE ?', [$name])->getRowArray();
            $result[$name] = $row['Value'] ?? $row['VARIABLE_VALUE'] ?? 'N/A';
        }
        return $result;
    }
}
