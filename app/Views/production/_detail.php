<?php
$formatMs = static function (int $milliseconds): string {
    $milliseconds = max(0, $milliseconds);
    $seconds = intdiv($milliseconds, 1000);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    $ms = $milliseconds % 1000;
    return $hours > 0
        ? sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $ms)
        : sprintf('%02d:%02d.%03d', $minutes, $secs, $ms);
};

$detailId = (int) $r['detail_id'];
$detailBatch = !empty($r['production_batch_id'])
    ? ($batchOverview[(int) $r['production_batch_id']] ?? null)
    : null;
?>

<div class="detail-section-title">Production Process Flow</div>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($processFlow[(int) $r['part_id']] ?? [] as $stage): ?>
        <div class="border rounded p-2 small <?= (int) $stage['id'] === (int) $r['part_process_id'] ? 'border-primary' : '' ?>">
            <b><?= esc($stage['process_name']) ?></b>
            <div><?= $stage['is_next_grinding'] ? '' : esc(strtoupper($stage['process_mode'])) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="small mb-3">Mode: <b><?= esc($r['mode'] ?? 'production') ?></b></div>

<div class="detail-list mb-4">
    <div class="detail-item"><small>Part</small><strong><?= esc($r['part_name']) ?></strong></div>
    <div class="detail-item">
        <small>Process</small>
        <strong><?= esc($r['process_name_snapshot'] ?? 'Single Process') ?></strong>
        <small>P<?= (int) ($r['process_no'] ?? 1) ?><?php if (strtolower((string) ($r['process_mode_snapshot'] ?? '')) !== 'none'): ?> · <?= esc(strtoupper((string) ($r['process_mode_snapshot'] ?? 'auto'))) ?><?php endif; ?></small>
    </div>
    <div class="detail-item">
        <small>Standard Cycle</small>
        <strong>Machine <?= esc($formatMs((int) ($r['machine_time_target_ms'] ?? 0))) ?></strong>
        <small>Loading <?= esc($formatMs((int) ($r['loading_time_target_ms'] ?? 0))) ?></small>
    </div>
    <div class="detail-item"><small>Customer</small><strong><?= esc($r['customer_name']) ?></strong></div>
    <div class="detail-item">
        <small><?= ($r['mode'] ?? 'production') === 'setting' ? 'PIC Setting' : 'Operator' ?></small>
        <strong><?= esc(($r['mode'] ?? 'production') === 'setting' ? ($r['pic_name'] ?? '-') : ($r['operator_name'] ?? '-')) ?></strong>
    </div>
    <div class="detail-item"><small>Mulai Shift</small><strong><?= esc($r['detail_started_at'] ?? '-') ?></strong></div>
    <div class="detail-item"><small>Selesai Shift</small><strong><?= esc($r['detail_ended_at'] ?? '-') ?></strong></div>
    <div class="detail-item"><small>Gross Shift</small><strong><?= number_format((int) ($r['shift_actual_qty'] ?? 0), 0, ',', '.') ?></strong></div>
    <div class="detail-item"><small>Plan GOOD Shift</small><strong><?= number_format((int) ($r['detail_target_qty'] ?? 0), 0, ',', '.') ?></strong></div>
    <div class="detail-item"><small>Daily Plan</small><strong><?= number_format((int) $r['detail_target_qty'] * (int) $r['shifts_per_day']) ?> / day</strong></div>
    <div class="detail-item"><small>Gross / Good / Plan Sesi</small><strong><?= number_format((int) $r['production_gross_qty'], 0, ',', '.') ?> / <?= number_format((int) $r['production_good_qty'], 0, ',', '.') ?> / <?= number_format((int) $r['production_target_qty'], 0, ',', '.') ?></strong></div>
</div>

<?php if ($detailBatch): ?>
    <div class="detail-section-title">Process Flow Batch</div>
    <div class="border rounded-3 p-3 mb-4">
        <div class="d-flex justify-content-between gap-2 flex-wrap mb-3">
            <div>
                <strong class="mono"><?= esc($detailBatch['batch_code']) ?></strong>
                <div class="small text-secondary">Actual Production Total = GOOD process terakhir</div>
            </div>
            <div class="text-end">
                <strong><?= number_format((int) $detailBatch['final_good_qty']) ?> / <?= number_format((int) $detailBatch['target_qty']) ?></strong>
                <div class="small text-secondary"><?= esc((string) $detailBatch['achievement']) ?>%</div>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach ($detailBatch['processes'] as $process): ?>
                <?php
                $stageClass = match ($process['status'] ?? 'waiting') {
                    'completed' => 'success',
                    'running' => 'primary',
                    'ready' => 'warning',
                    default => 'secondary',
                };
                ?>
                <div class="border rounded px-2 py-2" style="min-width:145px;">
                    <div class="small fw-bold">P<?= (int) $process['process_no'] ?> · <?= esc($process['process_name']) ?></div>
                    <?php if (strtolower((string) ($process['process_mode'] ?? '')) !== 'none'): ?>
                        <div class="small text-secondary"><?= esc(strtoupper((string) $process['process_mode'])) ?></div>
                    <?php endif; ?>
                    <span class="badge bg-<?= $stageClass ?> mt-1"><?= esc(strtoupper((string) $process['status'])) ?></span>
                    <?php if (!empty($process['production_code'])): ?>
                        <div class="small mt-1">GOOD <?= number_format((int) $process['good_qty']) ?> / <?= number_format((int) $process['target_qty']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-end gap-2 flex-wrap mb-4">
    <?php if (($r['mode'] ?? 'production') === 'production' && !empty($r['operator_employee_id'])): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= esc(site_url('reports/cycle-time') . '?' . http_build_query([
            'start' => $r['detail_work_date'] ?: $r['production_date'],
            'end' => $r['detail_work_date'] ?: $r['production_date'],
            'shift_id' => $r['shift_id'],
            'machine_id' => $r['machine_id'],
            'operator_employee_id' => $r['operator_employee_id'],
            'part_id' => $r['part_id'],
            'part_process_id' => $r['part_process_id'],
        ]), 'attr') ?>">
            <i class="bi bi-stopwatch me-1"></i>Lihat Cycle Time Report
        </a>
    <?php endif; ?>
    <a class="btn btn-sm btn-outline-primary" href="<?= esc(site_url('machine-utility') . '?' . http_build_query([
        'date' => $r['detail_work_date'] ?: $r['production_date'],
        'machine_id' => $r['machine_id'],
    ]), 'attr') ?>">
        <i class="bi bi-bar-chart-steps me-1"></i>Lihat Runtime Machine
    </a>
</div>

<?php if (($r['mode'] ?? 'production') === 'production'): ?>
<div class="detail-section-title">Riwayat Operator Shift</div>
<div class="table-responsive mb-4"><table class="table table-sm align-middle">
<thead><tr><th>Operator</th><th>NIK</th><th>Mulai</th><th>Selesai</th><th>Alasan Perubahan</th></tr></thead><tbody>
<?php foreach (($operatorHistory ?? []) as $history): ?>
<tr><td><strong><?= esc($history['operator_name'] ?? '-') ?></strong></td><td><?= esc($history['nik'] ?? '-') ?></td><td><?= esc($history['started_at'] ?? '-') ?></td><td><?= !empty($history['ended_at']) ? esc($history['ended_at']) : 'Active' ?></td><td><?= esc($history['change_reason'] ?? '-') ?></td></tr>
<?php endforeach; ?>
<?php if (empty($operatorHistory)): ?><tr><td colspan="5" class="text-center text-secondary">Belum ada histori pergantian Operator.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<div class="detail-section-title">Session Runtime & Timeline</div>
<div class="table-responsive mb-3">
    <table class="table table-sm">
        <thead><tr><th>Activity</th><th>Start</th><th>End</th><th>Duration</th></tr></thead>
        <tbody>
        <?php foreach ($runtime[$detailId] ?? [] as $interval): ?>
            <tr>
                <td><?= esc(ucfirst($interval['state'])) ?></td>
                <td><?= esc($interval['started_at']) ?></td>
                <td><?= esc($interval['ended_at'] ?? 'Active') ?></td>
                <td><?= esc($formatMs($interval['ended_at'] ? (int) $interval['duration_ms'] : max(0, (time() - strtotime($interval['started_at'])) * 1000))) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($runtime[$detailId])): ?>
            <tr><td colspan="4" class="text-center text-secondary">Belum ada runtime interval.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<div class="detail-section-title">Pemakaian Tools</div>
<div class="table-responsive mb-4">
    <table class="table">
        <thead><tr><th>Tool</th><th>Posisi</th><th>Side</th><th>Holder</th><th>Awal</th><th>Akhir</th><th>Pemakaian</th><th>Limit</th></tr></thead>
        <tbody>
        <?php foreach ($tools[$detailId] ?? [] as $t): ?>
            <tr><td class="mono"><?= esc($t['code']) ?></td><td><?= esc($t['position_snapshot'] ?? '-') ?></td><td><strong><?= max(1, (int) ($t['current_edge'] ?? 1)) ?>/<?= max(1, (int) ($t['cutting_edge'] ?? 1)) ?></strong><div class="small text-secondary">current / total</div></td><td><?= esc($t['holder'] ?? '-') ?></td><td><?= (int) $t['start_lifetime'] ?></td><td><?= (int) $t['end_lifetime'] ?></td><td><?= (int) $t['quantity_increment'] ?></td><td><?= (int) $t['set_lifetime_snapshot'] ?></td></tr>
        <?php endforeach; ?>
        <?php if (empty($tools[$detailId])): ?><tr><td colspan="8" class="text-center text-secondary">Belum ada pemakaian tools.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<div class="detail-section-title">Data Cacat</div>
<?php if (!empty($defects[$detailId])): ?>
    <?php foreach ($defects[$detailId] as $d): ?><div class="detail-item mb-2"><small><?= esc($d['defect_type']) ?></small><strong><?= (int) $d['quantity'] ?></strong></div><?php endforeach; ?>
<?php else: ?><p class="text-secondary">Belum ada data cacat.</p><?php endif; ?>

<div class="detail-section-title mt-4">Riwayat Alarm Shift</div>
<?php if (!empty($alarms[$detailId])): ?>
    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Mulai</th><th>Selesai</th><th>Durasi</th><th>Jenis</th><th>Severity</th><th>Effect</th><th>Pesan</th><th>Resolusi / PIC</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($alarms[$detailId] as $a): ?>
        <?php $alarmDuration = (!empty($a['created_at']) && !empty($a['resolved_at'])) ? max(0, (strtotime($a['resolved_at']) - strtotime($a['created_at'])) * 1000) : 0; ?>
        <tr><td><?= !empty($a['created_at']) ? esc(date('H:i:s', strtotime($a['created_at']))) : '-' ?></td><td><?= !empty($a['resolved_at']) ? esc(date('H:i:s', strtotime($a['resolved_at']))) : '-' ?></td><td><?= !empty($a['resolved_at']) ? esc($formatMs($alarmDuration)) : '-' ?></td><td><?= esc($a['kind']) ?></td><td><?= esc($a['severity']) ?></td><td><?= esc($a['effect'] ?? '-') ?></td><td><?= esc($a['message']) ?></td><td><?= esc($a['resolution_notes'] ?? '-') ?></td><td><?= esc($a['resolved_at'] ? 'resolved' : ($a['status'] ?? 'active')) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php else: ?><p class="text-secondary">Tidak ada alarm pada shift ini.</p><?php endif; ?>
