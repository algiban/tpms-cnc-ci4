<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$parts = $parts ?? [];
$customers = $customers ?? [];
$materials = $materials ?? [];
$toolTypes = $toolTypes ?? [];
$totalParts = (int) ($partStats['total'] ?? count($parts));
$totalProcesses = 0;
$autoProcesses = 0;
$manualProcesses = 0;
$nextGrindingProcesses = 0;
$configuredPlans = 0;
foreach ($parts as $part) {
    foreach (($part['processes'] ?? []) as $process) {
        $totalProcesses++;
        if (!empty($process['is_next_grinding'])) {
            $nextGrindingProcesses++;
        } elseif (strtolower((string)($process['process_mode'] ?? '')) === 'auto') {
            $autoProcesses++;
        } elseif (strtolower((string)($process['process_mode'] ?? '')) === 'manual') {
            $manualProcesses++;
        }
        if (!empty($process['plan'])) {
            $configuredPlans++;
        }
    }
}
// Cards tetap menampilkan statistik semua data, bukan hanya halaman aktif.
$totalProcesses = (int) ($processStats['total'] ?? $totalProcesses);
$autoProcesses = (int) ($processStats['auto'] ?? $autoProcesses);
$manualProcesses = (int) ($processStats['manual'] ?? $manualProcesses);
$nextGrindingProcesses = (int) ($processStats['grinding'] ?? $nextGrindingProcesses);
$configuredPlans = (int) ($processStats['configured'] ?? $configuredPlans);
$processTypeLabels = [
    'single_auto' => 'Single Proses Auto',
    'process_1_auto' => '1 Proses Auto',
    'process_2_auto' => '2 Proses Auto',
    'process_3_auto' => '3 Proses Auto',
    'single_manual' => 'Single Proses Manual',
    'process_1_manual' => '1 Proses Manual',
    'process_2_manual' => '2 Proses Manual',
    'process_3_manual' => '3 Proses Manual',
    'next_grinding' => 'Next Grinding',
];
?>
<style>
    .part-number {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #253149;
        font-size: 14px;
        font-weight: 850
    }

    .part-number-icon {
        width: 34px;
        height: 34px;
        display: grid;
        place-items: center;
        flex: 0 0 34px;
        border-radius: 10px;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 14px
    }

    .part-name {
        margin-top: 3px;
        color: #7c8da6;
        font-size: 12px;
        font-weight: 600
    }

    .part-customer {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #344054;
        font-weight: 700
    }

    .part-customer-icon {
        width: 29px;
        height: 29px;
        display: grid;
        place-items: center;
        border-radius: 8px;
        background: #f2f4f7;
        color: #667085;
        font-size: 12px
    }

    .process-list {
        display: grid;
        gap: 7px;
        min-width: 280px
    }

    .process-item {
        padding: 9px 10px;
        border: 1px solid #edf0f5;
        border-radius: 10px;
        background: #fafbfc
    }

    .process-item-top {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap
    }

    .process-name {
        color: #344054;
        font-size: 12px;
        font-weight: 800
    }

    .process-meta {
        margin-top: 5px;
        display: flex;
        align-items: center;
        gap: 5px;
        flex-wrap: wrap;
        color: #7c8da6;
        font-size: 11px;
        font-weight: 600
    }

    .process-tool-list {
        margin-top: 5px;
        color: #667085;
        font-size: 11px;
        line-height: 1.5
    }

    .process-tool-list i {
        margin-right: 4px;
        color: #7c3aed
    }

    .process-time {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        color: #475467
    }

    .process-mode {
        display: inline-flex;
        align-items: center;
        padding: 3px 7px;
        border-radius: 999px;
        font-size: 9px;
        font-weight: 850;
        letter-spacing: .03em;
        text-transform: uppercase
    }

    .process-mode.auto {
        background: #ecfdf3;
        color: #027a48
    }

    .process-mode.manual {
        background: #fff7ed;
        color: #c2410c
    }

    .process-mode.grinding {
        background: #fdf2fa;
        color: #c11574
    }

    .plan-list {
        display: grid;
        gap: 7px;
        min-width: 160px
    }

    .plan-item {
        padding: 8px 9px;
        border-radius: 9px;
        background: #f8fafc;
        border: 1px solid #eef2f6
    }

    .plan-name {
        color: #475467;
        font-size: 10px;
        font-weight: 800
    }

    .plan-value {
        margin-top: 3px;
        color: #253149;
        font-size: 12px;
        font-weight: 800
    }

    .plan-value span {
        color: #98a2b3;
        font-weight: 600
    }

    .plan-unconfigured {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-top: 3px;
        color: #d97706;
        font-size: 10px;
        font-weight: 700
    }

    .parts-filter-result {
        color: #7c8da6;
        font-size: 12px;
        font-weight: 700
    }

    .parts-empty-state {
        padding: 52px 24px !important;
        text-align: center;
        color: #98a2b3 !important
    }

    .parts-empty-state i {
        display: block;
        margin-bottom: 10px;
        font-size: 34px
    }

    .part-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        white-space: nowrap
    }

    .part-action-btn {
        width: 34px;
        height: 34px;
        display: inline-grid;
        place-items: center;
        padding: 0;
        border: 1px solid #e4e7ec;
        border-radius: 9px;
        background: #fff;
        color: #667085;
        transition: .18s ease
    }

    .part-action-btn:hover {
        border-color: #c7d2fe;
        background: #eef2ff;
        color: #4f46e5
    }

    .part-action-btn.delete:hover {
        border-color: #fecaca;
        background: #fef2f2;
        color: #dc2626
    }

    .part-modal-section {
        padding: 15px;
        border: 1px solid #e8ecf2;
        border-radius: 13px;
        background: #fbfcfd
    }

    .part-modal-section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        color: #344054;
        font-size: 13px;
        font-weight: 850
    }

    .part-modal-section-title i {
        color: #4f46e5
    }

    .part-process-info {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        margin-top: 13px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f5f7ff;
        color: #667085;
        font-size: 11px;
        line-height: 1.55
    }

    .part-process-info i {
        margin-top: 1px;
        color: #4f46e5
    }

    .part-table-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px
    }

    .part-table-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #253149;
        font-size: 14px;
        font-weight: 850
    }

    .part-table-count {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 9px;
        border-radius: 999px;
        background: #f2f4f7;
        color: #667085;
        font-size: 10px;
        font-weight: 800
    }

    @media(max-width:767.98px) {
        .part-actions {
            justify-content: flex-start
        }

        .part-table-toolbar {
            align-items: flex-start;
            flex-direction: column
        }

        .process-list {
            min-width: 230px
        }

        .plan-list {
            min-width: 145px
        }
    }
    .process-production-inline { margin-left: auto; display: inline-flex; gap: 4px; align-items: center; font-size: 11px; color: #667085; white-space: nowrap; }
    .process-production-inline strong { color: #344054; font-weight: 700; }
    .process-production-divider { color: #cbd5e1; padding: 0 2px; }
</style>
<div class="page-heading">
    <div>
        <h1>Parts <span>& Processes</span></h1>
        <div class="page-subtitle"><?= $totalParts ?> Parts · <?= $totalProcesses ?> Processes · Production Configuration</div>
    </div>
    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button type="button" class="btn btn-primary" id="addPart"><i class="bi bi-plus-lg me-2"></i>Add Part</button>
        </div>
    <?php endif ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'parts', 'label' => 'Parts', 'template' => 'parts_import.xlsx']]]) ?>
<div class="stat-grid mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total Parts</div>
        <div class="stat-value"><?= $totalParts ?></div>
        <div class="stat-meta text-primary"><?= $totalProcesses ?> configured processes</div>
        <div class="stat-icon text-primary"><i class="bi bi-box-seam"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Auto Processes</div>
        <div class="stat-value text-success"><?= $autoProcesses ?></div>
        <div class="stat-meta text-success">Automatic machining</div>
        <div class="stat-icon text-success"><i class="bi bi-gear-wide-connected"></i></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Manual Processes</div>
        <div class="stat-value text-warning"><?= $manualProcesses ?></div>
        <div class="stat-meta text-warning">Manual operation</div>
        <div class="stat-icon text-warning"><i class="bi bi-person-gear"></i></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Next Grinding</div>
        <div class="stat-value text-primary"><?= $nextGrindingProcesses ?></div>
        <div class="stat-meta text-primary"><?= $configuredPlans ?> process plans ready</div>
        <div class="stat-icon text-primary"><i class="bi bi-arrow-repeat"></i></div>
    </div>
</div>
<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-6">
            <label for="partSearch" class="filter-label">Search Part</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                <input type="search" id="partSearch" class="form-control border-start-0" placeholder="Search part number, name, customer..." autocomplete="off" name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
            </div>
        </div>
        <div class="col-12 col-md-5 col-lg-3">
            <label for="partProcessFilter" class="filter-label">Process Type</label>
            <select id="partProcessFilter" class="form-select" name="process" onchange="this.form.submit()">
                <option value="">All Processes</option>
                <option value="auto" <?= (string) service('request')->getGet('process') === 'auto' ? 'selected' : '' ?>>Auto</option>
                <option value="manual" <?= (string) service('request')->getGet('process') === 'manual' ? 'selected' : '' ?>>Manual</option>
                <option value="grinding" <?= (string) service('request')->getGet('process') === 'grinding' ? 'selected' : '' ?>>Next Grinding</option>
            </select>
        </div>
        <div class="col-12 col-md-4 col-lg-2">
            <label for="partPlanFilter" class="filter-label">Plan</label>
            <select id="partPlanFilter" class="form-select" name="plan" onchange="this.form.submit()">
                <option value="">All Plan Status</option>
                <option value="configured" <?= (string) service('request')->getGet('plan') === 'configured' ? 'selected' : '' ?>>Configured</option>
                <option value="unconfigured" <?= (string) service('request')->getGet('plan') === 'unconfigured' ? 'selected' : '' ?>>Not Configured</option>
            </select>
        </div>
        <div class="col-12 col-md-3 col-lg-1 d-flex">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>" title="Reset filter">Reset</a>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-2">
        <div class="parts-filter-result" id="partFilterResult">Showing <?= count($parts) ?> of <?= (int) ($pagination['total'] ?? $totalParts) ?> parts</div>
    </div>
</form>
<div class="panel table-card">
    <div class="p-3 pb-0">
        <div class="part-table-toolbar">
            <div class="part-table-title"><i class="bi bi-diagram-3 text-primary"></i>Parts & Process Configuration</div>
            <div class="part-table-count"><i class="bi bi-database"></i><?= $totalParts ?> records</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle" id="partsTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'part', 'label' => 'Part', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'customer', 'label' => 'Customer', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'process', 'label' => 'Processes / Required Tool Types', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'plan', 'label' => 'Plan / Shift · Daily', 'pagination' => $pagination]) ?>
                    <th class="text-end">Actions</th>
</tr>
            </thead>
            <tbody>
                <?php foreach ($parts as $p): ?>
                    <?php
                    $partModes = [];
                    $hasConfiguredPlan = false;
                    foreach (($p['processes'] ?? []) as $process) {
                        if (!empty($process['is_next_grinding'])) {
                            $partModes[] = 'grinding';
                        } else {
                            $mode = strtolower((string)($process['process_mode'] ?? ''));
                            if (in_array($mode, ['auto', 'manual'], true)) {
                                $partModes[] = $mode;
                            }
                        }
                        if (!empty($process['plan'])) {
                            $hasConfiguredPlan = true;
                        }
                    }
                    $searchText = strtolower(trim(
                        (string)($p['part_number'] ?? '') . ' ' .
                            (string)($p['name'] ?? '') . ' ' .
                            (string)($p['customer_name'] ?? '') . ' ' .
                            implode(' ', array_map(static fn($process) => (string)($process['process_name'] ?? ''), $p['processes'] ?? []))
                    ));
                    ?>
                    <tr data-part-row data-search="<?= esc($searchText, 'attr') ?>" data-modes="<?= esc(implode(',', array_unique($partModes)), 'attr') ?>" data-plan="<?= $hasConfiguredPlan ? 'configured' : 'unconfigured' ?>">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="part-number-icon"><i class="bi bi-box-seam"></i></div>
                                <div>
                                    <div class="part-number"><?= esc($p['part_number'] ?? '-') ?></div>
                                    <div class="part-name"><?= esc($p['name'] ?? '-') ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="part-customer">
                                <span class="part-customer-icon"><i class="bi bi-building"></i></span>
                                <span><?= esc($p['customer_name'] ?? '-') ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="process-list">
                                <?php foreach (($p['processes'] ?? []) as $process): ?>
                                    <?php
                                    $isGrinding = !empty($process['is_next_grinding']);
                                    $processMode = strtolower((string)($process['process_mode'] ?? ''));
                                    $modeClass = $isGrinding ? 'grinding' : ($processMode === 'manual' ? 'manual' : 'auto');
                                    $modeLabel = $isGrinding ? 'Next Grinding' : strtoupper($processMode ?: '-');
                                    $requirementCodes = array_values(array_filter(array_map(static function ($row) {
                                        $code = trim((string)($row['code'] ?? ''));
                                        if ($code === '') return '';
                                        $position = trim((string)($row['position'] ?? ''));
                                        return $position !== '' ? $code . ' @ ' . $position : $code . ' @ POSITION?';
                                    }, $process['requirements'] ?? [])));
                                    ?>
                                    <div class="process-item">
                                        <div class="process-item-top">
                                            <span class="process-name"><?= esc($process['process_name'] ?? '-') ?></span>
                                            <span class="process-mode <?= esc($modeClass, 'attr') ?>"><?= esc($modeLabel) ?></span>
                                            <?php $qty = $process['production_summary'] ?? []; ?>
                                            <span class="process-production-inline">
                                                Good: <strong><?= number_format((int) ($qty['good'] ?? 0), 0, ',', '.') ?></strong>
                                                <span class="process-production-divider">|</span>
                                                NC: <strong><?= number_format((int) ($qty['nc'] ?? 0), 0, ',', '.') ?></strong>
                                            </span>
                                        </div>
                                        <div class="process-meta">
                                            <span class="process-time"><i class="bi bi-stopwatch me-1"></i>M <?= number_format(((int)($process['machine_time_target_ms'] ?? 0)) / 1000, 2) ?>s</span>
                                            <span>+</span>
                                            <span class="process-time">L <?= number_format(((int)($process['loading_time_target_ms'] ?? 0)) / 1000, 2) ?>s</span>
                                            <span>·</span>
                                            <span>Total <?= number_format((((int)($process['machine_time_target_ms'] ?? 0)) + ((int)($process['loading_time_target_ms'] ?? 0))) / 1000, 2) ?>s</span>
                                        </div>
                                        <div class="process-tool-list">
                                            <i class="bi bi-tools"></i>
                                            <?php if (!empty($requirementCodes)): ?>
                                                <?= esc(implode(', ', $requirementCodes)) ?>
                                            <?php else: ?>
                                                <span class="text-secondary fst-italic">No required tool type</span>
                                            <?php endif ?>
                                        </div>
                                    </div>
                                <?php endforeach ?>
                                <?php if (empty($p['processes'])): ?>
                                    <div class="small text-secondary fst-italic"><i class="bi bi-exclamation-circle me-1"></i>No process configured</div>
                                <?php endif ?>
                            </div>
                        </td>
                        <td>
                            <div class="plan-list">
                                <?php foreach (($p['processes'] ?? []) as $process): ?>
                                    <div class="plan-item">
                                        <div class="plan-name"><?= esc($process['process_name'] ?? '-') ?></div>
                                        <?php if (!empty($process['plan'])): ?>
                                            <div class="plan-value"><?= number_format((int)($process['plan']['plan_per_shift'] ?? 0)) ?> <span>/ shift</span> · <?= number_format((int)($process['plan']['daily_plan'] ?? 0)) ?> <span>/ day</span></div>
                                        <?php else: ?>
                                            <div class="plan-unconfigured"><i class="bi bi-exclamation-triangle"></i>Configure Cycle Time</div>
                                        <?php endif ?>
                                    </div>
                                <?php endforeach ?>
                                <?php if (empty($p['processes'])): ?>
                                    <span class="text-secondary small">-</span>
                                <?php endif ?>
                            </div>
                        </td>
                        <td>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <div class="part-actions">
                                    <button type="button" class="part-action-btn" data-edit-part="<?= (int)($p['id'] ?? 0) ?>" title="Edit Part"><i class="bi bi-pencil"></i></button>
                                    <form method="post" action="<?= site_url('master-data/parts/' . (int)($p['id'] ?? 0) . '/delete') ?>" class="d-inline" data-delete-part-form>
                                        <?= csrf_field() ?>
                                        <button type="submit" class="part-action-btn delete" title="Delete Part"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if (empty($parts)): ?>
                    <tr id="partEmptyInitial">
                        <td colspan="5" class="parts-empty-state"><i class="bi bi-box-seam"></i>Belum ada Part yang terdaftar.</td>
                    </tr>
                <?php endif ?>
                <tr id="partEmptyFilter" class="d-none">
                    <td colspan="5" class="parts-empty-state"><i class="bi bi-search"></i>Tidak ada Part yang sesuai dengan filter.</td>
                </tr>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>
<?php if (session()->get('role') === 'admin'): ?>
    <div class="modal fade" id="partModal" tabindex="-1" aria-labelledby="partModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <form class="modal-content" id="partForm" method="post" action="<?= site_url('master-data/parts') ?>">
                <?= csrf_field() ?>
                <div class="modal-header">
                    <div>
                        <h2 class="modal-title fs-6" id="partModalLabel"><i class="bi bi-diagram-3 me-2 text-primary"></i>Part & Process Requirements</h2>
                        <div class="small text-secondary mt-1">Configure part information, process sequence, cycle time, required Tool Type, dan posisi Tool pada Machine.</div>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="part-modal-section mb-3">
                        <div class="part-modal-section-title"><i class="bi bi-info-circle"></i>Part Information</div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Part Number <span class="text-danger">*</span></label>
                                <input class="form-control" name="part_number" maxlength="100" placeholder="Example: CYLINDER-B-T6" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Part Name <span class="text-danger">*</span></label>
                                <input class="form-control" name="name" maxlength="180" placeholder="Enter part name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Customer <span class="text-danger">*</span></label>
                                <select class="form-select" name="customer_id" required>
                                    <option value="">Select Customer</option>
                                    <?php foreach ($customers as $c): ?>
                                        <option value="<?= (int)($c['id'] ?? 0) ?>"><?= esc($c['name'] ?? '-') ?></option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Material</label>
                                <select class="form-select" name="material_id">
                                    <option value="">None</option>
                                    <?php foreach ($materials as $m): ?>
                                        <option value="<?= (int)($m['id'] ?? 0) ?>"><?= esc($m['name'] ?? '-') ?></option>
                                    <?php endforeach ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="part-modal-section">
                        <div class="part-modal-section-title"><i class="bi bi-gear-wide-connected"></i>Process Configuration</div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Process Type</label>
                                <select class="form-select" name="process_type" id="processType">
                                    <?php foreach ($processTypeLabels as $value => $label): ?>
                                        <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                                    <?php endforeach ?>
                                </select>
                                <div class="form-text">Menentukan jumlah proses dan mode Auto / Manual.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Shift per Day</label>
                                <select class="form-select" name="shifts_per_day" id="shiftsPerDay">
                                    <option value="1">1 Shift</option>
                                    <option value="2">2 Shifts</option>
                                    <option value="3" selected>3 Shifts</option>
                                </select>
                                <div class="form-text">Digunakan untuk menghitung daily production plan.</div>
                            </div>
                            <div class="col-12" id="grindingWrap">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" name="next_grinding" id="nextGrinding" type="checkbox" value="1">
                                    <label class="form-check-label fw-semibold" for="nextGrinding">Last process = Next Grinding</label>
                                </div>
                                <div class="form-text">Aktifkan jika proses terakhir dilanjutkan ke Next Grinding.</div>
                            </div>
                        </div>
                        <div id="processEditor"></div>
                        <div class="part-process-info">
                            <i class="bi bi-info-circle-fill"></i>
                            <div><strong>Production Plan Calculation</strong><br>Plan per shift = floor(25,200 seconds / total cycle seconds). Total cycle time terdiri dari Machine Time + Loading Time. Physical tools akan di-resolve dari daftar tools milik Machine berdasarkan Tool Type; Position berasal dari konfigurasi Part per Process.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Part</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        window.TPMS_PART_EDITOR = <?= json_encode(['parts' => $parts, 'toolTypes' => $toolTypes, 'base' => site_url('master-data/parts')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="<?= base_url('assets/js/part-process-editor.js') ?>"></script>
<?php endif ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-delete-part-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!confirm('Delete this part? Process configuration dan requirement yang terkait juga dapat terpengaruh.')) event.preventDefault();
            });
        });
    });
</script>
<?= $this->endSection() ?>