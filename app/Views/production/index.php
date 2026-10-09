<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<div class="page-heading">
    <div>
        <h1>Production <span>Management</span></h1>
        <div class="page-subtitle">Data otomatis dari ESP32 · <?= esc($period['start']) ?> — <?= esc($period['end']) ?></div>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= esc(site_url('machine-utility'), 'attr') ?>"><i class="bi bi-bar-chart-steps me-2"></i>Machine Utility</a>
        <button class="btn btn-outline-primary" type="button" onclick="location.reload()"><i class="bi bi-arrow-clockwise me-2"></i>Refresh</button>
        <?php if (session()->get('role') === 'admin'): ?>
            <a class="btn btn-primary" href="<?= esc(site_url('production/export') . '?' . http_build_query($period + $filters), 'attr') ?>"><i class="bi bi-download me-2"></i>Export CSV</a>
        <?php endif; ?>
    </div>
</div>

<div class="action-row mb-3" role="group" aria-label="Periode produksi">
    <?php foreach (['today' => 'Hari Ini', 'month' => 'Per Bulan', 'range' => 'Rentang Tanggal'] as $mode => $label): ?>
        <a class="btn <?= $period['mode'] === $mode ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= esc(site_url('production') . '?' . http_build_query(['mode' => $mode, 'month' => date('Y-m'), 'start' => date('Y-m-d'), 'end' => date('Y-m-d')] + $filters), 'attr') ?>"><?= esc($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="<?= esc(site_url('production'), 'attr') ?>" class="panel filter-panel mb-3">
    <input type="hidden" name="mode" value="<?= esc($period['mode'], 'attr') ?>">
    <div class="row g-3 align-items-end">
        <?php if ($period['mode'] === 'month'): ?>
            <div class="col-md-3"><label class="filter-label" for="month">Bulan</label><input class="form-control" id="month" type="month" name="month" value="<?= esc($period['month'], 'attr') ?>" required></div>
        <?php elseif ($period['mode'] === 'range'): ?>
            <?php foreach (['start' => 'Dari Tanggal', 'end' => 'Sampai Tanggal'] as $key => $label): ?>
                <div class="col-md-3"><label class="filter-label" for="<?= esc($key, 'attr') ?>"><?= esc($label) ?></label><input class="form-control" id="<?= esc($key, 'attr') ?>" type="date" name="<?= esc($key, 'attr') ?>" value="<?= esc($period[$key], 'attr') ?>" required></div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach (['machine_id' => ['Mesin', $machines], 'shift_id' => ['Shift', $shifts], 'customer_id' => ['Customer', $customers]] as $key => [$label, $options]): ?>
            <div class="col-md-2"><label class="filter-label" for="<?= esc($key, 'attr') ?>"><?= esc($label) ?></label><select class="form-select" name="<?= esc($key, 'attr') ?>" id="<?= esc($key, 'attr') ?>"><option value="0">Semua</option><?php foreach ($options as $option): ?><option value="<?= (int) $option['id'] ?>" <?= $filters[$key] === (int) $option['id'] ? 'selected' : '' ?>><?= esc($option['name']) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>

        <div class="col-md-2"><label class="filter-label" for="per_page">Baris</label><select class="form-select" id="per_page" name="per_page"><?php foreach ($pagination['options'] as $option): ?><option value="<?= (int) $option ?>" <?= (int) $pagination['per_page'] === (int) $option ? 'selected' : '' ?>><?= (int) $option ?> / halaman</option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Tampilkan</button></div>
    </div>
</form>

<div class="stat-grid cols-5">
    <?php foreach (['gross' => ['Gross Production', 'blue', 'bi-box-seam', ''], 'good' => ['Final Good', 'green', 'bi-check-circle', 'text-success'], 'reject' => ['Cacat', 'purple', 'bi-exclamation-triangle', 'text-danger'], 'target' => ['Plan Shift', 'blue', 'bi-bullseye', 'text-primary'], 'open' => ['Production Aktif', 'green', 'bi-activity', 'text-success']] as $key => [$label, $tone, $icon, $textClass]): ?>
        <div class="stat-card <?= $tone ?>"><div class="stat-label"><?= esc($label) ?></div><div class="stat-value <?= $textClass ?>"><?= number_format((int) ($summary[$key] ?? 0), 0, ',', '.') ?></div><div class="stat-icon <?= $textClass ?: 'text-primary' ?>"><i class="bi <?= $icon ?>"></i></div></div>
    <?php endforeach; ?>
</div>

<div class="alert alert-light border d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
    <div><strong>Runtime machine dipindahkan ke Machine Utility.</strong><div class="small text-secondary">Detail cycle, alarm, tools, dan defect sekarang dimuat saat tombol detail dibuka supaya halaman list tetap ringan.</div></div>
    <a class="btn btn-sm btn-outline-primary" href="<?= esc(site_url('machine-utility'), 'attr') ?>">Buka Machine Utility</a>
</div>

<div class="panel table-card">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 pt-3">
        <div class="small text-secondary">Menampilkan <?= number_format(count($records)) ?> dari <?= number_format((int) $pagination['total']) ?> shift detail</div>
        <div class="small text-secondary">Halaman <?= (int) $pagination['page'] ?> / <?= (int) $pagination['pages'] ?></div>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Tanggal / Kode</th><th>Mesin / Shift</th><th>Part / Operator-PIC</th><th>Process</th><th>Gross Shift</th><th>Good</th><th>Cacat</th><th>Plan Shift</th><th>Status Shift</th><th>Detail</th></tr></thead>
            <tbody>
            <?php foreach ($records as $r): ?>
                <?php
                $detailStatus = $r['detail_status'] ?? 'unknown';
                $statusColor = match ($detailStatus) {
                    'completed' => 'success', 'running' => 'primary', 'paused' => 'warning',
                    'service_required' => 'danger', 'operator_change_required' => 'danger', 'awaiting_defects' => 'info', default => 'secondary',
                };
                ?>
                <tr>
                    <td><?= esc($r['detail_work_date'] ?: $r['production_date']) ?><div class="mono text-secondary small"><?= esc($r['production_code']) ?></div></td>
                    <td>Slot <?= esc($r['slot_no']) ?> · <?= esc($r['machine_name']) ?><div><?= esc($r['shift_name'] ?? '-') ?></div></td>
                    <td><strong><?= esc($r['part_number']) ?></strong><div class="small"><?= esc(($r['mode'] ?? 'production') === 'setting' ? ($r['pic_name'] ?? '-') : ($r['operator_name'] ?? '-')) ?></div></td>
                    <td><strong><?= esc($r['process_name_snapshot'] ?? 'Single Process') ?></strong><div class="small text-secondary">P<?= (int) ($r['process_no'] ?? 1) ?><?php if (strtolower((string) ($r['process_mode_snapshot'] ?? '')) !== 'none'): ?> · <?= esc(strtoupper((string) ($r['process_mode_snapshot'] ?? 'auto'))) ?><?php endif; ?></div></td>
                    <td><?= number_format((int) ($r['shift_actual_qty'] ?? 0)) ?></td>
                    <td><?= number_format((int) ($r['good_qty'] ?? 0)) ?></td>
                    <td><?= number_format((int) ($r['reject_qty'] ?? 0)) ?></td>
                    <td><?= number_format((int) ($r['detail_target_qty'] ?? 0)) ?></td>
                    <td><span class="badge bg-<?= $statusColor ?>"><?= esc(ucwords(str_replace('_', ' ', $detailStatus))) ?></span><div class="small text-secondary mt-1">Production: <?= esc($r['status']) ?></div></td>
                    <td><button type="button" class="action-icon production-detail-button" data-bs-toggle="modal" data-bs-target="#productionDetailModal" data-detail-url="<?= esc(site_url('production/detail/' . (int) $r['detail_id']), 'attr') ?>" data-title="<?= esc($r['production_code'] . ' · ' . ($r['shift_name'] ?? '-'), 'attr') ?>" aria-label="Lihat detail <?= esc($r['production_code'], 'attr') ?>"><i class="bi bi-eye"></i></button></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$records): ?><tr><td colspan="10" class="text-center p-4">Belum ada produksi pada periode ini.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ((int) $pagination['pages'] > 1): ?>
    <?php
    $currentPage = (int) $pagination['page'];
    $totalPages = (int) $pagination['pages'];
    $startPage = max(1, $currentPage - 2);
    $endPage = min($totalPages, $currentPage + 2);
    $baseQuery = $period + $filters + ['per_page' => (int) $pagination['per_page']];
    ?>
    <nav class="mt-3" aria-label="Pagination production"><ul class="pagination justify-content-center">
        <?php if ($currentPage > 1): ?><li class="page-item"><a class="page-link" href="<?= esc(site_url('production') . '?' . http_build_query($baseQuery + ['page' => $currentPage - 1]), 'attr') ?>">‹</a></li><?php endif; ?>
        <?php if ($startPage > 1): ?><li class="page-item"><a class="page-link" href="<?= esc(site_url('production') . '?' . http_build_query($baseQuery + ['page' => 1]), 'attr') ?>">1</a></li><?php if ($startPage > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><?php endif; ?>
        <?php for ($i = $startPage; $i <= $endPage; $i++): ?><li class="page-item <?= $i === $currentPage ? 'active' : '' ?>"><a class="page-link" href="<?= esc(site_url('production') . '?' . http_build_query($baseQuery + ['page' => $i]), 'attr') ?>"><?= $i ?></a></li><?php endfor; ?>
        <?php if ($endPage < $totalPages): ?><?php if ($endPage < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?= esc(site_url('production') . '?' . http_build_query($baseQuery + ['page' => $totalPages]), 'attr') ?>"><?= $totalPages ?></a></li><?php endif; ?>
        <?php if ($currentPage < $totalPages): ?><li class="page-item"><a class="page-link" href="<?= esc(site_url('production') . '?' . http_build_query($baseQuery + ['page' => $currentPage + 1]), 'attr') ?>">›</a></li><?php endif; ?>
    </ul></nav>
<?php endif; ?>

<div class="modal fade" id="productionDetailModal" tabindex="-1" aria-labelledby="productionDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="productionDetailModalLabel">Production Detail</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
            <div class="modal-body" id="productionDetailBody"><div class="text-center p-5 text-secondary"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Memuat detail...</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('productionDetailModal');
    const body = document.getElementById('productionDetailBody');
    const title = document.getElementById('productionDetailModalLabel');
    let activeUrl = null;
    let requestController = null;

    const loading = () => {
        body.innerHTML = '<div class="text-center p-5 text-secondary"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Memuat detail...</div>';
    };

    const loadDetail = async (url) => {
        if (!url) return;
        activeUrl = url;
        if (requestController) requestController.abort();
        requestController = new AbortController();
        loading();
        try {
            const response = await fetch(url, {
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                signal: requestController.signal
            });
            if (!response.ok) throw new Error('HTTP ' + response.status);
            body.innerHTML = await response.text();
        } catch (error) {
            if (error.name === 'AbortError') return;
            body.innerHTML = '<div class="alert alert-danger mb-0">Detail gagal dimuat. Silakan tutup modal lalu coba lagi.</div>';
        }
    };

    modal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        const url = trigger?.dataset?.detailUrl;
        title.textContent = trigger?.dataset?.title || 'Production Detail';
        loadDetail(url);
    });

    modal.addEventListener('hidden.bs.modal', () => {
        activeUrl = null;
        if (requestController) requestController.abort();
        body.innerHTML = '';
    });

    body.addEventListener('click', (event) => {
        const link = event.target.closest('a.production-detail-page');
        if (!link) return;
        event.preventDefault();
        loadDetail(link.href);
    });

    <?php if ($period['mode'] === 'today'): ?>
    setInterval(() => {
        if (!document.hidden && !document.querySelector('.modal.show') && !document.activeElement.closest('form')) {
            location.reload();
        }
    }, 15000);
    <?php endif; ?>
});
</script>

<?= $this->endSection() ?>
