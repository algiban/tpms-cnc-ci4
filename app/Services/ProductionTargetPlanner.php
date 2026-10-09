<?php

namespace App\Services;

use DomainException;

final class ProductionTargetPlanner
{
    public static function validateDurationDays(int $days): int
    {
        if ($days < 1 || $days > 3650) {
            throw new DomainException('target_duration_days harus antara 1 sampai 3650 hari.');
        }

        return $days;
    }

    public static function validateShiftsPerDay(int $shiftsPerDay): int
    {
        if ($shiftsPerDay < 1 || $shiftsPerDay > 3) {
            throw new DomainException('shifts_per_day harus 1, 2, atau 3.');
        }

        return $shiftsPerDay;
    }

    public static function totalPlannedShifts(int $durationDays, int $shiftsPerDay): int
    {
        self::validateDurationDays($durationDays);
        self::validateShiftsPerDay($shiftsPerDay);

        return $durationDays * $shiftsPerDay;
    }

    public static function remainingPlannedShifts(
        int $durationDays,
        int $shiftsPerDay,
        int $usedShifts
    ): int {
        $total = self::totalPlannedShifts($durationDays, $shiftsPerDay);

        return max(0, $total - max(0, $usedShifts));
    }

    /**
     * Hitung target GOOD untuk shift berikutnya.
     *
     * Target dibagi ulang setiap shift berdasarkan remaining overall GOOD target.
     * Jika rencana shift sudah habis tetapi target belum tercapai, seluruh sisa
     * target diberikan ke shift berikutnya agar production tetap bisa diselesaikan.
     */
    public static function nextShiftTarget(
        int $overallTarget,
        int $cumulativeGood,
        int $durationDays,
        int $shiftsPerDay,
        int $usedShifts
    ): int {
        $remainingGood = ProductionQuantity::remaining($overallTarget, $cumulativeGood);
        if ($remainingGood <= 0) {
            return 0;
        }

        $remainingPlanned = self::remainingPlannedShifts(
            $durationDays,
            $shiftsPerDay,
            $usedShifts
        );

        // Planned window habis: jangan blokir production, kejar seluruh sisa target.
        $divisor = max(1, $remainingPlanned);

        return intdiv($remainingGood + $divisor - 1, $divisor);
    }
}
