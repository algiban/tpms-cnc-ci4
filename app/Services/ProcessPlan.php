<?php
namespace App\Services;

use DomainException;

final class ProcessPlan
{
    public const EFFECTIVE_SHIFT_MS = 25200000;

    public static function calculate(int $machineMs, int $loadingMs, int $shifts = 3): array
    {
        if ($machineMs < 0 || $loadingMs < 0 || $machineMs + $loadingMs <= 0) {
            throw new DomainException('Machine Time dan Loading Time harus nonnegatif dengan Cycle Time lebih dari nol.', 422);
        }
        if ($shifts < 1 || $shifts > 3) {
            throw new DomainException('Shift per day harus 1, 2, atau 3.', 422);
        }
        $cycle = $machineMs + $loadingMs;
        $plan = intdiv(self::EFFECTIVE_SHIFT_MS, $cycle);
        if ($plan < 1) {
            throw new DomainException('Cycle Time melebihi waktu efektif satu shift (7 jam).', 422);
        }
        return ['cycle_time_ms' => $cycle, 'plan_per_shift' => $plan, 'daily_plan' => $plan * $shifts, 'shifts_per_day' => $shifts, 'effective_shift_seconds' => 25200, 'rounding' => 'floor'];
    }

    public static function milliseconds(mixed $seconds, string $label): int
    {
        if ((!is_string($seconds) && !is_int($seconds) && !is_float($seconds)) || !is_numeric($seconds) || !is_finite((float)$seconds) || (float)$seconds < 0 || (float)$seconds > 25200) {
            throw new DomainException("{$label} harus angka antara 0 dan 25.200 detik.", 422);
        }
        return (int)round((float)$seconds * 1000);
    }
}
