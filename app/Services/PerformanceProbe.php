<?php

namespace App\Services;

use CodeIgniter\Database\QueryInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Lightweight request/SQL profiler for Phase 0 performance baselining.
 *
 * It is intentionally isolated from production logic. Enable only while taking
 * a baseline by setting TPMS_PERF_ENABLED=true in .env.
 */
class PerformanceProbe
{
    private static bool $active = false;
    private static float $startedAt = 0.0;
    private static int $startMemory = 0;
    private static int $queryCount = 0;
    private static int $readQueryCount = 0;
    private static int $writeQueryCount = 0;
    private static float $sqlDurationMs = 0.0;
    private static array $slowQueries = [];
    private static array $queryPatterns = [];
    private static string $requestId = '';

    public static function start(RequestInterface $request): void
    {
        self::reset();

        if (! self::envBool('TPMS_PERF_ENABLED', false)) {
            return;
        }

        self::$active = true;
        self::$startedAt = microtime(true);
        self::$startMemory = memory_get_usage(true);
        self::$requestId = self::makeRequestId($request);
    }

    public static function recordQuery(QueryInterface $query): void
    {
        if (! self::$active) {
            return;
        }

        $durationMs = ((float) $query->getDuration(6)) * 1000;
        self::$queryCount++;
        self::$sqlDurationMs += $durationMs;

        if ($query->isWriteType()) {
            self::$writeQueryCount++;
        } else {
            self::$readQueryCount++;
        }

        $normalizedSql = self::normalizeSql($query->getOriginalQuery());
        $patternKey = sha1($normalizedSql);
        if (! isset(self::$queryPatterns[$patternKey])) {
            self::$queryPatterns[$patternKey] = [
                'count' => 0,
                'total_ms' => 0.0,
                'sql' => $normalizedSql,
            ];
        }
        self::$queryPatterns[$patternKey]['count']++;
        self::$queryPatterns[$patternKey]['total_ms'] += $durationMs;

        $slowThresholdMs = max(0.0, (float) env('TPMS_PERF_SLOW_QUERY_MS', 50));
        if ($durationMs < $slowThresholdMs) {
            return;
        }

        self::$slowQueries[] = [
            'ms' => round($durationMs, 3),
            'sql' => $normalizedSql,
        ];

        usort(
            self::$slowQueries,
            static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']
        );

        $limit = max(1, (int) env('TPMS_PERF_SLOW_QUERY_LIMIT', 5));
        if (count(self::$slowQueries) > $limit) {
            self::$slowQueries = array_slice(self::$slowQueries, 0, $limit);
        }
    }

    public static function finish(RequestInterface $request, ResponseInterface $response): void
    {
        if (! self::$active) {
            return;
        }

        $durationMs = (microtime(true) - self::$startedAt) * 1000;
        $peakMemoryMb = memory_get_peak_usage(true) / 1024 / 1024;
        $memoryDeltaMb = (memory_get_usage(true) - self::$startMemory) / 1024 / 1024;
        $status = $response->getStatusCode();

        $queryPatterns = array_values(self::$queryPatterns);
        usort(
            $queryPatterns,
            static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']
        );
        $queryPatternLimit = max(1, (int) env('TPMS_PERF_QUERY_PATTERN_LIMIT', 8));
        $queryPatterns = array_slice($queryPatterns, 0, $queryPatternLimit);
        foreach ($queryPatterns as &$pattern) {
            $pattern['total_ms'] = round((float) $pattern['total_ms'], 3);
            $pattern['avg_ms'] = round(
                $pattern['count'] > 0 ? $pattern['total_ms'] / $pattern['count'] : 0,
                3
            );
        }
        unset($pattern);

        $record = [
            'ts' => date(DATE_ATOM),
            'request_id' => self::$requestId,
            'method' => strtoupper((string) $request->getMethod()),
            'path' => '/' . ltrim($request->getUri()->getPath(), '/'),
            'status' => $status,
            'duration_ms' => round($durationMs, 3),
            'queries' => self::$queryCount,
            'read_queries' => self::$readQueryCount,
            'write_queries' => self::$writeQueryCount,
            'sql_ms' => round(self::$sqlDurationMs, 3),
            'app_ms' => round(max(0.0, $durationMs - self::$sqlDurationMs), 3),
            'peak_memory_mb' => round($peakMemoryMb, 3),
            'memory_delta_mb' => round($memoryDeltaMb, 3),
            'slow_queries' => self::$slowQueries,
            'query_patterns' => $queryPatterns,
        ];

        $logAll = self::envBool('TPMS_PERF_LOG_ALL', true);
        $slowRequestMs = max(0.0, (float) env('TPMS_PERF_SLOW_REQUEST_MS', 250));
        $shouldLog = $logAll || $status >= 400 || $durationMs >= $slowRequestMs;

        if ($shouldLog) {
            self::appendRecord($record);
        }

        // Helpful for manual inspection/curl without changing response JSON.
        if (self::envBool('TPMS_PERF_RESPONSE_HEADERS', true)) {
            $response->setHeader('X-TPMS-Perf-Id', self::$requestId);
            $response->setHeader('X-TPMS-Perf-Ms', number_format($durationMs, 2, '.', ''));
            $response->setHeader('X-TPMS-Perf-Queries', (string) self::$queryCount);
            $response->setHeader('X-TPMS-Perf-Sql-Ms', number_format(self::$sqlDurationMs, 2, '.', ''));
        }

        self::reset();
    }

    private static function appendRecord(array $record): void
    {
        $directory = WRITEPATH . 'logs';
        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $file = $directory . DIRECTORY_SEPARATOR
            . 'tpms-performance-' . date('Y-m-d') . '.jsonl';

        try {
            $json = json_encode(
                $record,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            // Do not use LOCK_EX here: the profiler must not become a global
            // request serialization point during concurrency tests.
            @file_put_contents($file, $json . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // Profiling must never break a production request.
            log_message('warning', 'TPMS performance profiler failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function makeRequestId(RequestInterface $request): string
    {
        $provided = trim((string) $request->getHeaderLine('X-Request-ID'));
        if ($provided !== '') {
            return substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '', $provided) ?: '', 0, 80);
        }

        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('perf', true));
        }
    }

    private static function normalizeSql(string $sql): string
    {
        $sql = preg_replace("/'(?:''|[^'])*'/s", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|[^"])*"/s', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);

        return substr($sql, 0, 700);
    }

    private static function envBool(string $name, bool $default): bool
    {
        $value = env($name, $default ? 'true' : 'false');

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private static function reset(): void
    {
        self::$active = false;
        self::$startedAt = 0.0;
        self::$startMemory = 0;
        self::$queryCount = 0;
        self::$readQueryCount = 0;
        self::$writeQueryCount = 0;
        self::$sqlDurationMs = 0.0;
        self::$slowQueries = [];
        self::$queryPatterns = [];
        self::$requestId = '';
    }
}
