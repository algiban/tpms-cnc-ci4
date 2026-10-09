<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php $total = (int) ($tpmsStats['total'] ?? count($devices));
$connected = (int) ($tpmsStats['online'] ?? 0); ?>

<div class="page-heading">
    <div>
        <h1>TPMS <span>Management</span></h1>
        <div class="page-subtitle"><?= $total ?> Devices · <?= $connected ?> Connected</div>
    </div>
    <div class="action-row">
        <form method="post" action="<?= site_url('/master-data/tpms/scan-connections') ?>"><?= csrf_field() ?><button class="btn btn-primary"><i class="bi bi-wifi me-2"></i>Scan Connections</button></form>
    </div>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'tpms', 'label' => 'TPMS Devices', 'import' => true, 'template' => 'tpms_import.xlsx']]]) ?>
<div class="stat-grid mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total TPMS</div>
        <div class="stat-value"><?= $total ?></div>
        <div class="stat-icon text-primary"><i class="bi bi-display"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Connected</div>
        <div class="stat-value text-success"><?= $connected ?></div>
        <div class="stat-icon text-success"><i class="bi bi-router"></i></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Has FW/HMI</div>
        <div class="stat-value"><?= (int) ($tpmsStats['versioned'] ?? 0) ?></div>
        <div class="stat-icon text-primary"><i class="bi bi-cpu"></i></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Offline</div>
        <div class="stat-value text-warning"><?= $total - $connected ?></div>
        <div class="stat-icon text-warning"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
</div>
<div class="panel info-banner"><strong><i class="bi bi-info-circle me-2"></i>Automatic device registry.</strong> ESP32 register/heartbeat ke API. MAC address menjadi identitas fisik device, sedangkan IP dapat berubah.</div>
<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

<label class="filter-label">Search TPMS</label>
<div class="d-flex gap-2"><input class="form-control" name="q" value="<?= esc((string)(service('request')->getGet('q') ?? ''), 'attr') ?>" placeholder="Slot / machine / MAC / IP / firmware..."><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></div></form>
<div class="panel table-card">
    <div class="table-responsive">
        <table class="table" id="tpmsTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'machine', 'label' => 'Slot / Machine', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'firmware', 'label' => 'FW / HMI', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'network', 'label' => 'Network', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'connection', 'label' => 'Connection', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'last_seen', 'label' => 'Last Seen', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'credential', 'label' => 'Credential', 'pagination' => $pagination]) ?>
</tr>
            </thead>
            <tbody><?php foreach ($devices as $d): ?><tr>
                        <td><strong><?= esc($d['slot']) ?></strong>
                            <div class="secondary"><?= esc($d['machine']) ?></div>
                        </td>
                        <td>
                            <div class="mono">FW: <?= esc($d['fw']) ?></div>
                            <div class="mono secondary">HMI: <?= esc($d['hmi']) ?></div>
                        </td>
                        <td>
                            <div class="mono"><?= esc($d['ip']) ?></div>
                            <div class="mono secondary"><?= esc($d['mac']) ?></div>
                        </td>
                        <td><span class="badge-soft <?= $d['status'] === 'offline' ? 'muted' : ($d['status'] === 'idle' ? 'warning' : 'success') ?>"><?= ucfirst(esc($d['status'])) ?></span></td>
                        <td><span class="badge-soft <?= $d['connection'] === 'reachable' ? 'success' : ($d['connection'] === 'unreachable' ? 'danger' : 'muted') ?>"><?= esc($d['connection']) ?></span>
                            <div class="secondary"><?= esc($d['last_connection_check_at'] ?? '-') ?></div>
                        </td>
                        <td><?= esc($d['updated']) ?></td>
                        <td><span class="badge-soft muted"><?= esc($d['credential'] ?? 'Hidden') ?></span></td>
                    </tr><?php endforeach; ?></tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>
<?= $this->endSection() ?>