<?php

use App\Services\ProductionTargetPlanner;
use CodeIgniter\Test\CIUnitTestCase;

final class ProductionTargetPlannerTest extends CIUnitTestCase
{
    public function testThousandPartsFiveDaysThreeShiftsStartsAtSixtySeven(): void
    {
        $this->assertSame(
            67,
            ProductionTargetPlanner::nextShiftTarget(1000, 0, 5, 3, 0)
        );
    }

    public function testUnderTargetIsRedistributedToRemainingShifts(): void
    {
        $this->assertSame(
            68,
            ProductionTargetPlanner::nextShiftTarget(1000, 60, 5, 3, 1)
        );
    }

    public function testThousandPartsFiveDaysTwoShiftsEqualsOneHundred(): void
    {
        $this->assertSame(
            100,
            ProductionTargetPlanner::nextShiftTarget(1000, 0, 5, 2, 0)
        );
    }

    public function testCeilingDistributionEndsExactlyAtOverallTargetWhenEachShiftMeetsPlan(): void
    {
        $good = 0;
        $allocated = [];

        for ($used = 0; $used < 15; $used++) {
            $target = ProductionTargetPlanner::nextShiftTarget(1000, $good, 5, 3, $used);
            $allocated[] = $target;
            $good += $target;
        }

        $this->assertSame(1000, $good);
        $this->assertSame(67, $allocated[0]);
        $this->assertSame(66, $allocated[14]);
    }

    public function testRemainingTargetCanContinueAfterPlannedWindowIsExhausted(): void
    {
        $this->assertSame(
            40,
            ProductionTargetPlanner::nextShiftTarget(1000, 960, 5, 3, 15)
        );
    }
}
