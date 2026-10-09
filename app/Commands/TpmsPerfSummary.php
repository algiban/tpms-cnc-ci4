<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class TpmsPerfSummary extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:perf-summary';
    protected $description = 'Ringkas log Phase 0 TPMS: latency, query count, SQL time, memory, dan error rate per endpoint.';
    protected $usage = 'tpms:perf-summary [YYYY-MM-DD|path/to/file.jsonl]';

    public function run(array $params)
    {
        $target = $params[0] ?? date('Y-m-d');
        $file = is_file($target)
            ? $target
            : WRITEPATH . 'logs/tpms-performance-' . $target . '.jsonl';

        if (! is_file($file)) {
            CLI::error('Log performance tidak ditemukan: ' . $file);
            return;
        }

        $groups = [];
        $patterns = [];
        $totalLines = 0;
        $invalidLines = 0;

        $fh = fopen($file, 'rb');
        if ($fh === false) {
            CLI::error('Tidak dapat membuka log: ' . $file);
            return;
        }

        while (($line = fgets($fh)) !== false) {
            $totalLines++;
            $row = json_decode(trim($line), true);
            if (! is_array($row)) {
                $invalidLines++;
                continue;
            }

            $path = (string) ($row['path'] ?? 'unknown');
            $method = (string) ($row['method'] ?? '?');
            $key = $method . ' ' . $path;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'durations' => [],
                    'queries' => [],
                    'writes' => [],
                    'sql' => [],
                    'memory' => [],
                    'errors4' => 0,
                    'errors5' => 0,
                    'slowQueryCount' => 0,
                ];
            }

            $g =& $groups[$key];
            $g['durations'][] = (float) ($row['duration_ms'] ?? 0);
            $g['queries'][] = (float) ($row['queries'] ?? 0);
            $g['writes'][] = (float) ($row['write_queries'] ?? 0);
            $g['sql'][] = (float) ($row['sql_ms'] ?? 0);
            $g['memory'][] = (float) ($row['peak_memory_mb'] ?? 0);
            $status = (int) ($row['status'] ?? 0);
            if ($status >= 500) {
                $g['errors5']++;
            } elseif ($status >= 400) {
                $g['errors4']++;
            }
            $g['slowQueryCount'] += count($row['slow_queries'] ?? []);
            unset($g);

            foreach (($row['query_patterns'] ?? []) as $pattern) {
                if (! is_array($pattern) || empty($pattern['sql'])) {
                    continue;
                }
                $sql = (string) $pattern['sql'];
                $keySql = sha1($sql);
                if (! isset($patterns[$keySql])) {
                    $patterns[$keySql] = ['count' => 0, 'total_ms' => 0.0, 'sql' => $sql];
                }
                $patterns[$keySql]['count'] += (int) ($pattern['count'] ?? 0);
                $patterns[$keySql]['total_ms'] += (float) ($pattern['total_ms'] ?? 0);
            }
        }
        fclose($fh);

        if ($groups === []) {
            CLI::error('Tidak ada record performance yang valid.');
            return;
        }

        ksort($groups);
        $rows = [];
        foreach ($groups as $endpoint => $g) {
            sort($g['durations']);
            $n = count($g['durations']);
            $rows[] = [
                $endpoint,
                $n,
                $this->fmt($this->avg($g['durations'])),
                $this->fmt($this->percentile($g['durations'], 0.95)),
                $this->fmt($this->percentile($g['durations'], 0.99)),
                $this->fmt(max($g['durations'])),
                $this->fmt($this->avg($g['queries']), 1),
                $this->fmt($this->avg($g['writes']), 1),
                $this->fmt($this->avg($g['sql'])),
                $this->fmt(max($g['memory']), 1),
                $g['errors4'],
                $g['errors5'],
                $g['slowQueryCount'],
            ];
        }

        CLI::write('TPMS Phase 0 Performance Summary', 'green');
        CLI::write('Source: ' . $file);
        CLI::write('Records: ' . ($totalLines - $invalidLines) . ' valid, ' . $invalidLines . ' invalid');
        CLI::newLine();

        CLI::table($rows, [
            'Endpoint', 'N', 'Avg ms', 'P95', 'P99', 'Max',
            'Avg Q', 'Avg WQ', 'Avg SQL ms', 'Peak MB', '4xx', '5xx', 'Slow Q',
        ]);

        CLI::newLine();
        CLI::write('Interpretasi awal:', 'yellow');
        CLI::write('- Avg/P95/P99 = latency request server-side.');
        CLI::write('- Avg Q = rata-rata total query; Avg WQ = rata-rata query write.');
        CLI::write('- Avg SQL ms = waktu yang habis di database per request.');
        CLI::write('- Slow Q = jumlah query yang melewati TPMS_PERF_SLOW_QUERY_MS.');

        if ($patterns !== []) {
            uasort(
                $patterns,
                static fn (array $a, array $b): int => $b['total_ms'] <=> $a['total_ms']
            );
            $patternRows = [];
            foreach (array_slice($patterns, 0, 15, true) as $pattern) {
                $count = max(1, (int) $pattern['count']);
                $patternRows[] = [
                    $count,
                    $this->fmt((float) $pattern['total_ms']),
                    $this->fmt((float) $pattern['total_ms'] / $count),
                    substr((string) $pattern['sql'], 0, 120),
                ];
            }

            CLI::newLine();
            CLI::write('Top SQL patterns (cumulative DB time)', 'green');
            CLI::table($patternRows, ['Count', 'Total ms', 'Avg ms', 'Normalized SQL']);
        }
    }

    private function avg(array $values): float
    {
        return $values === [] ? 0.0 : array_sum($values) / count($values);
    }

    private function percentile(array $sortedValues, float $percentile): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }
        $index = (int) ceil($percentile * $count) - 1;
        return (float) $sortedValues[max(0, min($count - 1, $index))];
    }

    private function fmt(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
