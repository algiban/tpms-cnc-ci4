<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$slots = $slots ?? [];
$machines = $machines ?? [];
$toolPool = $toolPool ?? [];
?>


<div class="page-heading">
    <div>
        <h1>Machine <span>Management</span></h1>
        <div class="page-subtitle">
            <?= count($slots) ?> Slots · <?= count($machines) ?> Physical Machines
        </div>
    </div>

    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button
                type="button"
                class="btn btn-dark"
                data-bs-toggle="modal"
                data-bs-target="#addSlotModal">
                <i class="bi bi-plus-lg me-2"></i>Add Slot
            </button>

            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addMachineModal">
                <i class="bi bi-plus-lg me-2"></i>Add Machine
            </button>
        </div>
    <?php endif; ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'machines', 'label' => 'Machines', 'import' => true, 'template' => 'machines_import.xlsx'], ['resource' => 'slots', 'label' => 'Slots', 'import' => true, 'template' => 'slots_import.xlsx'], ['resource' => 'machine-tools', 'label' => 'Machine Tools', 'import' => true, 'template' => 'machine_tools_import.xlsx']]]) ?>

<div class="split-grid mt-3">
    <section>
        <label for="slotSearch" class="filter-label">
            <i class="bi bi-geo-alt me-1"></i>Production Floor Slot
        </label>

        <div class="input-group mb-3">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search"></i>
            </span>
            <input
                type="search"
                id="slotSearch"
                class="form-control border-start-0"
                placeholder="Search slot, machine, serial..."
                data-machine-card-filter="slotTable">
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Production Floor Slot</h2>
                <span class="secondary"><?= count($slots) ?> slots</span>
            </div>

            <div class="slot-list">
                <div class="slot-grid" id="slotTable">
                    <?php foreach ($slots as $index => $slot): ?>
                        <?php
                        $slotKey = 'slot' . bin2hex((string) $index);
                        $hasMachine = !empty($slot['machine']);
                        $status = $slot['status'] ?? 'idle';

                        $statusClass = $status === 'running'
                            ? 'success'
                            : ($status === 'idle' ? 'warning' : 'muted');
                        ?>

                        <?php if (!$hasMachine): ?>
                            <article class="slot-card empty" data-filter-card>
                                <div>
                                    <div class="slot-id">
                                        <?= esc($slot['id'] ?? '') ?>
                                    </div>
                                    <div class="fs-3">
                                        <i class="bi bi-plus-circle"></i>
                                    </div>
                                    <div>Empty slot</div>
                                </div>
                            </article>
                        <?php else: ?>
                            <article
                                class="slot-card <?= esc($status, 'attr') ?>"
                                data-filter-card>
                                <div class="slot-top">
                                    <span class="slot-id">
                                        <?= esc($slot['id'] ?? '') ?>
                                    </span>

                                    <div class="d-flex flex-wrap gap-1 justify-content-end">
                                        <?php if (!empty($slot['tpms'])): ?>
                                            <span class="badge-soft info">
                                                <i class="bi bi-wifi me-1"></i>
                                                <?= esc($slot['tpms']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-soft muted">
                                                <i class="bi bi-wifi-off me-1"></i>No TPMS
                                            </span>
                                        <?php endif; ?>

                                        <span class="badge-soft <?= $statusClass ?>">
                                            <?= esc(ucfirst($status)) ?>
                                        </span>
                                    </div>
                                </div>

                                <div class="detail-box">
                                    <strong><?= esc($slot['machine']) ?></strong>
                                    <div class="mono text-primary">
                                        <?= esc($slot['serial'] ?? '-') ?>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    class="btn btn-outline-danger btn-sm mt-2 w-100"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $slotKey ?>Dispose">
                                    <i class="bi bi-trash me-1"></i>Dispose
                                </button>
                            </article>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($slots)): ?>
                    <p class="text-center text-secondary p-3 mb-0">
                        No slots available.
                    </p>
                <?php else: ?>
                    <p
                        class="text-center text-secondary p-3 mb-0"
                        data-filter-empty="slotTable"
                        hidden>
                        No matching slots.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section>
        <label for="machineSearch" class="filter-label">
            <i class="bi bi-wrench-adjustable me-1"></i>Physical Machine Pool
        </label>

        <div class="input-group mb-3">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search"></i>
            </span>
            <input
                type="search"
                id="machineSearch"
                class="form-control border-start-0"
                placeholder="Search name, code, serial..."
                data-machine-card-filter="machineTable">
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Physical Machine Pool</h2>
                <span class="secondary"><?= count($machines) ?> machines</span>
            </div>

            <div class="machine-pool">
                <div class="machine-grid" id="machineTable">
                    <?php foreach ($machines as $index => $machine): ?>
                        <?php
                        $machineKey = 'machine' . bin2hex((string) $index);
                        $machineId = rawurlencode((string) $machine['id']);
                        $currentSlot = $machine['slot'] ?? null;
                        $currentSlotId = $machine['slot_id'] ?? null;
                        $isAvailable = $currentSlot === null;
                        ?>

                        <article
                            class="machine-card <?= $isAvailable ? 'available' : '' ?>"
                            data-filter-card>
                            <div class="machine-top">
                                <div>
                                    <strong><?= esc($machine['name'] ?? '') ?></strong>
                                    <div class="secondary mono">
                                        <?= esc($machine['code'] ?? '') ?>
                                    </div>
                                </div>

                                <div class="d-flex flex-wrap gap-1">
                                    <?php if ($isAvailable): ?>
                                        <span class="badge-soft success">
                                            <i class="bi bi-check-lg me-1"></i>Available
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-soft purple">
                                            @ <?= esc($currentSlot) ?>
                                        </span>
                                    <?php endif; ?>

                                    <button
                                        type="button"
                                        class="action-icon"
                                        data-bs-toggle="modal"
                                        data-bs-target="#<?= $machineKey ?>Detail"
                                        aria-label="View machine detail">
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <button
                                        type="button"
                                        class="action-icon"
                                        data-bs-toggle="modal"
                                        data-bs-target="#<?= $machineKey ?>Edit"
                                        aria-label="Edit machine">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="detail-grid mb-3">
                                <div>
                                    <span class="label">Serial</span>
                                    <strong class="mono">
                                        <?= esc($machine['serial'] ?? '-') ?>
                                    </strong>
                                </div>

                                <div>
                                    <span class="label">Year</span>
                                    <strong><?= esc($machine['year'] ?? '-') ?></strong>
                                </div>
                            </div>

                            <div class="border rounded p-2 mb-3 bg-light-subtle">
                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <div>
                                        <div class="small text-secondary">Assigned Tools</div>
                                        <strong><?= count($machine['tools'] ?? []) ?> physical tools</strong>
                                    </div>
                                    <?php if (session()->get('role') === 'admin'): ?>
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#<?= $machineKey ?>Tools">
                                            <i class="bi bi-tools me-1"></i>Assign Tools
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($machine['tools'])): ?>
                                    <div class="small text-secondary mt-2">
                                        <?= esc(implode(', ', array_map(static fn($tool) => ($tool['code'] ?? '-') . ' (' . number_format((int)($tool['actual_lifetime'] ?? 0)) . ')', $machine['tools']))) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <form
                                method="post"
                                action="<?= esc(site_url('master-data/machines/' . $machineId . '/assign'), 'attr') ?>"
                                class="d-flex gap-2">
                                <?= csrf_field() ?>

                                <select
                                    id="<?= $machineKey ?>Slot"
                                    name="slot_id"
                                    class="form-select form-select-sm"
                                    aria-label="Select production slot"
                                    required>
                                    <option value="">Select slot...</option>

                                    <?php foreach ($slots as $s): ?>
                                        <?php
                                        $assignedMachineId = $s['machine_id'] ?? null;
                                        $canAssign = empty($assignedMachineId)
                                            || (string) $assignedMachineId === (string) $machine['id'];

                                        $isSelected = $currentSlotId !== null
                                            && (string) $currentSlotId === (string) $s['db_id'];
                                        ?>

                                        <?php if ($canAssign): ?>
                                            <option
                                                value="<?= esc((string) $s['db_id'], 'attr') ?>"
                                                <?= $isSelected ? 'selected' : '' ?>>
                                                Slot <?= esc($s['id'] ?? '') ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" class="btn btn-primary btn-sm">
                                    Assign
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($machines)): ?>
                    <p class="text-center text-secondary p-3 mb-0">
                        No machines available.
                    </p>
                <?php else: ?>
                    <p
                        class="text-center text-secondary p-3 mb-0"
                        data-filter-empty="machineTable"
                        hidden>
                        No matching machines.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>

<?php foreach ($slots as $index => $slot): ?>
    <?php if (!empty($slot['machine'])): ?>
        <?php
        $slotKey = 'slot' . bin2hex((string) $index);
        $slotId = rawurlencode((string) $slot['db_id']);
        ?>

        <div
            class="modal fade"
            id="<?= $slotKey ?>Dispose"
            tabindex="-1"
            aria-labelledby="<?= $slotKey ?>DisposeLabel"
            aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form
                        method="post"
                        action="<?= esc(site_url('master-data/slots/' . $slotId . '/dispose-machine'), 'attr') ?>">
                        <?= csrf_field() ?>

                        <div class="modal-header">
                            <h5
                                class="modal-title text-danger"
                                id="<?= $slotKey ?>DisposeLabel">
                                Dispose Machine
                            </h5>

                            <button
                                type="button"
                                class="btn-close"
                                data-bs-dismiss="modal"
                                aria-label="Close"></button>
                        </div>

                        <div class="modal-body">
                            Lepas <strong><?= esc($slot['machine']) ?></strong>
                            dari Slot <?= esc($slot['id'] ?? '') ?>?
                        </div>

                        <div class="modal-footer">
                            <button
                                type="button"
                                class="btn btn-light"
                                data-bs-dismiss="modal">
                                Cancel
                            </button>
                            <button type="submit" class="btn btn-danger">
                                Dispose
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php foreach ($machines as $index => $machine): ?>
    <?php
    $machineKey = 'machine' . bin2hex((string) $index);
    $machineId = rawurlencode((string) $machine['id']);
    $yearValue = $machine['year'] ?? '';
    $yearValue = $yearValue === '-' ? '' : $yearValue;
    ?>

    <div
        class="modal fade"
        id="<?= $machineKey ?>Detail"
        tabindex="-1"
        aria-labelledby="<?= $machineKey ?>DetailLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="<?= $machineKey ?>DetailLabel">
                        Machine Detail · <?= esc($machine['code'] ?? '') ?>
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="detail-list">
                        <div class="detail-item">
                            <small>Code</small>
                            <strong class="mono">
                                <?= esc($machine['code'] ?? '') ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <small>Name</small>
                            <strong><?= esc($machine['name'] ?? '') ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Registration Code</small><strong><?= esc($machine['registration_code'] ?? $machine['code']) ?></strong>
                        </div>
                        <div><small>Serial</small>
                            <strong class="mono">
                                <?= esc($machine['serial'] ?? '-') ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <small>Year</small>
                            <strong><?= esc($machine['year'] ?? '-') ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Current Slot</small>
                            <strong>
                                <?= esc($machine['slot'] ?? 'Available') ?>
                            </strong>
                        </div>

                        <div class="detail-item">
                            <small>Assigned Tools</small>
                            <?php if (!empty($machine['tools'])): ?>
                                <strong><?= count($machine['tools']) ?> tools</strong>
                                <div class="small text-secondary mt-1">
                                    <?= esc(implode(', ', array_map(static fn($tool) => ($tool['code'] ?? '-') . ' (' . number_format((int)($tool['actual_lifetime'] ?? 0)) . ')', $machine['tools']))) ?>
                                </div>
                            <?php else: ?>
                                <strong>-</strong>
                            <?php endif; ?>
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
        id="<?= $machineKey ?>Edit"
        tabindex="-1"
        aria-labelledby="<?= $machineKey ?>EditLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form
                    method="post"
                    action="<?= esc(site_url('master-data/machines/' . $machineId . '/update'), 'attr') ?>">
                    <?= csrf_field() ?><div class="px-4 pt-3"><label class="form-label">Registration Code</label><input class="form-control" name="registration_code" maxlength="50" value="<?= esc($machine['registration_code'] ?? $machine['code'], 'attr') ?>" required></div>

                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= $machineKey ?>EditLabel">
                            Edit Machine
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="<?= $machineKey ?>Code" class="form-label">
                                    Code
                                </label>
                                <input
                                    type="text"
                                    id="<?= $machineKey ?>Code"
                                    class="form-control"
                                    name="code"
                                    value="<?= esc($machine['code'] ?? '', 'attr') ?>"
                                    required>
                            </div>

                            <div class="col-md-6">
                                <label for="<?= $machineKey ?>Name" class="form-label">
                                    Name
                                </label>
                                <input
                                    type="text"
                                    id="<?= $machineKey ?>Name"
                                    class="form-control"
                                    name="name"
                                    value="<?= esc($machine['name'] ?? '', 'attr') ?>"
                                    required>
                            </div>

                            <div class="col-md-6">
                                <label for="<?= $machineKey ?>Serial" class="form-label">
                                    Serial
                                </label>
                                <input
                                    type="text"
                                    id="<?= $machineKey ?>Serial"
                                    class="form-control"
                                    name="serial_number"
                                    value="<?= esc($machine['serial_raw'] ?? '', 'attr') ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="<?= $machineKey ?>Year" class="form-label">
                                    Year
                                </label>
                                <input
                                    type="number"
                                    id="<?= $machineKey ?>Year"
                                    class="form-control"
                                    name="year"
                                    value="<?= esc((string) $yearValue, 'attr') ?>"
                                    min="1"
                                    step="1">
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
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (session()->get('role') === 'admin'): ?>
        <div class="modal fade" id="<?= $machineKey ?>Tools" tabindex="-1" aria-labelledby="<?= $machineKey ?>ToolsLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form class="modal-content" method="post" action="<?= esc(site_url('master-data/machines/' . $machineId . '/assign-tools'), 'attr') ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="<?= $machineKey ?>ToolsLabel">Assign Tools · <?= esc($machine['code'] ?? '-') ?></h5>
                            <div class="small text-secondary mt-1">Machine memilih physical tools. Posisi tool tidak diset di sini; posisi diset pada Part per Process.</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-light border small">
                            Tool yang sudah dimiliki Machine lain tidak dapat dipilih. Lepas dari Machine lama terlebih dahulu. Nilai dalam kurung adalah <strong>Actual Lifetime</strong>.
                        </div>

                        <div class="input-group mb-3">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" placeholder="Search tool / tool type..." data-machine-tool-search="<?= $machineKey ?>ToolList">
                        </div>

                        <div class="list-group" id="<?= $machineKey ?>ToolList">
                            <?php foreach ($toolPool as $tool): ?>
                                <?php
                                $assignedMachineId = !empty($tool['machine_id']) ? (int)$tool['machine_id'] : null;
                                $isCurrentMachine = $assignedMachineId === (int)$machine['id'];
                                $ownedByOther = $assignedMachineId !== null && !$isCurrentMachine;
                                $toolSearch = strtolower(trim((string)($tool['code'] ?? '') . ' ' . (string)($tool['name'] ?? '') . ' ' . (string)($tool['tool_type_code'] ?? '')));
                                ?>
                                <label class="list-group-item d-flex align-items-start gap-3 <?= $ownedByOther ? 'opacity-50' : '' ?>" data-machine-tool-item data-search="<?= esc($toolSearch, 'attr') ?>">
                                    <input class="form-check-input mt-1" type="checkbox" name="tool_ids[]" value="<?= (int)$tool['id'] ?>" <?= $isCurrentMachine ? 'checked' : '' ?> <?= $ownedByOther ? 'disabled' : '' ?>>
                                    <div class="flex-grow-1">
                                        <div class="d-flex flex-wrap justify-content-between gap-2">
                                            <strong><?= esc(($tool['code'] ?? '-') . ' (' . number_format((int)($tool['actual_lifetime'] ?? 0)) . ')') ?></strong>
                                            <span class="badge text-bg-light border"><?= esc($tool['tool_type_code'] ?? '-') ?></span>
                                        </div>
                                        <div class="small text-secondary"><?= esc($tool['name'] ?? '-') ?></div>
                                        <?php if ($ownedByOther): ?>
                                            <div class="small text-danger mt-1"><i class="bi bi-lock me-1"></i>Assigned to <?= esc($tool['assigned_machine_code'] ?? 'Machine lain') ?></div>
                                        <?php elseif ($isCurrentMachine): ?>
                                            <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i>Currently assigned to this Machine</div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                            <?php if (empty($toolPool)): ?>
                                <div class="text-center text-secondary py-4">Belum ada Physical Tool.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Tool Assignment</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<div
    class="modal fade"
    id="addSlotModal"
    tabindex="-1"
    aria-labelledby="addSlotModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form
                method="post"
                action="<?= esc(site_url('master-data/slots'), 'attr') ?>">
                <?= csrf_field() ?>

                <div class="modal-header">
                    <h5 class="modal-title" id="addSlotModalLabel">
                        Add Production Slot
                    </h5>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <label for="addSlotNumber" class="form-label">
                        Slot Number
                    </label>
                    <input
                        type="number"
                        id="addSlotNumber"
                        class="form-control mb-3"
                        name="slot_no"
                        min="1"
                        step="1"
                        required>

                    <label for="addSlotName" class="form-label">Name</label>
                    <input
                        type="text"
                        id="addSlotName"
                        class="form-control mb-3"
                        name="name">

                    <label for="addSlotArea" class="form-label">Area</label>
                    <input
                        type="text"
                        id="addSlotArea"
                        class="form-control"
                        name="area">
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        Create Slot
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="addMachineModal"
    tabindex="-1"
    aria-labelledby="addMachineModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form
                method="post"
                action="<?= esc(site_url('master-data/machines'), 'attr') ?>">
                <?= csrf_field() ?><div class="px-4 pt-3"><label class="form-label">Registration Code</label><input class="form-control" name="registration_code" maxlength="50" value="" required></div>

                <div class="modal-header">
                    <h5 class="modal-title" id="addMachineModalLabel">
                        Add Physical Machine
                    </h5>
                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="addMachineCode" class="form-label">
                                Code
                            </label>
                            <input
                                type="text"
                                id="addMachineCode"
                                class="form-control"
                                name="code"
                                required>
                        </div>

                        <div class="col-md-6">
                            <label for="addMachineName" class="form-label">
                                Name
                            </label>
                            <input
                                type="text"
                                id="addMachineName"
                                class="form-control"
                                name="name"
                                required>
                        </div>

                        <div class="col-md-6">
                            <label for="addMachineSerial" class="form-label">
                                Serial
                            </label>
                            <input
                                type="text"
                                id="addMachineSerial"
                                class="form-control"
                                name="serial_number">
                        </div>

                        <div class="col-md-6">
                            <label for="addMachineYear" class="form-label">
                                Year
                            </label>
                            <input
                                type="number"
                                id="addMachineYear"
                                class="form-control"
                                name="year"
                                min="1"
                                step="1">
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
                        Save Machine
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    (() => {
        const initializeCardFilters = () => {
            document.querySelectorAll('[data-machine-tool-search]').forEach((input) => {
                const list = document.getElementById(input.dataset.machineToolSearch);
                if (!list) return;
                input.addEventListener('input', () => {
                    const keyword = input.value.trim().toLowerCase();
                    list.querySelectorAll('[data-machine-tool-item]').forEach((item) => {
                        item.hidden = keyword !== '' && !(item.dataset.search || '').includes(keyword);
                    });
                });
            });

            document.querySelectorAll('[data-machine-card-filter]').forEach((input) => {
                const targetId = input.dataset.machineCardFilter;
                const container = document.getElementById(targetId);

                if (!container) {
                    return;
                }

                const cards = Array.from(container.querySelectorAll('[data-filter-card]'));
                const emptyMessage = document.querySelector(
                    `[data-filter-empty="${targetId}"]`
                );

                const filterCards = () => {
                    const query = input.value.trim().toLocaleLowerCase();
                    let visibleCount = 0;

                    cards.forEach((card) => {
                        const text = card.textContent.toLocaleLowerCase();
                        const matches = text.includes(query);

                        card.classList.toggle('d-none', !matches);

                        if (matches) {
                            visibleCount++;
                        }
                    });

                    if (emptyMessage) {
                        emptyMessage.hidden = visibleCount > 0;
                    }
                };

                input.addEventListener('input', filterCards);
                filterCards();
            });
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeCardFilters, {
                once: true
            });
        } else {
            initializeCardFilters();
        }
    })();
</script>

<?= $this->endSection() ?>