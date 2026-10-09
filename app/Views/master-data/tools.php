<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$tools = $tools ?? [];
$toolTypes = $toolTypes ?? [];
$issues = $issues ?? [];
$totalTools = (int) ($toolStats['total'] ?? count($tools));
$assignedTools = (int) ($toolStats['assigned'] ?? 0);
$unassignedTools = $totalTools - $assignedTools;
$readyTools = (int) ($toolStats['ready'] ?? 0);
$warningTools = (int) ($toolStats['warning'] ?? 0);
$problemTools = (int) ($toolStats['problem'] ?? 0);
$statusLabels = ['ready' => 'Ready', 'warning' => 'Warning', 'broken' => 'Broken', 'maintenance' => 'Maintenance', 'inactive' => 'Inactive'];
?>
<style>
    .tool-identity {
        display: flex;
        align-items: center;
        gap: 11px;
        min-width: 190px
    }

    .tool-identity-icon {
        width: 38px;
        height: 38px;
        display: grid;
        place-items: center;
        flex: 0 0 38px;
        border-radius: 11px;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 15px
    }

    .tool-code {
        color: #253149;
        font-size: 13px;
        font-weight: 850
    }

    .tool-name {
        margin-top: 2px;
        color: #7c8da6;
        font-size: 11px;
        font-weight: 600
    }

    .tool-holder {
        margin-top: 3px;
        color: #98a2b3;
        font-size: 10px
    }

    .tool-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 8px;
        background: #f5f3ff;
        color: #6d28d9;
        font-size: 10px;
        font-weight: 850
    }

    .tool-edge-wrap {
        min-width: 100px
    }

    .tool-edge-value {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #344054;
        font-size: 13px;
        font-weight: 850
    }

    .tool-edge-value strong {
        color: #4f46e5
    }

    .tool-edge-caption {
        margin-top: 3px;
        color: #98a2b3;
        font-size: 10px;
        font-weight: 600
    }

    .tool-lifetime {
        min-width: 150px
    }

    .tool-lifetime-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 6px;
        color: #475467;
        font-size: 11px;
        font-weight: 700
    }

    .tool-lifetime-top strong {
        color: #253149
    }

    .tool-lifetime-bar {
        height: 5px;
        overflow: hidden;
        border-radius: 999px;
        background: #eef2f6
    }

    .tool-lifetime-fill {
        height: 100%;
        border-radius: 999px;
        background: #4f46e5;
        transition: width .2s ease
    }

    .tool-lifetime-fill.warning {
        background: #f59e0b
    }

    .tool-lifetime-fill.danger {
        background: #ef4444
    }

    .tool-machine {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        min-width: 150px
    }

    .tool-machine-icon {
        width: 30px;
        height: 30px;
        display: grid;
        place-items: center;
        flex: 0 0 30px;
        border-radius: 8px;
        background: #f2f4f7;
        color: #667085;
        font-size: 12px
    }

    .tool-machine-code {
        color: #344054;
        font-size: 12px;
        font-weight: 800
    }

    .tool-machine-position {
        margin-top: 2px;
        color: #98a2b3;
        font-size: 10px;
        font-weight: 600
    }

    .tool-unassigned {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        color: #98a2b3;
        font-size: 11px;
        font-weight: 650
    }

    .tool-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 850;
        text-transform: capitalize
    }

    .tool-status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor
    }

    .tool-status.ready {
        background: #ecfdf3;
        color: #027a48
    }

    .tool-status.warning {
        background: #fff7ed;
        color: #d97706
    }

    .tool-status.broken {
        background: #fef2f2;
        color: #dc2626
    }

    .tool-status.maintenance {
        background: #eef2ff;
        color: #4f46e5
    }

    .tool-status.inactive {
        background: #f2f4f7;
        color: #667085
    }

    .tool-actions {
        display: flex;
        align-items: center;
        gap: 5px;
        justify-content: flex-end;
        white-space: nowrap
    }

    .tool-action-btn {
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

    .tool-action-btn:hover {
        border-color: #c7d2fe;
        background: #eef2ff;
        color: #4f46e5
    }

    .tool-action-btn.assign:hover {
        border-color: #bfdbfe;
        background: #eff6ff;
        color: #2563eb
    }

    .tool-action-btn.maintenance:hover {
        border-color: #fde68a;
        background: #fffbeb;
        color: #d97706
    }

    .tools-filter-result {
        color: #7c8da6;
        font-size: 12px;
        font-weight: 700
    }

    .tools-empty-state {
        padding: 52px 24px !important;
        text-align: center;
        color: #98a2b3 !important
    }

    .tools-empty-state i {
        display: block;
        margin-bottom: 10px;
        font-size: 34px
    }

    .tools-table-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px
    }

    .tools-table-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #253149;
        font-size: 14px;
        font-weight: 850
    }

    .tools-table-count {
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

    .tool-modal-section {
        padding: 15px;
        border: 1px solid #e8ecf2;
        border-radius: 13px;
        background: #fbfcfd
    }

    .tool-modal-section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 14px;
        color: #344054;
        font-size: 13px;
        font-weight: 850
    }

    .tool-modal-section-title i {
        color: #4f46e5
    }

    .tool-info-box {
        display: flex;
        align-items: flex-start;
        gap: 9px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f5f7ff;
        color: #667085;
        font-size: 11px;
        line-height: 1.55
    }

    .tool-info-box i {
        margin-top: 1px;
        color: #4f46e5
    }

    .tool-maint-card {
        padding: 14px;
        border: 1px solid #e8ecf2;
        border-radius: 12px;
        background: #fff
    }

    .tool-maint-card+.tool-maint-card {
        margin-top: 12px
    }

    .tool-maint-title {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 5px;
        color: #344054;
        font-size: 12px;
        font-weight: 850
    }

    .tool-maint-description {
        margin-bottom: 12px;
        color: #98a2b3;
        font-size: 10px;
        line-height: 1.5
    }

    .migration-review {
        border: 1px solid #fde68a !important;
        border-radius: 12px !important;
        background: #fffbeb !important;
        color: #92400e !important
    }

    .migration-review summary {
        cursor: pointer;
        font-weight: 800
    }

    .migration-review-item {
        display: flex;
        align-items: flex-start;
        gap: 6px;
        margin-top: 6px
    }

    @media(max-width:991.98px) {
        .tool-actions {
            justify-content: flex-start
        }

        .tool-lifetime {
            min-width: 130px
        }
    }

    @media(max-width:767.98px) {
        .tools-table-toolbar {
            align-items: flex-start;
            flex-direction: column
        }

        .tool-actions {
            min-width: 120px
        }
    }
</style>
<div class="page-heading">
    <div>
        <h1>Physical <span>Tools</span></h1>
        <div class="page-subtitle"><?= $totalTools ?> Tools · <?= $assignedTools ?> Assigned · <?= $readyTools ?> Ready</div>
    </div>
    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#tool0"><i class="bi bi-plus-lg me-2"></i>Add Tool</button>
        </div>
    <?php endif ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'tools', 'label' => 'Tools', 'template' => 'tools_import.xlsx']]]) ?>
<?php if ($issues && session()->get('role') === 'admin'): ?>
    <details class="alert migration-review small">
        <summary><i class="bi bi-exclamation-triangle me-1"></i>Migration Review Required (<?= count($issues) ?>)</summary>
        <?php foreach ($issues as $issue): ?>
            <div class="migration-review-item"><i class="bi bi-dot"></i><span><?= esc($issue['message'] ?? '-') ?></span></div>
        <?php endforeach ?>
    </details>
<?php endif ?>
<div class="stat-grid">
    <div class="stat-card blue">
        <div class="stat-label">Total Physical Tools</div>
        <div class="stat-value"><?= $totalTools ?></div>
        <div class="stat-meta text-primary">Registered physical tools</div>
        <div class="stat-icon text-primary"><i class="bi bi-tools"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Assigned</div>
        <div class="stat-value text-success"><?= $assignedTools ?></div>
        <div class="stat-meta text-success"><?= $unassignedTools ?> tools unassigned</div>
        <div class="stat-icon text-success"><i class="bi bi-link-45deg"></i></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Warning</div>
        <div class="stat-value text-warning"><?= $warningTools ?></div>
        <div class="stat-meta text-warning">Require tool attention</div>
        <div class="stat-icon text-warning"><i class="bi bi-exclamation-triangle"></i></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Unavailable</div>
        <div class="stat-value text-primary"><?= $problemTools ?></div>
        <div class="stat-meta text-primary">Broken · Maintenance · Inactive</div>
        <div class="stat-icon text-primary"><i class="bi bi-wrench-adjustable-circle"></i></div>
    </div>
</div>
<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-4">
            <label for="toolSearch" class="filter-label">Search Tool</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                <input type="search" id="toolSearch" class="form-control border-start-0" placeholder="Code, name, type, machine..." autocomplete="off" name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
            </div>
        </div>
        <div class="col-12 col-md-4 col-lg-2">
            <label for="toolStatusFilter" class="filter-label">Status</label>
            <select id="toolStatusFilter" class="form-select" name="status" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="ready" <?= (string) service('request')->getGet('status') === 'ready' ? 'selected' : '' ?>>Ready</option>
                <option value="warning" <?= (string) service('request')->getGet('status') === 'warning' ? 'selected' : '' ?>>Warning</option>
                <option value="broken" <?= (string) service('request')->getGet('status') === 'broken' ? 'selected' : '' ?>>Broken</option>
                <option value="maintenance" <?= (string) service('request')->getGet('status') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                <option value="inactive" <?= (string) service('request')->getGet('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-12 col-md-4 col-lg-2">
            <label for="toolAssignmentFilter" class="filter-label">Assignment</label>
            <select id="toolAssignmentFilter" class="form-select" name="assignment" onchange="this.form.submit()">
                <option value="">All Assignment</option>
                <option value="assigned" <?= (string) service('request')->getGet('assignment') === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                <option value="unassigned" <?= (string) service('request')->getGet('assignment') === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
            </select>
        </div>
        <div class="col-12 col-md-3 col-lg-2">
            <label for="toolTypeFilter" class="filter-label">Tool Type</label>
            <select id="toolTypeFilter" class="form-select" name="type" onchange="this.form.submit()">
                <option value="">All Tool Types</option>
                <?php foreach ($toolTypes as $tt): ?>
                    <option value="<?= esc((string)($tt['code'] ?? ''), 'attr') ?>" <?= strtolower((string) service('request')->getGet('type')) === strtolower((string)($tt['code'] ?? '')) ? 'selected' : '' ?>><?= esc($tt['code'] ?? '-') ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="col-12 col-md-1 col-lg-2 d-flex">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>" title="Reset filter">Reset</a>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-2">
        <div class="tools-filter-result" id="toolFilterResult">Showing <?= count($tools) ?> of <?= (int) ($pagination['total'] ?? $totalTools) ?> tools</div>
    </div>
</form>
<div class="panel table-card">
    <div class="p-3 pb-0">
        <div class="tools-table-toolbar">
            <div class="tools-table-title"><i class="bi bi-tools text-primary"></i>Physical Tool Inventory</div>
            <div class="tools-table-count"><i class="bi bi-database"></i><?= $totalTools ?> records</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle" id="toolsTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'tool', 'label' => 'Tool', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'type', 'label' => 'Tool Type', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'edge', 'label' => 'Edge', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'lifetime', 'label' => 'Lifetime', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'machine', 'label' => 'Machine', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <th class="text-end">Actions</th>
</tr>
            </thead>
            <tbody>
                <?php foreach ($tools as $t): ?>
                    <?php
                    $status = strtolower((string)($t['status'] ?? 'inactive'));
                    $currentEdge = max(1, (int)($t['current_edge'] ?? 1));
                    $cuttingEdge = max(1, (int)($t['cutting_edge'] ?? 1));
                    $actualLifetime = max(0, (int)($t['actual_lifetime'] ?? 0));
                    $defaultLifetime = max(0, (int)($t['default_lifetime'] ?? 0));
                    $lifePercentage = $defaultLifetime > 0 ? min(100, ($actualLifetime / $defaultLifetime) * 100) : 0;
                    $remainingLifetime = $defaultLifetime > 0 ? max(0, $defaultLifetime - $actualLifetime) : null;
                    $lifeClass = '';
                    if ($defaultLifetime > 0 && $remainingLifetime !== null) {
                        if ($remainingLifetime <= 1) {
                            $lifeClass = 'danger';
                        } elseif ($remainingLifetime <= 5) {
                            $lifeClass = 'warning';
                        }
                    }
                    $assignment = !empty($t['machine_id']) ? 'assigned' : 'unassigned';
                    $toolTypeCode = strtolower((string)($t['tool_type_code'] ?? ''));
                    $searchText = strtolower(trim((string)($t['code'] ?? '') . ' ' . (string)($t['name'] ?? '') . ' ' . (string)($t['tool_type_code'] ?? '') . ' ' . (string)($t['holder'] ?? '') . ' ' . (string)($t['machine_code'] ?? '')));
                    ?>
                    <tr data-tool-row data-search="<?= esc($searchText, 'attr') ?>" data-status="<?= esc($status, 'attr') ?>" data-assignment="<?= esc($assignment, 'attr') ?>" data-tool-type="<?= esc($toolTypeCode, 'attr') ?>">
                        <td>
                            <div class="tool-identity">
                                <div class="tool-identity-icon"><i class="bi bi-wrench-adjustable"></i></div>
                                <div>
                                    <div class="tool-code"><?= esc($t['code'] ?? '-') ?></div>
                                    <div class="tool-name"><?= esc($t['name'] ?? '-') ?></div>
                                    <?php if (!empty($t['holder'])): ?><div class="tool-holder"><i class="bi bi-pin-angle me-1"></i>Holder: <?= esc($t['holder']) ?></div><?php endif ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="tool-type-badge"><i class="bi bi-tag"></i><?= esc($t['tool_type_code'] ?? '-') ?></span>
                        </td>
                        <td>
                            <div class="tool-edge-wrap">
                                <div class="tool-edge-value"><i class="bi bi-layers"></i><strong><?= $currentEdge ?></strong><span>/ <?= $cuttingEdge ?></span></div>
                                <div class="tool-edge-caption"><?= max(0, $cuttingEdge - $currentEdge) ?> edge remaining after current</div>
                            </div>
                        </td>
                        <td>
                            <div class="tool-lifetime">
                                <div class="tool-lifetime-top">
                                    <span><strong><?= number_format($actualLifetime) ?></strong> / <?= $defaultLifetime > 0 ? number_format($defaultLifetime) : '-' ?></span>
                                    <?php if ($remainingLifetime !== null): ?><span><?= number_format($remainingLifetime) ?> left</span><?php endif ?>
                                </div>
                                <?php if ($defaultLifetime > 0): ?>
                                    <div class="tool-lifetime-bar">
                                        <div class="tool-lifetime-fill <?= $lifeClass ?>" style="width:<?= number_format($lifePercentage, 2, '.', '') ?>%"></div>
                                    </div>
                                <?php else: ?>
                                    <div class="small text-secondary">Lifetime not configured</div>
                                <?php endif ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($t['machine_id'])): ?>
                                <div class="tool-machine">
                                    <span class="tool-machine-icon"><i class="bi bi-cpu"></i></span>
                                    <div>
                                        <div class="tool-machine-code"><?= esc($t['machine_code'] ?? '-') ?></div>
                                        <div class="tool-machine-position"><?= esc($t['machine_name'] ?? '-') ?></div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="tool-unassigned"><i class="bi bi-dash-circle"></i>Unassigned</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <span class="tool-status <?= esc($status, 'attr') ?>"><span class="tool-status-dot"></span><?= esc($statusLabels[$status] ?? ucfirst($status)) ?></span>
                        </td>
                        <td>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <div class="tool-actions">
                                    <button type="button" class="tool-action-btn" data-bs-toggle="modal" data-bs-target="#tool<?= (int)$t['id'] ?>" title="Edit Tool"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="tool-action-btn maintenance" data-bs-toggle="modal" data-bs-target="#maint<?= (int)$t['id'] ?>" title="Maintenance"><i class="bi bi-wrench"></i></button>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if (empty($tools)): ?>
                    <tr id="toolsEmptyInitial">
                        <td colspan="7" class="tools-empty-state"><i class="bi bi-tools"></i>Belum ada Physical Tool yang terdaftar.</td>
                    </tr>
                <?php endif ?>
                <tr id="toolsEmptyFilter" class="d-none">
                    <td colspan="7" class="tools-empty-state"><i class="bi bi-search"></i>Tidak ada Physical Tool yang sesuai dengan filter.</td>
                </tr>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>
<?php if (session()->get('role') === 'admin'): ?>
    <?php foreach (array_merge([['id' => 0, 'code' => '', 'name' => '', 'tool_type_id' => 0, 'cutting_edge' => 1, 'current_edge' => 1, 'holder' => '', 'default_lifetime' => '', 'status' => 'ready', 'notes' => '']], $tools) as $t): ?>
        <?php
        $toolId = (int)($t['id'] ?? 0);
        $isNew = $toolId === 0;
        $currentEdge = max(1, (int)($t['current_edge'] ?? 1));
        $cuttingEdge = max(1, (int)($t['cutting_edge'] ?? 1));
        $toolStatus = strtolower((string)($t['status'] ?? 'ready'));
        ?>
        <div class="modal fade" id="tool<?= $toolId ?>" tabindex="-1" aria-labelledby="toolLabel<?= $toolId ?>" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <form class="modal-content" method="post" action="<?= site_url('master-data/tools' . ($toolId ? '/' . $toolId . '/update' : '')) ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-6" id="toolLabel<?= $toolId ?>"><i class="bi <?= $isNew ? 'bi-plus-circle' : 'bi-pencil-square' ?> me-2 text-primary"></i><?= $isNew ? 'Add Physical Tool' : 'Edit Physical Tool' ?></h2>
                            <div class="small text-secondary mt-1"><?= $isNew ? 'Register a new physical cutting tool.' : 'Update physical tool information and configuration.' ?></div>
                        </div>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="tool-modal-section mb-3">
                            <div class="tool-modal-section-title"><i class="bi bi-info-circle"></i>Tool Information</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Code <span class="text-danger">*</span></label>
                                    <input class="form-control" name="code" required maxlength="100" value="<?= esc($t['code'] ?? '', 'attr') ?>" placeholder="Example: TOOL-001">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Name <span class="text-danger">*</span></label>
                                    <input class="form-control" name="name" required maxlength="150" value="<?= esc($t['name'] ?? '', 'attr') ?>" placeholder="Physical tool name">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Tool Type <span class="text-danger">*</span></label>
                                    <select class="form-select" name="tool_type_id" required>
                                        <option value="">Select Tool Type</option>
                                        <?php foreach ($toolTypes as $tt): ?>
                                            <option value="<?= (int)$tt['id'] ?>" <?= (int)$tt['id'] === (int)($t['tool_type_id'] ?? 0) ? 'selected' : '' ?>><?= esc($tt['code'] ?? '-') ?><?= !empty($tt['name']) ? ' · ' . esc($tt['name']) : '' ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Holder</label>
                                    <input class="form-control" name="holder" maxlength="150" value="<?= esc($t['holder'] ?? '', 'attr') ?>" placeholder="Holder information">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Notes</label>
                                    <input class="form-control" name="notes" maxlength="500" value="<?= esc($t['notes'] ?? '', 'attr') ?>" placeholder="Optional tool notes">
                                </div>
                            </div>
                        </div>
                        <div class="tool-modal-section">
                            <div class="tool-modal-section-title"><i class="bi bi-sliders"></i>Lifetime & Cutting Edge</div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Total Cutting Edge <span class="text-danger">*</span></label>
                                    <input class="form-control" type="number" min="1" max="65535" name="cutting_edge" value="<?= $cuttingEdge ?>" required>
                                    <div class="form-text">Total sisi/edge yang tersedia pada physical tool.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Current Edge <span class="text-danger">*</span></label>
                                    <input class="form-control" type="number" min="1" name="current_edge" value="<?= $currentEdge ?>" <?= $toolId ? 'readonly' : '' ?> required>
                                    <div class="form-text"><?= $toolId ? 'Pergantian edge dilakukan melalui Maintenance.' : 'Edge awal yang sedang digunakan.' ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Default Lifetime</label>
                                    <input class="form-control" type="number" min="1" name="default_lifetime" value="<?= esc($t['default_lifetime'] ?? '', 'attr') ?>" placeholder="Example: 100">
                                    <div class="form-text">Target lifetime untuk setiap edge.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Status</label>
                                    <select class="form-select" name="status">
                                        <?php foreach (['ready', 'warning', 'broken', 'maintenance', 'inactive'] as $st): ?>
                                            <option value="<?= $st ?>" <?= $st === $toolStatus ? 'selected' : '' ?>><?= esc($statusLabels[$st] ?? ucfirst($st)) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $isNew ? 'Create Tool' : 'Save Changes' ?></button>
                    </div>
                </form>
            </div>
        </div>
        <?php if ($toolId): ?>
            <div class="modal fade" id="maint<?= $toolId ?>" tabindex="-1" aria-labelledby="maintLabel<?= $toolId ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <div>
                                <h2 class="modal-title fs-6" id="maintLabel<?= $toolId ?>"><i class="bi bi-wrench-adjustable me-2 text-warning"></i>Tool Maintenance</h2>
                                <div class="small text-secondary mt-1"><?= esc($t['code'] ?? '-') ?> · Edge <?= $currentEdge ?>/<?= $cuttingEdge ?> · Lifetime <?= number_format((int)($t['actual_lifetime'] ?? 0)) ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="tool-maint-card">
                                <div class="tool-maint-title"><i class="bi bi-arrow-repeat text-primary"></i>Change Cutting Edge</div>
                                <div class="tool-maint-description">Gunakan ketika edge saat ini sudah tumpul/rusak tetapi physical tool masih memiliki edge berikutnya. Lifetime edge akan di-reset oleh proses maintenance tanpa dianggap mengganti physical tool.</div>
                                <form method="post" action="<?= site_url('master-data/tools/' . $toolId . '/change-edge') ?>" data-change-edge-form>
                                    <?= csrf_field() ?>
                                    <div class="mb-2">
                                        <label class="form-label">New Current Edge</label>
                                        <input class="form-control" name="current_edge" type="number" min="<?= $currentEdge + 1 ?>" max="<?= $cuttingEdge ?>" value="<?= min($currentEdge + 1, $cuttingEdge) ?>" required <?= $currentEdge >= $cuttingEdge ? 'disabled' : '' ?>>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Reason</label>
                                        <input class="form-control" name="reason" placeholder="Example: Edge 1 sudah tumpul" required maxlength="500" <?= $currentEdge >= $cuttingEdge ? 'disabled' : '' ?>>
                                    </div>
                                    <button type="submit" class="btn btn-outline-primary w-100" <?= $currentEdge >= $cuttingEdge ? 'disabled' : '' ?>><i class="bi bi-arrow-repeat me-1"></i><?= $currentEdge >= $cuttingEdge ? 'No Edge Available' : 'Change to Next Edge' ?></button>
                                </form>
                            </div>
                            <div class="tool-maint-card">
                                <div class="tool-maint-title"><i class="bi bi-arrow-counterclockwise text-warning"></i>Reset / Replace Physical Tool</div>
                                <div class="tool-maint-description">Gunakan hanya ketika lifetime memang perlu di-reset atau physical tool diganti. Aksi ini berbeda dengan pergantian edge.</div>
                                <form method="post" action="<?= site_url('master-data/tools/' . $toolId . '/reset-lifetime') ?>" data-reset-tool-form>
                                    <?= csrf_field() ?>
                                    <div class="mb-3">
                                        <label class="form-label">Reason</label>
                                        <input class="form-control" name="reason" placeholder="Physical replacement / reset reason" required maxlength="500">
                                    </div>
                                    <button type="submit" class="btn btn-outline-warning w-100"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset / Replace Physical Tool</button>
                                </form>
                            </div>
                            <div class="tool-maint-card">
                                <div class="tool-maint-title"><i class="bi bi-trash text-danger"></i>Delete Tool</div>
                                <div class="tool-maint-description">Hanya gunakan untuk tool yang belum memiliki penggunaan atau dependency produksi.</div>
                                <form method="post" action="<?= site_url('master-data/tools/' . $toolId . '/delete') ?>" data-delete-tool-form>
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i>Delete Unused Tool</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif ?>
    <?php endforeach ?>
<?php endif ?>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-change-edge-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!confirm('Ganti ke cutting edge berikutnya? Lifetime edge saat ini akan diproses sesuai aturan maintenance.')) event.preventDefault();
            });
        });
        document.querySelectorAll('[data-reset-tool-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!confirm('Reset lifetime / replace physical tool ini? Pastikan aksi ini bukan hanya pergantian edge.')) event.preventDefault();
            });
        });
        document.querySelectorAll('[data-delete-tool-form]').forEach(form => {
            form.addEventListener('submit', event => {
                if (!confirm('Delete physical tool ini? Aksi ini tidak dapat dibatalkan.')) event.preventDefault();
            });
        });
    });
</script>
<?= $this->endSection() ?>