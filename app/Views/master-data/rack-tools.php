<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$racks = $racks ?? [];
$parts = $parts ?? [];
$tools = $tools ?? [];
?>


<div class="page-heading">
    <div>
        <h1>Rack Tools <span>Management</span></h1>
        <div class="page-subtitle">
            <?= (int) ($rackStats['total'] ?? count($racks)) ?> Racks · Part &amp; Tool Grouping
        </div>
    </div>
    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addRackModal">
                <i class="bi bi-plus-lg me-2"></i>Add Rack Tools
            </button>
        </div>
    <?php endif; ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'rack-tools', 'label' => 'Rack Tools', 'import' => true, 'template' => 'rack_tools_import.xlsx']]]) ?>

<form class="panel filter-panel mt-4" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <label for="rackSearch" class="filter-label">
        Search Rack / Part / Tool
    </label>
    <input
        type="search"
        id="rackSearch"
        class="form-control"
        placeholder="RACK-BBP0, part, tool..."
         name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
<div class="mt-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></div>
</form>

<div class="panel table-card">
    <div class="table-responsive">
        <table class="table" id="rackTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'rack', 'label' => 'Rack', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'parts', 'label' => 'Parts', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'tools', 'label' => 'Tools', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'location', 'label' => 'Location', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <th>Actions</th>
</tr>
            </thead>

            <tbody>
                <?php foreach ($racks as $index => $r): ?>
                    <?php
                    $modalKey = 'rack' . bin2hex((string) $index);
                    $rackParts = $r['parts'] ?? [];
                    $rackTools = $r['tools'] ?? [];
                    $status = $r['status'] ?? 'available';

                    $statusClass = $status === 'available'
                        ? 'success'
                        : ($status === 'maintenance' ? 'warning' : 'info');
                    ?>

                    <tr>
                        <td>
                            <strong class="mono">
                                <?= esc($r['code'] ?? '') ?>
                            </strong>
                            <div class="secondary">
                                <?= esc($r['name'] ?? '') ?>
                            </div>
                            <div class="secondary">
                                UID: <?= esc($r['uid'] ?? '-') ?>
                            </div>
                        </td>

                        <td>
                            <?php foreach ($rackParts as $part): ?>
                                <span class="badge-soft info me-1 mb-1">
                                    <?= esc($part) ?>
                                </span>
                            <?php endforeach; ?>

                            <?php if (empty($rackParts)): ?>
                                <span class="text-secondary">No parts assigned.</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php foreach ($rackTools as $tool): ?>
                                <span class="badge-soft purple me-1 mb-1">
                                    <?= esc($tool) ?>
                                </span>
                            <?php endforeach; ?>

                            <?php if (empty($rackTools)): ?>
                                <span class="text-secondary">No tools assigned.</span>
                            <?php endif; ?>
                        </td>

                        <td><?= esc($r['location'] ?? '-') ?></td>

                        <td>
                            <span class="badge-soft <?= $statusClass ?>">
                                <?= esc(ucfirst($status)) ?>
                            </span>
                        </td>

                        <td class="text-nowrap">
                            <button
                                type="button"
                                class="action-icon"
                                data-bs-toggle="modal"
                                data-bs-target="#<?= $modalKey ?>Detail"
                                aria-label="View rack detail">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <button
                                    type="button"
                                    class="action-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Assign"
                                    aria-label="Assign parts and tools">
                                    <i class="bi bi-link-45deg"></i>
                                </button>

                                <button
                                    type="button"
                                    class="action-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Edit"
                                    aria-label="Edit rack">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($racks)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-secondary">
                            No racks available.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>

<?php foreach ($racks as $index => $r): ?>
    <?php
    $modalKey = 'rack' . bin2hex((string) $index);
    $rackId = rawurlencode((string) $r['id']);
    $rackParts = $r['parts'] ?? [];
    $rackTools = $r['tools'] ?? [];
    $partRows = $r['part_rows'] ?? [];
    $toolRows = $r['tool_rows'] ?? [];
    $status = $r['status'] ?? 'available';
    ?>

    <div
        class="modal fade"
        id="<?= $modalKey ?>Detail"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>DetailLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="<?= $modalKey ?>DetailLabel">
                        Rack Tools Detail · <?= esc($r['code'] ?? '') ?>
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-4">
                        <div class="col-lg-5">
                            <div class="detail-section-title">
                                Compatible Parts
                            </div>

                            <?php foreach ($partRows as $p): ?>
                                <div class="detail-item mb-2">
                                    <strong class="mono">
                                        <?= esc($p['part_number'] ?? '-') ?>
                                    </strong>
                                    <small><?= esc($p['name'] ?? '') ?></small>
                                </div>
                            <?php endforeach; ?>

                            <?php if (empty($partRows)): ?>
                                <p class="text-secondary mb-0">
                                    No parts assigned.
                                </p>
                            <?php endif; ?>
                        </div>

                        <div class="col-lg-7">
                            <div class="detail-section-title">
                                Tools in Rack
                            </div>

                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Position</th>
                                            <th>Tool</th>
                                            <th>Side</th>
                                            <th>Actual</th>
                                            <th>Default Lifetime</th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        <?php foreach ($toolRows as $t): ?>
                                            <tr>
                                                <td><?= esc($t['position'] ?? '-') ?></td>
                                                <td>
                                                    <?= esc(
                                                        ($t['code'] ?? '') . ' · ' . ($t['name'] ?? '')
                                                    ) ?>
                                                </td>
                                                <td><strong><?= max(1, (int) ($t['current_edge'] ?? 1)) ?>/<?= max(1, (int) ($t['cutting_edge'] ?? 1)) ?></strong></td>
                                                <td><?= esc($t['actual_lifetime'] ?? 0) ?></td>
                                                <td><?= esc($t['default_lifetime'] ?? '-') ?></td>
                                            </tr>
                                        <?php endforeach; ?>

                                        <?php if (empty($toolRows)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-secondary">
                                                    No tools assigned.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="<?= $modalKey ?>Assign"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>AssignLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form
                    method="post"
                    action="<?= esc(site_url('master-data/rack-tools/' . $rackId . '/sync'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= $modalKey ?>AssignLabel">
                            Assign Parts &amp; Tools · <?= esc($r['code'] ?? '') ?>
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p class="text-secondary">
                            Satu rack dapat berisi banyak parts dan banyak tools.
                            Pilih semua item yang ingin dipasang pada rack ini.
                        </p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label
                                    for="<?= $modalKey ?>Parts"
                                    class="form-label">
                                    Parts
                                </label>

                                <select
                                    id="<?= $modalKey ?>Parts"
                                    class="form-select"
                                    name="part_ids[]"
                                    multiple
                                    size="9"
                                    aria-describedby="<?= $modalKey ?>PartsHelp">
                                    <?php foreach ($parts as $p): ?>
                                        <option
                                            value="<?= esc((string) $p['id'], 'attr') ?>"
                                            <?= in_array($p['part_number'], $rackParts, true) ? 'selected' : '' ?>>
                                            <?= esc(
                                                $p['part_number'] . ' · ' . ($p['name'] ?? '')
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div
                                    id="<?= $modalKey ?>PartsHelp"
                                    class="form-text">
                                    <?php if (empty($parts)): ?>
                                        Belum ada data parts.
                                    <?php else: ?>
                                        Tahan Ctrl (Windows) atau Command (Mac)
                                        untuk memilih atau membatalkan beberapa parts.
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label
                                    for="<?= $modalKey ?>Tools"
                                    class="form-label">
                                    Tools
                                </label>

                                <select
                                    id="<?= $modalKey ?>Tools"
                                    class="form-select"
                                    name="tool_ids[]"
                                    multiple
                                    size="9"
                                    aria-describedby="<?= $modalKey ?>ToolsHelp">
                                    <?php foreach ($tools as $t): ?>
                                        <option
                                            value="<?= esc((string) $t['id'], 'attr') ?>"
                                            <?= in_array($t['code'], $rackTools, true) ? 'selected' : '' ?>>
                                            <?= esc(
                                                $t['code'] . ' · ' . ($t['name'] ?? '')
                                            ) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <div
                                    id="<?= $modalKey ?>ToolsHelp"
                                    class="form-text">
                                    <?php if (empty($tools)): ?>
                                        Belum ada data tools.
                                    <?php else: ?>
                                        Tahan Ctrl (Windows) atau Command (Mac)
                                        untuk memilih atau membatalkan beberapa tools.
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-primary">
                            Save Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div
        class="modal fade"
        id="<?= $modalKey ?>Edit"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>EditLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form
                    method="post"
                    action="<?= esc(site_url('master-data/rack-tools/' . $rackId . '/update'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= $modalKey ?>EditLabel">
                            Edit Rack Tools
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <label for="<?= $modalKey ?>Code" class="form-label">
                            Code
                        </label>
                        <input
                            type="text"
                            id="<?= $modalKey ?>Code"
                            class="form-control mb-3"
                            name="code"
                            value="<?= esc($r['code'] ?? '', 'attr') ?>"
                            required>

                        <label for="<?= $modalKey ?>Name" class="form-label">
                            Name
                        </label>
                        <input
                            type="text"
                            id="<?= $modalKey ?>Name"
                            class="form-control mb-3"
                            name="name"
                            value="<?= esc($r['name'] ?? '', 'attr') ?>"
                            required>

                        <label for="<?= $modalKey ?>Uid" class="form-label">
                            UID / RFID
                        </label>
                        <div class="input-group mb-1">
                            <input
                                type="text"
                                id="<?= $modalKey ?>Uid"
                                class="form-control"
                                name="uid"
                                value="<?= esc($r['uid'] ?? '', 'attr') ?>">
                            <button type="button" class="btn btn-outline-primary" data-rfid-read data-rfid-purpose="rack" data-rfid-target="#<?= $modalKey ?>Uid" data-rfid-info="#<?= $modalKey ?>UidInfo"><i class="bi bi-broadcast-pin me-1"></i>Baca RFID</button>
                        </div>
                        <div class="form-text mb-3" id="<?= $modalKey ?>UidInfo">Scan Rack Tools pada TPMS/ESP32.</div>

                        <label for="<?= $modalKey ?>Location" class="form-label">
                            Location
                        </label>
                        <input
                            type="text"
                            id="<?= $modalKey ?>Location"
                            class="form-control mb-3"
                            name="location"
                            value="<?= esc($r['location'] ?? '', 'attr') ?>">

                        <label for="<?= $modalKey ?>Status" class="form-label">
                            Status
                        </label>
                        <select
                            id="<?= $modalKey ?>Status"
                            class="form-select"
                            name="status">
                            <?php foreach (['available', 'in-use', 'maintenance'] as $s): ?>
                                <option
                                    value="<?= esc($s, 'attr') ?>"
                                    <?= $status === $s ? 'selected' : '' ?>>
                                    <?= esc($s) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-primary">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div
    class="modal fade"
    id="addRackModal"
    tabindex="-1"
    aria-labelledby="addRackModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form
                method="post"
                action="<?= esc(site_url('master-data/rack-tools'), 'attr') ?>">
                <?= csrf_field() ?>

                <div class="modal-header">
                    <h5 class="modal-title" id="addRackModalLabel">
                        Add Rack Tools
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <label for="addRackCode" class="form-label">Code</label>
                    <input
                        type="text"
                        id="addRackCode"
                        class="form-control mb-3"
                        name="code"
                        required>

                    <label for="addRackName" class="form-label">Name</label>
                    <input
                        type="text"
                        id="addRackName"
                        class="form-control mb-3"
                        name="name"
                        required>

                    <label for="addRackUid" class="form-label">UID / RFID</label>
                    <div class="input-group mb-1">
                        <input type="text" id="addRackUid" class="form-control" name="uid">
                        <button type="button" class="btn btn-outline-primary" data-rfid-read data-rfid-purpose="rack" data-rfid-target="#addRackUid" data-rfid-info="#addRackUidInfo"><i class="bi bi-broadcast-pin me-1"></i>Baca RFID</button>
                    </div>
                    <div class="form-text mb-3" id="addRackUidInfo">Klik Baca RFID lalu scan Rack Tools pada TPMS.</div>

                    <label for="addRackLocation" class="form-label">Location</label>
                    <input
                        type="text"
                        id="addRackLocation"
                        class="form-control"
                        name="location">

                    <div class="form-text mt-3">
                        Setelah rack disimpan, gunakan tombol Assign Parts &amp; Tools
                        untuk menambahkan banyak parts dan tools.
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">
                        Save Rack
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>