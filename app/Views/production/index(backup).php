<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$statusClass = static function (?string $status): string {
    return match ($status) {
        'completed' => 'success',
        'running' => 'primary',
        'paused' => 'warning',
        'service_required' => 'danger',
        'awaiting_defects' => 'info',
        default => 'secondary',
    };
};
?>

<div class="page-heading">
    <div>
        <h1>Production</h1>
        <p class="text-secondary">
            Data otomatis dari ESP32 · <?= esc($period['start']) ?> — <?= esc($period['end']) ?>
        </p>
    </div>
    <a
        class="btn btn-success"
        href="<?= esc(site_url('production/export') . '?' . http_build_query($period + $filters), 'attr') ?>">
        Export CSV
    </a>
</div>

<div class="d-flex gap-2 flex-wrap mb-3" role="group" aria-label="Periode produksi">
    <?php foreach (['today' => 'Hari Ini', 'month' => 'Per Bulan', 'range' => 'Rentang Tanggal'] as $mode => $label): ?>
        <a
            class="btn <?= $period['mode'] === $mode ? 'btn-primary' : 'btn-outline-primary' ?>"
            href="<?= esc(site_url('production') . '?' . http_build_query([
                'mode' => $mode,
                'month' => date('Y-m'),
                'start' => date('Y-m-d'),
                'end' => date('Y-m-d'),
            ] + $filters), 'attr') ?>">
            <?= esc($label) ?>
        </a>
    <?php endforeach; ?>
    <button class="btn btn-outline-secondary" type="button" onclick="location.reload()">Perbarui Data</button>
</div>

<form method="get" action="<?= site_url('production') ?>" class="panel p-3 mb-3">
    <input type="hidden" name="mode" value="<?= esc($period['mode'], 'attr') ?>">
    <div class="row g-3 align-items-end">
        <?php if ($period['mode'] === 'month'): ?>
            <div class="col-md-3">
                <label class="form-label" for="month">Bulan</label>
                <input class="form-control" id="month" type="month" name="month" value="<?= esc($period['month'], 'attr') ?>" required>
            </div>
        <?php elseif ($period['mode'] === 'range'): ?>
            <?php foreach (['start' => 'Dari Tanggal', 'end' => 'Sampai Tanggal'] as $key => $label): ?>
                <div class="col-md-3">
                    <label class="form-label" for="<?= esc($key, 'attr') ?>"><?= esc($label) ?></label>
                    <input class="form-control" id="<?= esc($key, 'attr') ?>" type="date" name="<?= esc($key, 'attr') ?>" value="<?= esc($period[$key], 'attr') ?>" required>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach (['machine_id' => ['Mesin', $machines], 'shift_id' => ['Shift', $shifts], 'customer_id' => ['Customer', $customers]] as $key => [$label, $options]): ?>
            <div class="col-md-2">
                <label class="form-label" for="<?= esc($key, 'attr') ?>"><?= esc($label) ?></label>
                <select class="form-select" name="<?= esc($key, 'attr') ?>" id="<?= esc($key, 'attr') ?>">
                    <option value="0">Semua</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= (int) $option['id'] ?>" <?= $filters[$key] === (int) $option['id'] ? 'selected' : '' ?>>
                            <?= esc($option['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>

        <div class="col-md-2">
            <button class="btn btn-primary w-100">Tampilkan</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-3">
    <?php foreach (['actual' => 'Aktual Production', 'good' => 'Good', 'reject' => 'Cacat', 'target' => 'Target Production', 'open' => 'Production Aktif'] as $key => $label): ?>
        <div class="col">
            <div class="panel p-3">
                <div class="text-secondary"><?= esc($label) ?></div>
                <strong class="fs-3"><?= number_format($summary[$key]) ?></strong>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<p class="text-secondary small">
    State pause/service berada pada detail shift. Header production tetap aktif sampai target keseluruhan tercapai.
</p>

<?php foreach ($records as $record): ?>
    <?php foreach ($alarms[(int) ($record['detail_id'] ?? 0)] ?? [] as $alarm): ?>
        <?php if ($alarm['resolved_at'] !== null || ($alarm['effect'] ?? '') === 'notify') { continue; } ?>
        <div class="alert <?= ($alarm['effect'] ?? '') === 'service_stop' ? 'alert-danger' : 'alert-warning' ?>" role="alert">
            <strong><?= esc($record['production_code']) ?> · <?= esc($record['shift_name'] ?? '-') ?>:</strong>
            <?= esc($alarm['message']) ?>
            <span class="badge text-bg-light ms-2"><?= esc($alarm['severity']) ?></span>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<div class="panel table-responsive">
    <table class="table align-middle">
        <thead>
        <tr>
            <th>Tanggal / Kode</th>
            <th>Mesin / Shift</th>
            <th>Part / Operator</th>
            <th>Aktual Shift</th>
            <th>Good</th>
            <th>Cacat</th>
            <th>Target Shift</th>
            <th>Status Shift</th>
            <th>Detail</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
            <?php $detailStatus = $r['detail_status'] ?? '-'; ?>
            <tr>
                <td>
                    <?= esc($r['detail_work_date'] ?: $r['production_date']) ?>
                    <div class="small text-secondary"><?= esc($r['production_code']) ?></div>
                </td>
                <td>
                    Slot <?= esc($r['slot_no']) ?> · <?= esc($r['machine_name']) ?>
                    <div><?= esc($r['shift_name'] ?? '-') ?></div>
                </td>
                <td>
                    <?= esc($r['part_number']) ?>
                    <div class="small"><?= esc($r['operator_name'] ?? '-') ?></div>
                </td>
                <td><?= number_format((int) ($r['shift_actual_qty'] ?? 0)) ?></td>
                <td><?= number_format((int) ($r['good_qty'] ?? 0)) ?></td>
                <td><?= number_format((int) ($r['reject_qty'] ?? 0)) ?></td>
                <td><?= number_format((int) ($r['detail_target_qty'] ?? 0)) ?></td>
                <td>
                    <span class="badge bg-<?= esc($statusClass($detailStatus), 'attr') ?>">
                        <?= esc($detailStatus) ?>
                    </span>
                    <div class="small text-secondary mt-1">
                        Production: <?= esc($r['status']) ?>
                    </div>
                </td>
                <td>
                    <?php if (!empty($r['detail_id'])): ?>
                        <button
                            class="btn btn-sm btn-outline-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#production<?= (int) $r['id'] ?>Detail<?= (int) $r['detail_id'] ?>">
                            Lihat Detail
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <?php if (!$records): ?>
            <tr>
                <td colspan="9" class="text-center p-4">Belum ada produksi pada periode ini.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php foreach ($records as $r): ?>
    <?php if (empty($r['detail_id'])) { continue; } ?>
    <div class="modal fade" id="production<?= (int) $r['id'] ?>Detail<?= (int) $r['detail_id'] ?>" tabindex="-1" aria-label="Detail produksi">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= esc($r['production_code']) ?> · <?= esc($r['shift_name'] ?? '-') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                </div>
                <div class="modal-body">
                    <p><?= esc($r['part_name']) ?> · <?= esc($r['customer_name']) ?></p>
                    <p>
                        Mulai shift: <?= esc($r['detail_started_at'] ?? '-') ?> ·
                        Selesai shift: <?= esc($r['detail_ended_at'] ?? '-') ?>
                    </p>
                    <p>
                        Aktual shift: <strong><?= number_format((int) ($r['shift_actual_qty'] ?? 0)) ?></strong> ·
                        Aktual production: <strong><?= number_format((int) $r['production_actual_qty']) ?>/<?= number_format((int) $r['production_target_qty']) ?></strong>
                    </p>

                    <h6>Pemakaian Tools</h6>
                    <table class="table">
                        <thead>
                        <tr>
                            <th>Tool</th>
                            <th>Posisi</th>
                            <th>Awal</th>
                            <th>Akhir</th>
                            <th>Pemakaian</th>
                            <th>Limit</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tools[(int) $r['detail_id']] ?? [] as $t): ?>
                            <tr>
                                <td><?= esc($t['code']) ?></td>
                                <td><?= esc($t['position_snapshot'] ?? '-') ?></td>
                                <td><?= (int) $t['start_lifetime'] ?></td>
                                <td><?= (int) $t['end_lifetime'] ?></td>
                                <td><?= (int) $t['quantity_increment'] ?></td>
                                <td><?= (int) $t['set_lifetime_snapshot'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h6>Data Cacat</h6>
                    <?php if (!empty($defects[(int) $r['detail_id']])): ?>
                        <?php foreach ($defects[(int) $r['detail_id']] as $d): ?>
                            <p><?= esc($d['defect_type']) ?>: <?= (int) $d['quantity'] ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-secondary">Belum ada data cacat.</p>
                    <?php endif; ?>

                    <h6>Riwayat Alarm Shift</h6>
                    <?php if (!empty($alarms[(int) $r['detail_id']])): ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                <tr>
                                    <th>Waktu</th>
                                    <th>Jenis</th>
                                    <th>Severity</th>
                                    <th>Effect</th>
                                    <th>Pesan</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($alarms[(int) $r['detail_id']] as $a): ?>
                                    <tr>
                                        <td><?= esc($a['created_at']) ?></td>
                                        <td><?= esc($a['kind']) ?></td>
                                        <td><?= esc($a['severity']) ?></td>
                                        <td><?= esc($a['effect'] ?? '-') ?></td>
                                        <td><?= esc($a['message']) ?></td>
                                        <td><?= esc($a['resolved_at'] ? 'resolved' : ($a['status'] ?? 'active')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-secondary">Tidak ada alarm pada shift ini.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($period['mode'] === 'today'): ?>
    setInterval(() => {
        if (
            !document.hidden
            && !document.querySelector('.modal.show')
            && !document.activeElement.closest('form')
        ) {
            location.reload();
        }
    }, 15000);
    <?php endif; ?>
});
</script>

<?= $this->endSection() ?>
