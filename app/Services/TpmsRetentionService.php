<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

final class TpmsRetentionService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * Retention policy. A value <= 0 means "keep forever" for that target.
     * production_api_events is deliberately finite because it is only the
     * idempotency/retry window, not the production audit trail.
     */
    public function policy(?int $batchOverride = null): array
    {
        $batch = $batchOverride ?? $this->envInt('TPMS_RETENTION_BATCH_SIZE', 5000);
        $batch = max(100, min(50000, $batch));

        return [
            'batch_size' => $batch,
            'targets' => [
                'production_api_events' => [
                    'table' => 'production_api_events',
                    'time_column' => 'created_at',
                    'days' => $this->envInt('TPMS_RETENTION_API_EVENTS_DAYS', 14),
                    'label' => 'ESP idempotency events',
                    'where' => [],
                ],
                'tool_lifetime_increment' => [
                    'table' => 'tool_lifetime_logs',
                    'time_column' => 'occurred_at',
                    'days' => $this->envInt('TPMS_RETENTION_LIFETIME_INCREMENT_DAYS', 30),
                    'label' => 'Per-part lifetime increment logs',
                    'where' => ['event_type' => 'production_increment'],
                ],
                'tpms_logs' => [
                    'table' => 'tpms_logs',
                    'time_column' => 'occurred_at',
                    'days' => $this->envInt('TPMS_RETENTION_OPERATIONAL_LOGS_DAYS', 365),
                    'label' => 'TPMS operational logs',
                    'where' => [],
                ],
                'tool_logs' => [
                    'table' => 'tool_logs',
                    'time_column' => 'occurred_at',
                    'days' => $this->envInt('TPMS_RETENTION_OPERATIONAL_LOGS_DAYS', 365),
                    'label' => 'Tool operational logs',
                    'where' => [],
                ],
                'machine_logs' => [
                    'table' => 'machine_logs',
                    'time_column' => 'occurred_at',
                    'days' => $this->envInt('TPMS_RETENTION_OPERATIONAL_LOGS_DAYS', 365),
                    'label' => 'Machine operational logs',
                    'where' => [],
                ],
            ],
        ];
    }

    public function inspect(?int $batchOverride = null): array
    {
        $policy = $this->policy($batchOverride);
        $rows = [];

        foreach ($policy['targets'] as $key => $target) {
            if (! $this->db->tableExists($target['table'])) {
                $rows[$key] = [
                    ...$target,
                    'enabled' => false,
                    'cutoff' => null,
                    'total_rows' => 0,
                    'eligible_rows' => 0,
                    'oldest' => null,
                    'newest' => null,
                    'missing_table' => true,
                ];
                continue;
            }

            $days = (int) $target['days'];
            $cutoff = $days > 0
                ? (new DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d H:i:s')
                : null;

            $total = $this->countTarget($target, null);
            $eligible = $cutoff !== null ? $this->countTarget($target, $cutoff) : 0;

            $rangeBuilder = $this->db->table($target['table'])
                ->select("MIN({$target['time_column']}) AS oldest, MAX({$target['time_column']}) AS newest", false);
            $this->applyExtraWhere($rangeBuilder, $target['where']);
            $range = $rangeBuilder->get()->getRowArray() ?: [];

            $rows[$key] = [
                ...$target,
                'enabled' => $days > 0,
                'cutoff' => $cutoff,
                'total_rows' => $total,
                'eligible_rows' => $eligible,
                'oldest' => $range['oldest'] ?? null,
                'newest' => $range['newest'] ?? null,
                'missing_table' => false,
            ];
        }

        return [
            'batch_size' => $policy['batch_size'],
            'targets' => $rows,
        ];
    }

    /**
     * Delete old rows in small committed batches. This deliberately avoids a
     * single huge DELETE that could hold locks and generate a large undo log.
     */
    public function prune(bool $execute = false, ?int $batchOverride = null, ?string $onlyTarget = null): array
    {
        $inspection = $this->inspect($batchOverride);
        $batchSize = (int) $inspection['batch_size'];
        $result = [];

        if ($onlyTarget !== null && ! array_key_exists($onlyTarget, $inspection['targets'])) {
            throw new RuntimeException('Target retention tidak dikenal: ' . $onlyTarget);
        }

        foreach ($inspection['targets'] as $key => $target) {
            $deleted = 0;
            $selected = $onlyTarget === null || $onlyTarget === $key;
            $batches = 0;

            if (
                $execute
                && $selected
                && ! $target['missing_table']
                && $target['enabled']
                && $target['cutoff'] !== null
            ) {
                while (true) {
                    $ids = $this->eligibleIds($target, $target['cutoff'], $batchSize);
                    if ($ids === []) {
                        break;
                    }

                    $this->db->transBegin();
                    try {
                        $ok = $this->db->table($target['table'])
                            ->whereIn('id', $ids)
                            ->delete();

                        if (! $ok || $this->db->transStatus() === false) {
                            throw new RuntimeException('Delete batch gagal pada ' . $target['table']);
                        }

                        $this->db->transCommit();
                    } catch (\Throwable $e) {
                        $this->db->transRollback();
                        throw $e;
                    }

                    $deleted += count($ids);
                    $batches++;

                    if (count($ids) < $batchSize) {
                        break;
                    }
                }
            }

            $result[$key] = [
                ...$target,
                'deleted_rows' => $deleted,
                'batches' => $batches,
                'dry_run' => ! $execute,
                'selected' => $selected,
            ];
        }

        return [
            'execute' => $execute,
            'only_target' => $onlyTarget,
            'batch_size' => $batchSize,
            'targets' => $result,
        ];
    }

    private function eligibleIds(array $target, string $cutoff, int $limit): array
    {
        $builder = $this->db->table($target['table'])
            ->select('id')
            ->where($target['time_column'] . ' <', $cutoff);
        $this->applyExtraWhere($builder, $target['where']);

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $builder
                ->orderBy($target['time_column'], 'ASC')
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray()
        );
    }

    private function countTarget(array $target, ?string $cutoff): int
    {
        $builder = $this->db->table($target['table']);
        $this->applyExtraWhere($builder, $target['where']);
        if ($cutoff !== null) {
            $builder->where($target['time_column'] . ' <', $cutoff);
        }
        return (int) $builder->countAllResults();
    }

    private function applyExtraWhere($builder, array $where): void
    {
        foreach ($where as $column => $value) {
            $builder->where($column, $value);
        }
    }

    private function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim((string) $value) === '' || ! is_numeric($value)) {
            return $default;
        }
        return (int) $value;
    }
}
