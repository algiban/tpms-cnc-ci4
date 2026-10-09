<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$planningData = $planningData ?? [];
$machines = $planningData['machines'] ?? [];
$employees = $planningData['employees'] ?? [];
$shifts = $planningData['shifts'] ?? [];
$dates = $planningData['dates'] ?? [];
$schedule = $planningData['schedule'] ?? [];
$planningRoles = $planningData['planningRoles'] ?? [];
$currentRole = (string)($planningData['currentRole'] ?? 'operator');
$currentRoleLabel = (string)($planningData['currentRoleLabel'] ?? 'Operator');
$canEdit = !empty($planningData['canEdit']);
$totalMachines = count($machines);
$totalDates = count($dates);
$totalShifts = count($shifts);
$currentRoleEmployees = count(array_filter($employees, static fn(array $employee): bool => (string)($employee['role_key'] ?? '') === $currentRole && (string)($employee['status'] ?? 'active') === 'active'));
$plannedAssignments = 0;
$plannedEmployeeIds = [];
foreach ($schedule as $cell) {
    if (!is_array($cell)) continue;
    foreach ($cell as $employeeId) {
        if ((int)$employeeId <= 0) continue;
        $plannedAssignments++;
        $plannedEmployeeIds[(int)$employeeId] = true;
    }
}
$plannedEmployees = count($plannedEmployeeIds);
?>
<link rel="stylesheet" href="<?= esc(base_url('assets/css/planning-grid.css'), 'attr') ?>">
<style>
    .pe-page-meta {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap
    }

    .pe-role-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 11px;
        border-radius: 999px;
        background: #eef2ff;
        color: #4f46e5;
        font-size: 11px;
        font-weight: 850
    }

    .pe-access-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 7px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 850
    }

    .pe-access-badge.edit {
        background: #ecfdf3;
        color: #027a48
    }

    .pe-access-badge.view {
        background: #f2f4f7;
        color: #667085
    }

    .pe-overview-meta {
        display: flex;
        align-items: center;
        gap: 5px;
        color: #7c8da6;
        font-size: 11px;
        font-weight: 650
    }

    .pe-toolbar-shell {
        margin-bottom: 16px
    }

    .pe-board-heading-copy {
        min-width: 0
    }

    .pe-board-heading-copy strong {
        display: block;
        color: #253149;
        font-size: 13px;
        font-weight: 850
    }

    .pe-board-heading-copy small {
        display: block;
        margin-top: 2px;
        color: #98a2b3;
        font-size: 10px;
        font-weight: 600
    }

    .pe-month-current {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 8px;
        border-radius: 999px;
        background: #f5f7ff;
        color: #4f46e5;
        font-size: 9px;
        font-weight: 850;
        text-transform: uppercase;
        letter-spacing: .04em
    }

    .pe-view-alert {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 16px;
        padding: 11px 13px;
        border: 1px solid #e4e7ec;
        border-radius: 12px;
        background: #f8fafc;
        color: #667085;
        font-size: 11px;
        line-height: 1.55
    }

    .pe-view-alert i {
        margin-top: 1px;
        color: #4f46e5
    }

    @media(max-width:767.98px) {
        .pe-page-meta {
            width: 100%;
            justify-content: flex-start
        }

        .pe-board-header {
            align-items: flex-start !important
        }

        .pe-guide {
            width: 100%
        }
    }
</style>
<section id="planningApp" class="pe-app" aria-label="Planning Employee">
    <div class="page-heading">
        <div>
            <h1>Planning <span>Employee</span></h1>
            <div class="page-subtitle">Shift Schedule & Employee Placement per Machine · <?= esc($currentRoleLabel) ?></div>
        </div>
        <div class="pe-page-meta">
            <span class="pe-role-badge"><i class="bi bi-person-badge"></i><?= esc($currentRoleLabel) ?></span>
            <?php if ($canEdit): ?>
                <span class="pe-access-badge edit"><i class="bi bi-pencil-square"></i>Planning Editable</span>
            <?php else: ?>
                <span class="pe-access-badge view"><i class="bi bi-eye"></i>View Only</span>
            <?php endif ?>
        </div>
    </div>
    <div class="stat-grid">
        <div class="stat-card blue">
            <div class="stat-label">Machines</div>
            <div class="stat-value"><?= $totalMachines ?></div>
            <div class="stat-meta text-primary">Available for planning</div>
            <div class="stat-icon text-primary"><i class="bi bi-cpu"></i></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label"><?= esc($currentRoleLabel) ?></div>
            <div class="stat-value text-success"><?= $currentRoleEmployees ?></div>
            <div class="stat-meta text-success">Active employees</div>
            <div class="stat-icon text-success"><i class="bi bi-people"></i></div>
        </div>
        <div class="stat-card purple">
            <div class="stat-label">Planned Assignments</div>
            <div class="stat-value text-primary"><?= $plannedAssignments ?></div>
            <div class="stat-meta text-primary"><?= $plannedEmployees ?> employees scheduled</div>
            <div class="stat-icon text-primary"><i class="bi bi-calendar2-check"></i></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Planning Period</div>
            <div class="stat-value text-warning"><?= $totalDates ?></div>
            <div class="stat-meta text-warning"><?= $totalShifts ?> shifts per day</div>
            <div class="stat-icon text-warning"><i class="bi bi-calendar3"></i></div>
        </div>
    </div>
    <?php if (!$canEdit): ?>
        <div class="pe-view-alert"><i class="bi bi-info-circle-fill"></i>
            <div>Kamu sedang membuka Planning Employee dalam mode <strong>view only</strong>. Hanya Admin yang dapat membuat draft, mengubah placement employee, dan menyimpan planning.</div>
        </div>
    <?php endif ?>
    <div class="pe-toolbar pe-toolbar-shell">
        <div class="pe-month-control">
            <a id="pePrevious" class="pe-month-arrow" aria-label="Bulan sebelumnya">‹</a>
            <label class="pe-month-label" for="peMonth">
                <span id="peMonthTitle"></span>
                <input id="peMonth" type="month" min="2000-01" max="2099-12" aria-label="Pilih bulan">
            </label>
            <a id="peNext" class="pe-month-arrow" aria-label="Bulan berikutnya">›</a>
        </div>
        <div class="pe-legend">
            <span><i class="pe-dot pe-green"></i>Shift 1</span>
            <span><i class="pe-dot pe-blue"></i>Shift 2</span>
            <span><i class="pe-dot pe-purple"></i>Shift 3</span>
            <span><i class="pe-dot pe-yellow"></i>Not saved yet</span>
        </div>
        <div class="pe-toolbar-actions">
            <span id="peDraftCount" class="pe-draft-count" hidden></span>
            <button id="peDiscard" class="pe-btn pe-btn-light" type="button" disabled><i class="bi bi-arrow-counterclockwise me-1"></i>Buang Draft</button>
            <button id="peSave" class="pe-btn pe-btn-save" type="button" disabled><i class="bi bi-floppy me-1"></i>Save All</button>
        </div>
    </div>
    <div id="peMessage" class="pe-message" role="status" aria-live="polite" hidden></div>
    <div class="pe-board">
        <div class="pe-board-header">
            <nav class="pe-role-tabs" aria-label="Role employee planning">
                <?php foreach ($planningRoles as $role): ?>
                    <?php
                    $active = ($role['key'] ?? '') === $currentRole;
                    $icon = match ($role['key'] ?? '') {
                        'operator' => 'bi-person-gear',
                        'unit_head' => 'bi-bullseye',
                        'leader' => 'bi-person-badge',
                        default => 'bi-person-badge',
                    };
                    ?>
                    <a class="pe-role-tab<?= $active ? ' active' : '' ?>" href="<?= esc($role['url'] ?? '#', 'attr') ?>" data-role-tab="<?= esc($role['key'] ?? '', 'attr') ?>" aria-current="<?= $active ? 'page' : 'false' ?>">
                        <i class="bi <?= esc($icon, 'attr') ?>"></i>
                        <span><?= esc($role['label'] ?? '-') ?></span>
                        <small><?= (int)($role['count'] ?? 0) ?></small>
                    </a>
                <?php endforeach ?>
            </nav>
            <span class="pe-guide"><i class="bi bi-info-circle me-1"></i>Scheduling <?= esc(strtolower($currentRoleLabel)) ?> per shift · klik tanggal atau tarik untuk memilih rentang.</span>
        </div>
        <div id="peScroll" class="pe-scroll" tabindex="0" aria-label="Kalender penjadwalan, geser untuk melihat seluruh bulan">
            <table id="peTable" class="pe-table">
                <caption class="pe-sr-only">Jadwal <?= esc(strtolower($currentRoleLabel)) ?> per mesin dan tanggal</caption>
                <thead id="peHead"></thead>
                <tbody id="peBody"></tbody>
            </table>
        </div>
        <footer class="pe-board-footer">
            <span id="peSummary"></span>
            <span><span class="pe-key">Shift + klik</span> pilih rentang · <span class="pe-key">Esc</span> tutup panel</span>
        </footer>
    </div>
    <section id="peEditor" class="pe-editor" role="dialog" aria-modal="false" aria-labelledby="peEditorTitle" hidden>
        <header class="pe-editor-heading">
            <div>
                <h2 id="peEditorTitle"></h2>
                <p id="peEditorDates"></p>
            </div>
            <button id="peClose" type="button" aria-label="Tutup panel">×</button>
        </header>
        <div id="peSelectionCount" class="pe-selection-count"></div>
        <div class="pe-editor-body">
            <p id="peEditorEyebrow" class="pe-editor-eyebrow"><?= esc(strtoupper($currentRoleLabel)) ?> PER SHIFT</p>
            <div id="pePickers"></div>
            <p class="pe-editor-hint">Apply membuat draft untuk <?= esc(strtolower($currentRoleLabel)) ?>. Data disimpan saat Save All.</p>
            <p id="peEditorError" class="pe-editor-error" role="alert" hidden></p>
            <div class="pe-editor-actions">
                <button id="peApply" class="pe-btn pe-btn-primary" type="button"><i class="bi bi-check-lg me-1"></i>Apply</button>
                <button id="peClear" class="pe-btn pe-btn-clear" type="button" title="Kosongkan ketiga shift" aria-label="Kosongkan ketiga shift"><i class="bi bi-eraser"></i></button>
                <button id="peCancel" class="pe-btn pe-btn-light" type="button">Cancel</button>
            </div>
        </div>
    </section>
</section>
<script id="planningData" type="application/json">
    <?= json_encode($planningData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>
</script>
<script src="<?= esc(base_url('assets/js/planning-grid.js'), 'attr') ?>" defer></script>
<?= $this->endSection() ?>