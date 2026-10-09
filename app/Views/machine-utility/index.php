<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$formatMs = static function (int $milliseconds): string {
    $seconds = (int)floor(max(0, $milliseconds) / 1000);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    return sprintf('%02dh %02dm', $hours, $minutes);
};
$formatMsCompact = static function (int $milliseconds): string {
    $seconds = (int)floor(max(0, $milliseconds) / 1000);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    if ($hours > 0) return sprintf('%dh %02dm', $hours, $minutes);
    if ($minutes > 0) return sprintf('%dm %02ds', $minutes, $secs);
    return sprintf('%ds', $secs);
};
$formatAt = static function (int $timestamp, $reference): string {
    $timezone = (is_object($reference) && method_exists($reference, 'getTimezone')) ? $reference->getTimezone() : new \DateTimeZone(date_default_timezone_get());
    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone)->format('H:i');
};
$stateMeta = [
    'run' => ['Run', 'run', 'bi-play-fill'],
    'break' => ['Break', 'break', 'bi-cup-hot-fill'],
    'idle' => ['Idle', 'idle', 'bi-pause-fill'],
    'alarm' => ['Alarm', 'alarm', 'bi-exclamation-triangle-fill'],
    'offline' => ['Offline', 'offline', 'bi-wifi-off'],
    'setting' => ['Setting', 'setting', 'bi-tools'],
];
$shiftDisplayNames = ['Day Shift', 'Mid Shift', 'Night Shift'];
$timelineShiftLabels = ['Day', 'Mid', 'Night'];
$timelineShiftClasses = ['day', 'mid', 'night'];
$dailyTotals = $dailyTotals ?? [];
$shiftTimelines = $shiftTimelines ?? [];
$currentRuntime = $currentRuntime ?? null;
foreach (array_keys($stateMeta) as $state) $dailyTotals[$state] = (int)($dailyTotals[$state] ?? 0);
$deviceStatus = strtolower((string)($device['device_status'] ?? 'offline'));
$deviceStatusClass = match ($deviceStatus) {
    'run', 'running' => 'success',
    'setting' => 'info',
    'paused' => 'warning',
    'alarm' => 'danger',
    'idle', 'online' => 'primary',
    default => 'secondary',
};
$dailyGross = 0;
$dailyGood = 0;
$dailyReject = 0;
$dailyTarget = 0;
$idealProductionMs = 0;
foreach ($shiftTimelines as $row) {
    $summary = $row['summary'] ?? [];
    $dailyGross += (int)($summary['gross'] ?? 0);
    $dailyGood += (int)($summary['good'] ?? 0);
    $dailyReject += (int)($summary['reject'] ?? 0);
    $dailyTarget += (int)($summary['target'] ?? 0);
    foreach (($row['details'] ?? []) as $detail) {
        $gross = (int)($detail['actual_qty'] ?? 0);
        $standardCycleMs = (int)($detail['standard_machine_time_ms'] ?? 0) + (int)($detail['standard_loading_time_ms'] ?? 0);
        if ($gross > 0 && $standardCycleMs > 0) $idealProductionMs += ($gross * $standardCycleMs);
    }
}
$dailyRuntimeMs = array_sum($dailyTotals);
$plannedProductionMs = $dailyTotals['run'] + $dailyTotals['idle'] + $dailyTotals['alarm'] + $dailyTotals['offline'] + $dailyTotals['setting'];
$availability = $plannedProductionMs > 0 ? min(1, $dailyTotals['run'] / $plannedProductionMs) : 0;
if ($dailyTotals['run'] > 0 && $idealProductionMs > 0) {
    $performance = min(1, $idealProductionMs / $dailyTotals['run']);
} elseif ($dailyTarget > 0) {
    $performance = min(1, $dailyGross / $dailyTarget);
} else {
    $performance = 0;
}
$quality = $dailyGross > 0 ? min(1, $dailyGood / $dailyGross) : 0;
$availabilityPct = $availability * 100;
$performancePct = $performance * 100;
$qualityPct = $quality * 100;
$dailyOee = $availability * $performance * $quality * 100;
$oeeClass = $dailyOee >= 85 ? 'excellent' : ($dailyOee >= 70 ? 'good' : ($dailyOee >= 50 ? 'warning' : 'danger'));
$losses = [
    'Offline' => ['ms' => $dailyTotals['offline'], 'class' => 'offline', 'icon' => 'bi-wifi-off'],
    'Idle' => ['ms' => $dailyTotals['idle'], 'class' => 'idle', 'icon' => 'bi-pause-fill'],
    'Alarm' => ['ms' => $dailyTotals['alarm'], 'class' => 'alarm', 'icon' => 'bi-exclamation-triangle-fill'],
    'Setting' => ['ms' => $dailyTotals['setting'], 'class' => 'setting', 'icon' => 'bi-tools'],
];
uasort($losses, static fn(array $a, array $b): int => $b['ms'] <=> $a['ms']);
$totalLossMs = array_sum(array_column($losses, 'ms'));
?>
<link rel="stylesheet" href="<?= base_url() ?>assets/css/machine-utility.css">
<div class="page-heading">
    <div>
        <h1>Machine <span>Utility</span></h1>
        <div class="page-subtitle">Timeline runtime aktual per Machine, tanggal, dan 3 shift.</div>
    </div>
    <div class="action-row">
        <a href="<?= esc(site_url('production'), 'attr') ?>" class="btn btn-outline-primary"><i class="bi bi-activity me-2"></i>Production</a>
        <button type="button" class="btn btn-primary" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-2"></i>Refresh</button>
    </div>
</div>
<form method="get" action="<?= esc(site_url('machine-utility'), 'attr') ?>" class="panel filter-panel mb-3">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="filter-label" for="machineUtilityDate">Tanggal</label>
            <input class="form-control" id="machineUtilityDate" type="date" name="date" value="<?= esc($date, 'attr') ?>" required>
        </div>
        <div class="col-md-5">
            <label class="filter-label" for="machineUtilityMachine">Machine</label>
            <select class="form-select" id="machineUtilityMachine" name="machine_id" required>
                <?php foreach ($machines as $option): ?>
                    <option value="<?= (int)$option['id'] ?>" <?= (int)$machineId === (int)$option['id'] ? 'selected' : '' ?>><?= esc(($option['code'] ?? '-') . ' · ' . ($option['name'] ?? '-')) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary w-100"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
        </div>
    </div>
</form>
<?php if (!$machine): ?>
    <div class="panel p-5 text-center text-secondary"><i class="bi bi-cpu d-block fs-2 mb-2"></i>Belum ada Machine aktif.</div>
<?php else: ?>
    <div class="panel machine-utility-overview">
        <div class="machine-overview-main">
            <div class="machine-overview-icon"><i class="bi bi-cpu"></i></div>
            <div>
                <div class="machine-overview-code"><?= esc(($machine['code'] ?? '-') . ' · ' . ($machine['name'] ?? '-')) ?></div>
                <div class="machine-overview-meta">
                    <span><i class="bi bi-grid me-1"></i><?= $slot ? 'Slot ' . esc((string)$slot['slot_no']) : 'Belum terpasang di slot' ?></span>
                    <span>·</span>
                    <span><i class="bi bi-calendar3 me-1"></i><?= esc(date('d M Y', strtotime($date))) ?></span>
                    <span>·</span>
                    <span>07:00 → 07:00</span>
                </div>
            </div>
        </div>
        <div class="machine-overview-side">
            <div class="machine-overview-item">
                <div class="machine-overview-label">Device Status</div>
                <div class="machine-overview-value"><span class="badge bg-<?= esc($deviceStatusClass, 'attr') ?>"><?= esc(strtoupper($deviceStatus)) ?></span></div>
            </div>
            <div class="machine-overview-item">
                <div class="machine-overview-label">Good / Target</div>
                <div class="machine-overview-value"><span class="text-success"><?= number_format($dailyGood) ?></span> / <?= number_format($dailyTarget) ?></div>
            </div>
            <div class="machine-overview-item">
                <div class="machine-overview-label">NC All Shift</div>
                <div class="machine-overview-value text-danger"><?= number_format($dailyReject) ?></div>
            </div>
            <div class="machine-overview-item">
                <div class="machine-overview-label">Last Seen</div>
                <div class="machine-overview-value"><?= !empty($device['last_seen_at']) ? esc($device['last_seen_at']) : '-' ?></div>
            </div>
        </div>
    </div>
    <div class="row g-3 utility-main-layout">
        <div class="col-xl-9 utility-left-column">
            <div class="panel production-timeline-card">
                <div class="production-timeline-header">
                    <h2>Production Timeline — All Shifts</h2>
                    <div class="production-timeline-total"><?= number_format($dailyGross) ?> gross total</div>
                </div>
                <div class="production-timeline-content">
                    <?php foreach ($shiftTimelines as $shiftIndex => $row): ?>
                        <?php
                        $shiftStartTs = $row['start']->getTimestamp();
                        $shiftEndTs = $row['end']->getTimestamp();
                        $shiftLabel = $timelineShiftLabels[$shiftIndex] ?? ('Shift ' . ($shiftIndex + 1));
                        $shiftClass = $timelineShiftClasses[$shiftIndex] ?? 'day';
                        $chunks = [];
                        for ($chunkStartTs = $shiftStartTs; $chunkStartTs < $shiftEndTs; $chunkStartTs += 14400) {
                            $chunkEndTs = min($shiftEndTs, $chunkStartTs + 14400);
                            $chunkDuration = max(1, $chunkEndTs - $chunkStartTs);
                            $chunkSegments = [];
                            foreach (($row['segments'] ?? []) as $segment) {
                                $segmentStartTs = $segment['start']->getTimestamp();
                                $segmentEndTs = $segment['end']->getTimestamp();
                                $clipStart = max($chunkStartTs, $segmentStartTs);
                                $clipEnd = min($chunkEndTs, $segmentEndTs);
                                if ($clipEnd <= $clipStart) continue;
                                $chunkSegments[] = [
                                    'state' => $segment['state'] ?? 'offline',
                                    'left_pct' => (($clipStart - $chunkStartTs) / $chunkDuration) * 100,
                                    'width_pct' => (($clipEnd - $clipStart) / $chunkDuration) * 100,
                                    'start' => $segment['start'],
                                    'end' => $segment['end'],
                                    'part_number' => $segment['part_number'] ?? null,
                                    'process_name' => $segment['process_name'] ?? null,
                                ];
                            }
                            $ticks = [];
                            for ($tickTs = $chunkStartTs + 3600; $tickTs < $chunkEndTs; $tickTs += 3600) {
                                $ticks[] = [
                                    'label' => $formatAt($tickTs, $row['start']),
                                    'left_pct' => (($tickTs - $chunkStartTs) / $chunkDuration) * 100,
                                ];
                            }
                            $chunks[] = [
                                'start_label' => $formatAt($chunkStartTs, $row['start']),
                                'end_label' => $formatAt($chunkEndTs, $row['start']),
                                'segments' => $chunkSegments,
                                'ticks' => $ticks,
                            ];
                        }
                        ?>
                        <div class="production-shift-group">
                            <div class="production-shift-label-wrap">
                                <span class="production-shift-label <?= esc($shiftClass, 'attr') ?>"><?= esc($shiftLabel) ?></span>
                            </div>
                            <div class="production-shift-rows">
                                <?php foreach ($chunks as $chunk): ?>
                                    <div class="production-timeline-row">
                                        <div class="production-row-start"><?= esc($chunk['start_label']) ?></div>
                                        <div class="production-row-content">
                                            <div class="production-time-axis">
                                                <?php foreach ($chunk['ticks'] as $tick): ?>
                                                    <span class="production-time-label" style="left:<?= esc(number_format($tick['left_pct'], 4, '.', ''), 'attr') ?>%"><?= esc($tick['label']) ?></span>
                                                <?php endforeach ?>
                                            </div>
                                            <div class="production-runtime-track">
                                                <?php foreach ($chunk['ticks'] as $tick): ?>
                                                    <span class="production-hour-line" style="left:<?= esc(number_format($tick['left_pct'], 4, '.', ''), 'attr') ?>%"></span>
                                                <?php endforeach ?>
                                                <?php if (empty($chunk['segments'])): ?>
                                                    <div class="production-runtime-segment state-offline" style="left:0;width:100%"><span>Offline</span></div>
                                                <?php else: ?>
                                                    <?php foreach ($chunk['segments'] as $segment): ?>
                                                        <?php
                                                        $state = (string)$segment['state'];
                                                        [, $segmentClass] = $stateMeta[$state] ?? $stateMeta['offline'];
                                                        $stateLabel = $stateMeta[$state][0] ?? strtoupper($state);
                                                        $tooltip = $segment['start']->format('H:i:s') . '–' . $segment['end']->format('H:i:s') . ' · ' . $stateLabel;
                                                        if (!empty($segment['part_number'])) $tooltip .= ' · ' . $segment['part_number'];
                                                        if (!empty($segment['process_name'])) $tooltip .= ' · ' . $segment['process_name'];
                                                        ?>
                                                        <div class="production-runtime-segment state-<?= esc($segmentClass, 'attr') ?>" style="left:<?= esc(number_format((float)$segment['left_pct'], 5, '.', ''), 'attr') ?>%;width:<?= esc(number_format((float)$segment['width_pct'], 5, '.', ''), 'attr') ?>%" title="<?= esc($tooltip, 'attr') ?>" data-bs-toggle="tooltip" data-bs-placement="top">
                                                            <?php if ((float)$segment['width_pct'] >= 18): ?><span><?= esc($stateLabel) ?></span><?php endif ?>
                                                        </div>
                                                    <?php endforeach ?>
                                                <?php endif ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
                <div class="production-timeline-legend">
                    <span><i class="timeline-legend-box state-run"></i>Run</span>
                    <span><i class="timeline-legend-box state-break"></i>Break</span>
                    <span><i class="timeline-legend-box state-idle"></i>Idle</span>
                    <span><i class="timeline-legend-box state-alarm"></i>Alarm</span>
                    <span><i class="timeline-legend-box state-setting"></i>Setting</span>
                    <span><i class="timeline-legend-box state-offline"></i>Offline</span>
                    <?php foreach ($shiftTimelines as $index => $row): ?>
                        <?php $legendLabel = $timelineShiftLabels[$index] ?? ('Shift ' . ($index + 1));
                        $legendClass = $timelineShiftClasses[$index] ?? 'day'; ?>
                        <span class="timeline-shift-legend"><i class="timeline-shift-symbol <?= esc($legendClass, 'attr') ?>"><b></b></i><strong><?= esc($legendLabel) ?></strong><?= esc($row['start']->format('H:i')) ?>–<?= esc($row['end']->format('H:i')) ?></span>
                    <?php endforeach ?>
                </div>
            </div>
            <div class="utility-shift-list">
                <?php foreach ($shiftTimelines as $index => $row): ?>
                    <?php
                    $summary = $row['summary'] ?? [];
                    $detailShiftDisplay = $shiftDisplayNames[$index] ?? ($row['shift']['name'] ?? ('Shift ' . ($index + 1)));
                    $shiftGross = (int)($summary['gross'] ?? 0);
                    $shiftGood = (int)($summary['good'] ?? 0);
                    $shiftReject = (int)($summary['reject'] ?? 0);
                    $shiftTarget = (int)($summary['target'] ?? 0);
                    $shiftRunMs = (int)($row['totals']['run'] ?? 0);
                    $shiftBreakMs = (int)($row['totals']['break'] ?? 0);
                    $shiftIdleMs = (int)($row['totals']['idle'] ?? 0);
                    $shiftAlarmMs = (int)($row['totals']['alarm'] ?? 0);
                    $shiftSettingMs = (int)($row['totals']['setting'] ?? 0);
                    $shiftOfflineMs = (int)($row['totals']['offline'] ?? 0);
                    $shiftDurationMs = max(1, ($row['end']->getTimestamp() - $row['start']->getTimestamp()) * 1000);
                    $achievementPct = $shiftTarget > 0 ? min(100, ($shiftGood / $shiftTarget) * 100) : 0;
                    $runPct = ($shiftRunMs / $shiftDurationMs) * 100;
                    $breakPct = ($shiftBreakMs / $shiftDurationMs) * 100;
                    $idlePct = ($shiftIdleMs / $shiftDurationMs) * 100;
                    $alarmPct = ($shiftAlarmMs / $shiftDurationMs) * 100;
                    $settingPct = ($shiftSettingMs / $shiftDurationMs) * 100;
                    $offlinePct = ($shiftOfflineMs / $shiftDurationMs) * 100;
                    $actualCycleMs = (int)($row['cycle']['cycle_ms'] ?? 0);
                    if ($actualCycleMs <= 0 && $shiftGross > 0 && $shiftRunMs > 0) $actualCycleMs = (int)round($shiftRunMs / $shiftGross);
                    $ratePerHour = $shiftRunMs > 0 ? ($shiftGood / ($shiftRunMs / 3600000)) : 0;
                    $weightedStandardMs = 0;
                    $weightedQty = 0;
                    foreach (($row['details'] ?? []) as $detail) {
                        $detailGross = (int)($detail['actual_qty'] ?? 0);
                        $detailStandard = (int)($detail['standard_machine_time_ms'] ?? 0) + (int)($detail['standard_loading_time_ms'] ?? 0);
                        if ($detailGross > 0 && $detailStandard > 0) {
                            $weightedStandardMs += ($detailGross * $detailStandard);
                            $weightedQty += $detailGross;
                        }
                    }
                    $averageStandardCycleMs = $weightedQty > 0 ? (int)round($weightedStandardMs / $weightedQty) : 0;
                    $shiftCardId = 'shiftUtilityBody' . $index;
                    ?>
                    <div class="panel utility-shift-card-v2">
                        <div class="utility-shift-top">
                            <div class="utility-shift-top-left">
                                <div class="utility-shift-heading">
                                    <div>
                                        <h3><?= esc($detailShiftDisplay) ?></h3>
                                        <div class="utility-shift-time"><?= esc($row['start']->format('H:i')) ?> – <?= esc($row['end']->format('H:i')) ?></div>
                                    </div>
                                    <div class="utility-shift-progress-copy"><?= number_format($shiftGood) ?> / <?= number_format($shiftTarget) ?> pcs</div>
                                </div>
                                <div class="utility-shift-progress-bar"><span style="width:<?= esc(number_format($achievementPct, 2, '.', ''), 'attr') ?>%"></span></div>
                            </div>
                            <div class="utility-shift-top-right">
                                <div class="utility-shift-top-stat"><?= number_format($achievementPct, 1) ?>%</div>
                                <div class="utility-shift-top-runtime"><?= esc($formatMs($shiftRunMs)) ?></div>
                                <button type="button" class="utility-shift-toggle" data-shift-toggle="<?= esc($shiftCardId, 'attr') ?>" aria-expanded="true" aria-controls="<?= esc($shiftCardId, 'attr') ?>"><i class="bi bi-caret-up-fill"></i></button>
                            </div>
                        </div>
                        <div class="utility-shift-body-v2" id="<?= esc($shiftCardId, 'attr') ?>">
                            <div class="utility-shift-body-grid">
                                <div>
                                    <div class="utility-info-section">
                                        <div class="utility-info-title">Runtime Breakdown</div>
                                        <div class="utility-kv-list">
                                            <div class="utility-kv-row"><span>Run</span><strong class="text-success"><?= esc($formatMs($shiftRunMs)) ?> (<?= number_format($runPct, 1) ?>%)</strong></div>
                                            <div class="utility-kv-row"><span>Break</span><strong style="color:#ca8a04"><?= esc($formatMs($shiftBreakMs)) ?> (<?= number_format($breakPct, 1) ?>%)</strong></div>
                                            <div class="utility-kv-row"><span>Idle</span><strong class="text-warning"><?= esc($formatMs($shiftIdleMs)) ?> (<?= number_format($idlePct, 1) ?>%)</strong></div>
                                            <div class="utility-kv-row"><span>Alarm</span><strong class="text-danger"><?= esc($formatMs($shiftAlarmMs)) ?> (<?= number_format($alarmPct, 1) ?>%)</strong></div>
                                            <div class="utility-kv-row"><span>Setting</span><strong class="text-primary"><?= esc($formatMs($shiftSettingMs)) ?> (<?= number_format($settingPct, 1) ?>%)</strong></div>
                                            <div class="utility-kv-row"><span>Offline</span><strong><?= esc($formatMs($shiftOfflineMs)) ?> (<?= number_format($offlinePct, 1) ?>%)</strong></div>
                                        </div>
                                    </div>
                                    <div class="utility-info-section">
                                        <div class="utility-info-title">Cycle & Rate</div>
                                        <div class="utility-kv-list">
                                            <div class="utility-kv-row"><span>Rate</span><strong class="text-success"><?= number_format($ratePerHour, 1) ?> pcs/h</strong></div>
                                            <div class="utility-kv-row"><span>Actual Cycle</span><strong><?= $actualCycleMs > 0 ? number_format($actualCycleMs / 1000, 2) . 's' : '-' ?></strong></div>
                                            <div class="utility-kv-row"><span>Std Cycle Avg.</span><strong class="text-primary"><?= $averageStandardCycleMs > 0 ? number_format($averageStandardCycleMs / 1000, 2) . 's' : '-' ?></strong></div>
                                            <div class="utility-kv-row"><span>Gross</span><strong><?= number_format($shiftGross) ?> pcs</strong></div>
                                            <div class="utility-kv-row"><span>Good</span><strong class="text-success"><?= number_format($shiftGood) ?> pcs</strong></div>
                                            <div class="utility-kv-row"><span>NC</span><strong class="text-danger"><?= number_format($shiftReject) ?> pcs</strong></div>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div class="utility-info-title">Parts Details</div>
                                    <div class="utility-parts-head">
                                        <span>Part</span>
                                        <span>Process</span>
                                        <span>Operator / PIC</span>
                                        <span>Gross</span>
                                        <span>Good</span>
                                        <span>NC</span>
                                        <span>Target</span>
                                    </div>
                                    <div class="utility-parts-list">
                                        <?php foreach (($row['details'] ?? []) as $detail): ?>
                                            <?php
                                            $standardMachineMs = (int)($detail['standard_machine_time_ms'] ?? 0);
                                            $standardLoadingMs = (int)($detail['standard_loading_time_ms'] ?? 0);
                                            ?>
                                            <div class="utility-part-item">
                                                <div class="part-col-name">
                                                    <strong><?= esc($detail['part_number'] ?? '-') ?></strong>
                                                    <small>Standard M <?= number_format($standardMachineMs / 1000, 2) ?>s + L <?= number_format($standardLoadingMs / 1000, 2) ?>s</small>
                                                </div>
                                                <div class="part-col-process">
                                                    P<?= (int)($detail['process_no'] ?? 1) ?> · <?= esc($detail['process_name_snapshot'] ?? 'Process') ?>
                                                    <small><?= esc(strtoupper((string)($detail['mode'] ?? 'production'))) ?></small>
                                                </div>
                                                <div class="part-col-operator"><?= esc(($detail['mode'] ?? 'production') === 'setting' ? ($detail['pic_name'] ?? '-') : ($detail['operator_name'] ?? '-')) ?></div>
                                                <div class="part-col-mini"><?= number_format((int)($detail['actual_qty'] ?? 0)) ?></div>
                                                <div class="part-col-mini text-success"><?= number_format((int)($detail['good_qty'] ?? 0)) ?></div>
                                                <div class="part-col-mini text-danger"><?= number_format((int)($detail['reject_qty'] ?? 0)) ?></div>
                                                <div class="part-col-mini"><?= number_format((int)($detail['target_qty'] ?? 0)) ?></div>
                                            </div>
                                        <?php endforeach ?>
                                        <?php if (empty($row['details'])): ?>
                                            <div class="utility-part-empty"><i class="bi bi-inbox me-1"></i>Tidak ada production pada shift ini.</div>
                                        <?php endif ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
        </div>
        <div class="col-xl-3 utility-right-column">
            <div class="utility-side-stack">
                <div class="panel utility-side-card">
                    <div class="utility-side-title">
                        <strong><i class="bi bi-speedometer2 me-2"></i>Daily OEE</strong>
                        <span class="small text-secondary"><?= esc(date('d M', strtotime($date))) ?></span>
                    </div>
                    <div class="oee-main">
                        <div class="oee-ring" style="--oee:<?= esc(number_format($dailyOee, 2, '.', ''), 'attr') ?>">
                            <div class="oee-ring-content">
                                <div class="oee-value"><?= number_format($dailyOee, 1) ?>%</div>
                                <div class="oee-label">OEE</div>
                            </div>
                        </div>
                        <div class="oee-copy">
                            <span class="oee-status <?= esc($oeeClass, 'attr') ?>">
                                <?php if ($dailyOee >= 85): ?>Excellent
                                <?php elseif ($dailyOee >= 70): ?>Good
                                <?php elseif ($dailyOee >= 50): ?>Needs Attention
                                <?php else: ?>Low
                            <?php endif ?>
                            </span>
                            <div class="oee-production">Good <strong class="text-success"><?= number_format($dailyGood) ?></strong><br>Gross <strong><?= number_format($dailyGross) ?></strong><br>NC <strong class="text-danger"><?= number_format($dailyReject) ?></strong></div>
                        </div>
                    </div>
                    <div class="oee-components">
                        <div class="oee-component"><strong><?= number_format($availabilityPct, 1) ?>%</strong><span>Availability</span></div>
                        <div class="oee-component"><strong><?= number_format($performancePct, 1) ?>%</strong><span>Performance</span></div>
                        <div class="oee-component"><strong><?= number_format($qualityPct, 1) ?>%</strong><span>Quality</span></div>
                    </div>
                </div>
                <div class="panel utility-side-card">
                    <div class="utility-side-title">
                        <strong><i class="bi bi-graph-down-arrow me-2"></i>Top Losses</strong>
                        <span class="small text-secondary"><?= esc($formatMsCompact($totalLossMs)) ?></span>
                    </div>
                    <div class="loss-list">
                        <?php foreach ($losses as $label => $loss): ?>
                            <?php $lossPct = $totalLossMs > 0 ? ($loss['ms'] / $totalLossMs) * 100 : 0 ?>
                            <div>
                                <div class="loss-item-head">
                                    <span class="loss-name"><i class="bi <?= esc($loss['icon'], 'attr') ?>"></i><?= esc($label) ?></span>
                                    <span class="loss-value"><?= esc($formatMsCompact((int)$loss['ms'])) ?></span>
                                </div>
                                <div class="loss-bar">
                                    <div class="loss-fill <?= esc($loss['class'], 'attr') ?>" style="width:<?= esc(number_format($lossPct, 2, '.', ''), 'attr') ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach ?>
                        <?php if ($totalLossMs <= 0): ?>
                            <div class="text-center text-secondary small py-3"><i class="bi bi-check-circle text-success me-1"></i>Tidak ada loss tercatat.</div>
                        <?php endif ?>
                    </div>
                </div>
                <div class="panel utility-side-card">
                    <div class="utility-side-title"><strong><i class="bi bi-clock-history me-2"></i>Daily Runtime</strong></div>
                    <div class="runtime-total">
                        <div>
                            <div class="runtime-total-label">Total Recorded</div>
                            <div class="runtime-total-value"><?= esc($formatMs($dailyRuntimeMs)) ?></div>
                        </div>
                        <div class="runtime-date"><?= esc(date('d M Y', strtotime($date))) ?><br>07:00 → 07:00</div>
                    </div>
                    <div class="runtime-list">
                        <?php foreach ($stateMeta as $state => [$label, $class, $icon]): ?>
                            <div class="runtime-item">
                                <span class="runtime-state"><span class="runtime-state-icon <?= esc($class, 'attr') ?>"><i class="bi <?= esc($icon, 'attr') ?>"></i></span><?= esc($label) ?></span>
                                <span class="runtime-duration"><?= esc($formatMsCompact((int)$dailyTotals[$state])) ?></span>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <?php if ($currentRuntime): ?>
                        <?php $currentState = (string)($currentRuntime['state'] ?? 'offline') ?>
                        <div class="current-runtime-box"><i class="bi bi-broadcast"></i><span>Current: <strong><?= esc($stateMeta[$currentState][0] ?? strtoupper($currentState)) ?></strong> · <?= esc($formatMsCompact((int)($currentRuntime['duration_ms'] ?? 0))) ?></span></div>
                    <?php endif ?>
                </div>
            </div>
        </div>
    </div>
<?php endif ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (window.bootstrap) {
            document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(element => new bootstrap.Tooltip(element));
        }
        document.querySelectorAll('[data-shift-toggle]').forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-shift-toggle');
                const target = document.getElementById(targetId);
                if (!target) return;
                const hidden = target.hasAttribute('hidden');
                if (hidden) {
                    target.removeAttribute('hidden');
                    button.classList.remove('is-collapsed');
                    button.setAttribute('aria-expanded', 'true');
                } else {
                    target.setAttribute('hidden', 'hidden');
                    button.classList.add('is-collapsed');
                    button.setAttribute('aria-expanded', 'false');
                }
            });
        });
        <?php if ($date === date('Y-m-d')): ?>
            setInterval(() => {
                const active = document.activeElement;
                const insideForm = active && typeof active.closest === 'function' && active.closest('form');
                if (!document.hidden && !insideForm) location.reload();
            }, 30000);
        <?php endif ?>
    });
</script>
<?= $this->endSection() ?>