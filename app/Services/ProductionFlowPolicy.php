<?php

namespace App\Services;

final class ProductionFlowPolicy
{
    public static function nextAction(
        string $executionState,
        bool $productionCompleted,
        bool $slotChanged,
        bool $shiftEnded,
        bool $targetReached,
        bool $requiresService
    ): string {
        if ($productionCompleted) {
            return 'completed';
        }

        if ($slotChanged) {
            return 'refresh_assignment';
        }

        if ($executionState === 'operator_change_required') {
            return 'operator_replacement';
        }

        if ($executionState === 'setting') {
            return 'setting_finish';
        }

        if ($requiresService || $executionState === 'service_required') {
            return 'service_complete';
        }

        if ($executionState === 'paused') {
            return 'resume';
        }

        if ($executionState === 'awaiting_defects') {
            return 'finish_shift';
        }

        if ($executionState === 'awaiting_next_shift') {
            return 'start_next_shift';
        }

        if ($shiftEnded || $targetReached) {
            return 'stop_and_confirm_defects';
        }

        return 'none';
    }
}
