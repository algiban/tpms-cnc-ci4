<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsServerCheck extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:server-check';
    protected $description = 'Audit konfigurasi runtime PHP/CI4/MySQL untuk deployment TPMS production.';
    protected $usage = 'tpms:server-check';

    public function run(array $params)
    {
        $rows = [];
        $warn = 0;
        $fail = 0;

        $add = static function (string $name, string $value, string $status, string $note = '') use (&$rows, &$warn, &$fail): void {
            $rows[] = [$name, $value, $status, $note];
            if ($status === 'WARN') {
                $warn++;
            } elseif ($status === 'FAIL') {
                $fail++;
            }
        };

        $isProduction = ENVIRONMENT === 'production';
        $add('CI environment', ENVIRONMENT, $isProduction ? 'OK' : 'WARN', $isProduction ? '' : 'Gunakan production di server live.');

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $add('PHP', PHP_VERSION, $phpOk ? 'OK' : 'FAIL', 'Project membutuhkan PHP >= 8.2.');

        foreach (['mysqli', 'mbstring', 'intl', 'json'] as $extension) {
            $loaded = extension_loaded($extension);
            $add('ext-' . $extension, $loaded ? 'loaded' : 'missing', $loaded ? 'OK' : 'FAIL');
        }

        $opcache = extension_loaded('Zend OPcache') || extension_loaded('opcache');
        $opcacheEnabled = $opcache && $this->boolIni('opcache.enable');
        $add('OPcache', $opcacheEnabled ? 'enabled' : 'disabled', $opcacheEnabled ? 'OK' : 'WARN', 'Aktifkan pada PHP-FPM production.');

        if ($opcache) {
            $validate = (string) ini_get('opcache.validate_timestamps');
            $add('OPcache timestamp validation', $validate === '' ? 'N/A' : $validate, $isProduction && $validate !== '0' ? 'WARN' : 'OK', 'Production immutable deploy idealnya 0.');
        }

        $apcu = extension_loaded('apcu');
        $add('APCu', $apcu ? 'loaded' : 'not loaded', $apcu ? 'OK' : 'WARN', 'Opsional tetapi direkomendasikan untuk cache satu server.');

        $memory = (string) ini_get('memory_limit');
        $add('PHP memory_limit', $memory !== '' ? $memory : 'N/A', 'OK');

        $perfEnabled = $this->envBool('TPMS_PERF_ENABLED', false);
        $add('TPMS profiler', $perfEnabled ? 'enabled' : 'disabled', $isProduction && $perfEnabled ? 'WARN' : 'OK', 'Matikan saat baseline selesai.');

        $cacheConfig = config('Cache');
        $add('CI cache handler', (string) $cacheConfig->handler, $cacheConfig->handler === 'dummy' ? 'WARN' : 'OK');

        $dbConfig = config('Database');
        $dbDebug = (bool) ($dbConfig->default['DBDebug'] ?? false);
        $add('Database DBDebug', $dbDebug ? 'true' : 'false', $isProduction && $dbDebug ? 'WARN' : 'OK');

        foreach ([WRITEPATH, WRITEPATH . 'cache', WRITEPATH . 'logs', WRITEPATH . 'session'] as $path) {
            $ok = is_dir($path) && is_writable($path);
            $add('Writable ' . basename(rtrim($path, DIRECTORY_SEPARATOR)), $ok ? 'writable' : 'NOT writable', $ok ? 'OK' : 'FAIL', $path);
        }

        try {
            $db = db_connect();
            $version = $db->query('SELECT VERSION() AS version')->getRowArray();
            $add('Database connection', (string) ($version['version'] ?? 'connected'), 'OK');

            $vars = $this->dbVariables($db, [
                'max_connections',
                'innodb_buffer_pool_size',
                'slow_query_log',
                'long_query_time',
                'tmp_table_size',
                'max_heap_table_size',
            ]);

            $maxConnections = (int) ($vars['max_connections'] ?? 0);
            $add('DB max_connections', (string) $maxConnections, $maxConnections >= 100 ? 'OK' : 'WARN', 'Target awal 100-150, jangan dinaikkan berlebihan.');

            $bufferBytes = (int) ($vars['innodb_buffer_pool_size'] ?? 0);
            $bufferGiB = $bufferBytes > 0 ? round($bufferBytes / 1024 / 1024 / 1024, 2) . ' GiB' : 'N/A';
            $add('InnoDB buffer pool', $bufferGiB, $bufferBytes >= 1024 * 1024 * 1024 ? 'OK' : 'WARN', 'Pada server 8 GB gabungan, sekitar 3 GB adalah titik awal.');

            $slow = strtolower((string) ($vars['slow_query_log'] ?? 'off'));
            $add('Slow query log', $slow, in_array($slow, ['on', '1'], true) ? 'OK' : 'WARN');
            $add('Long query time', (string) ($vars['long_query_time'] ?? 'N/A') . ' s', 'OK');
        } catch (Throwable $e) {
            $add('Database connection', 'failed', 'FAIL', $e->getMessage());
        }

        CLI::write('TPMS Phase 7 Server Check', 'green');
        CLI::table($rows, ['Check', 'Value', 'Status', 'Note']);
        CLI::newLine();
        CLI::write('Warnings: ' . $warn . ' | Failures: ' . $fail, $fail > 0 ? 'red' : ($warn > 0 ? 'yellow' : 'green'));
        CLI::write('Catatan: command dijalankan via CLI; PHP-FPM dapat memakai php.ini/pool yang berbeda. Verifikasi juga `php-fpm -i`/status FPM di server.');

        return $fail > 0 ? EXIT_ERROR : EXIT_SUCCESS;
    }

    private function boolIni(string $key): bool
    {
        return filter_var((string) ini_get($key), FILTER_VALIDATE_BOOLEAN);
    }

    private function envBool(string $key, bool $default): bool
    {
        $value = env($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @return array<string, string> */
    private function dbVariables($db, array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            try {
                $row = $db->query('SHOW VARIABLES LIKE ?', [$name])->getRowArray();
                $out[$name] = (string) ($row['Value'] ?? $row['VARIABLE_VALUE'] ?? '');
            } catch (Throwable) {
                $out[$name] = '';
            }
        }
        return $out;
    }
}
