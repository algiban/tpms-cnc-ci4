<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<div class="page-heading">
    <div>
        <h1>RFID <span>Capture</span></h1>
        <div class="page-subtitle">Scan UID dari TPMS/ESP32 untuk Rack Tools, Employee, atau User.</div>
    </div>
</div>

<div class="panel p-4 mb-4">
    <div class="d-flex align-items-start gap-3">
        <div class="fs-2 text-primary"><i class="bi bi-broadcast-pin"></i></div>
        <div>
            <h2 class="h5 mb-1">Cara menggunakan</h2>
            <p class="text-secondary mb-0">Pada form Rack Tools, Employee, atau User klik <strong>Baca RFID</strong>, kemudian scan kartu/tag pada TPMS. Website hanya mengambil scan baru setelah tombol ditekan, sehingga UID lama tidak akan terisi otomatis.</p>
        </div>
    </div>
</div>

<div class="panel table-card">
    <div class="panel-header">
        <h2 class="panel-title"><i class="bi bi-clock-history me-2 text-primary"></i>50 Scan Terbaru</h2>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr><th>Time</th><th>UID</th><th>Purpose</th><th>TPMS</th><th>Slot / Machine</th></tr>
            </thead>
            <tbody>
            <?php foreach ($scans ?? [] as $scan): ?>
                <tr>
                    <td><?= esc($scan['scanned_at'] ?? '-') ?></td>
                    <td class="mono fw-semibold"><?= esc($scan['uid'] ?? '-') ?></td>
                    <td><span class="badge-soft info"><?= esc(strtoupper($scan['purpose'] ?? 'generic')) ?></span></td>
                    <td class="mono"><?= esc($scan['mac_address'] ?? '-') ?></td>
                    <td>Slot <?= esc($scan['slot_no'] ?? '-') ?> · <?= esc($scan['machine_name'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($scans)): ?>
                <tr><td colspan="5" class="text-center text-secondary p-4">Belum ada RFID scan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?= $this->endSection() ?>
