<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>


<div class="page-heading">
    <div>
        <h1>History <span>Device Transfer</span></h1>
        <div class="page-subtitle"><?= (int) ($pagination['total'] ?? count($devices)) ?> Tracked Devices · <?= (int) ($logsPagination['total'] ?? count($logs)) ?> Events</div>
    </div>
    <div class="action-row"><a class="btn btn-light border" href="<?= site_url('/master-data/tpms') ?>"><i class="bi bi-display me-2"></i>Monitor TPMS</a></div>
</div>

<?= view('components/master-transfer', ['transfers' => [['resource' => 'device-assignments', 'label' => 'Device Assignments', 'import' => true, 'template' => 'device_assignments_import.xlsx']]]) ?>
<div class="panel info-banner mt-3"><i class="bi bi-info-circle me-2 text-primary"></i>This page records physical movement of TPMS devices by MAC address between slots/machines. Credential device tidak ditampilkan di UI dan tidak disimpan pada history perpindahan.</div>

<div class="stat-grid mt-3">
    <div class="stat-card purple">
        <div class="stat-label">Total Events</div>
        <div class="stat-value"><?= (int) ($logsPagination['total'] ?? count($logs)) ?></div>
        <div class="stat-meta text-primary">register + move</div>
        <div class="stat-icon text-primary"><i class="bi bi-clock-history"></i></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Moved (30 days)</div>
        <div class="stat-value text-warning">2</div>
        <div class="stat-meta text-warning">device moved slots</div>
        <div class="stat-icon text-warning"><i class="bi bi-arrow-left-right"></i></div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Tracked Devices</div>
        <div class="stat-value"><?= (int) ($pagination['total'] ?? count($devices)) ?></div>
        <div class="stat-meta text-primary">unique MAC</div>
        <div class="stat-icon text-primary"><i class="bi bi-cpu"></i></div>
    </div>
    <div class="stat-card red">
        <div class="stat-label">Most Frequently Moved</div>
        <div class="stat-value text-danger">1</div>
        <div class="stat-meta text-danger">Last 30 days</div>
        <div class="stat-icon text-danger"><i class="bi bi-fast-forward"></i></div>
    </div>
</div>

<div class="panel table-card mb-4">
    <div class="panel-header">
        <h2 class="panel-title"><i class="bi bi-cpu me-2 text-primary"></i>Current TPMS Devices</h2>
        <form method="get" action="<?= current_url() ?>" class="d-flex flex-wrap gap-2">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">
<input class="form-control" style="max-width:280px" name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>" placeholder="Search MAC / IP / slot..."><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></form>
    </div>
    <div class="table-responsive">
        <table class="table" id="deviceTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'mac', 'label' => 'Device (MAC)', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'credential', 'label' => 'Credential', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'installed', 'label' => 'Installed In', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'ip', 'label' => 'IP', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'moves', 'label' => 'Move Count', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'last', 'label' => 'Last Event', 'pagination' => $pagination]) ?>
                    <th>History</th>
</tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $d): ?>
                    <tr>
                        <td class="mono"><?= esc($d['mac']) ?></td>
                        <td><span class="badge-soft muted"><?= esc($d['credential'] ?? 'Hidden') ?></span></td>
                        <td><strong><?= esc($d['slot']) ?></strong>
                            <div class="secondary"><?= esc($d['machine']) ?></div>
                        </td>
                        <td><span class="badge-soft <?= $d['status'] === 'run' ? 'success' : 'muted' ?>"><?= ucfirst(esc($d['status'])) ?></span></td>
                        <td class="mono"><?= esc($d['ip']) ?></td>
                        <td><?= esc($d['moves']) ?></td>
                        <td><?= esc($d['last']) ?></td>
                        <td><button class="btn btn-sm btn-light text-primary" data-bs-toggle="modal" data-bs-target="#historyModal">View</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>

<div class="panel table-card">
    <div class="panel-header">
        <h2 class="panel-title"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Transfer Log</h2>
        <form method="get" action="<?= current_url() ?>" class="d-flex flex-wrap gap-2">
            <?php foreach ((service('request')->getGet() ?? []) as $k => $val): ?>
                <?php if ($k !== 'log_date' && $k !== 'log_page' && is_scalar($val)): ?>
                    <input type="hidden" name="<?= esc((string)$k, 'attr') ?>" value="<?= esc((string)$val, 'attr') ?>">
                <?php endif ?>
            <?php endforeach ?>
            <input type="date" name="log_date" value="<?= esc((string)(service('request')->getGet('log_date') ?? ''), 'attr') ?>" class="form-control form-control-sm" style="width:160px">
            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'time', 'label' => 'Time', 'pagination' => $logsPagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'mac', 'label' => 'Device (MAC)', 'pagination' => $logsPagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'type', 'label' => 'Type', 'pagination' => $logsPagination]) ?>
                    <th>Credential</th>
                    <?= view('components/table-sort-th', ['key' => 'movement', 'label' => 'Movement', 'pagination' => $logsPagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'ip', 'label' => 'IP', 'pagination' => $logsPagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'notes', 'label' => 'Notes', 'pagination' => $logsPagination]) ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= esc($log['time']) ?></td>
                        <td class="mono"><?= esc($log['mac']) ?></td>
                        <td><span class="badge-soft warning"><i class="bi bi-arrow-left-right"></i><?= esc($log['type']) ?></span></td>
                        <td><span class="badge-soft muted"><?= esc($log['credential'] ?? 'Hidden') ?></span></td>
                        <td><strong><?= esc($log['from']) ?></strong> <span class="text-warning mx-2">→</span> <strong><?= esc($log['to']) ?></strong></td>
                        <td class="mono"><?= esc($log['ip']) ?></td>
                        <td class="secondary"><?= esc($log['notes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $logsPagination]) ?>

</div>

<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Device Movement History</h5><button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Nantinya modal ini menampilkan timeline lengkap perpindahan device berdasarkan MAC address.</p>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>