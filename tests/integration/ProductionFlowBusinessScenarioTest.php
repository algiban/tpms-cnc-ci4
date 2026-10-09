<?php

use App\Services\ProductionFlowPolicy;
use App\Services\ProductionQuantity;
use App\Services\ProductionTargetPlanner;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Integrates the production quantity and state/action policies in the same
 * sequence used by the TPMS API. Database/API integration is documented in
 * docs/QA_FIX_01_07.md and requires a MySQL test database.
 */
final class ProductionFlowBusinessScenarioTest extends CIUnitTestCase
{
    public function testFullBusinessFlowAcrossRejectAndDynamicShiftTargets(): void
    {
        $target = 100;
        $durationDays = 2;
        $shiftsPerDay = 2;
        $cumulativeGood = 0;

        // Shift 1: 100 / (2 hari x 2 shift) = 25 GOOD target.
        $shift1Target = ProductionTargetPlanner::nextShiftTarget(
            $target,
            $cumulativeGood,
            $durationDays,
            $shiftsPerDay,
            0
        );
        $this->assertSame(25, $shift1Target);

        // Gross 25 dengan reject 3 menghasilkan GOOD 22.
        $shift1Good = ProductionQuantity::good(25, 3);
        $this->assertSame(22, $shift1Good);
        $cumulativeGood += $shift1Good;
        $this->assertFalse(ProductionQuantity::reached($target, $cumulativeGood));

        // Defisit otomatis didistribusikan ke 3 shift tersisa: ceil(78/3)=26.
        $shift2Target = ProductionTargetPlanner::nextShiftTarget(
            $target,
            $cumulativeGood,
            $durationDays,
            $shiftsPerDay,
            1
        );
        $this->assertSame(26, $shift2Target);

        // Bila shift tersisa memenuhi target dinamis, overall GOOD tepat 100.
        for ($usedShifts = 1; $usedShifts < 4; $usedShifts++) {
            $shiftTarget = ProductionTargetPlanner::nextShiftTarget(
                $target,
                $cumulativeGood,
                $durationDays,
                $shiftsPerDay,
                $usedShifts
            );
            $cumulativeGood += $shiftTarget;
        }

        $this->assertSame(100, $cumulativeGood);
        $this->assertTrue(ProductionQuantity::reached($target, $cumulativeGood));
        $this->assertSame(
            'completed',
            ProductionFlowPolicy::nextAction('completed', true, false, false, true, false)
        );
    }

    public function testAlarmResumeServiceAndShiftRolloverActions(): void
    {
        $this->assertSame(
            'resume',
            ProductionFlowPolicy::nextAction('paused', false, false, false, false, false)
        );

        $this->assertSame(
            'service_complete',
            ProductionFlowPolicy::nextAction('service_required', false, false, false, false, true)
        );

        $this->assertSame(
            'stop_and_confirm_defects',
            ProductionFlowPolicy::nextAction('running', false, false, true, false, false)
        );

        $this->assertSame(
            'finish_shift',
            ProductionFlowPolicy::nextAction('awaiting_defects', false, false, false, false, false)
        );

        $this->assertSame(
            'completed',
            ProductionFlowPolicy::nextAction('completed', true, false, false, true, false)
        );
    }
}
