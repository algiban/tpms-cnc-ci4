<?php

namespace App\Services;

final class ProductionQuantity
{
    public static function good(int $grossQty, int $rejectQty): int
    {
        return max(0, $grossQty - $rejectQty);
    }

    public static function remaining(int $targetGoodQty, int $goodQty): int
    {
        return max(0, $targetGoodQty - $goodQty);
    }

    public static function reached(int $targetGoodQty, int $goodQty): bool
    {
        return $targetGoodQty > 0 && $goodQty >= $targetGoodQty;
    }
}
