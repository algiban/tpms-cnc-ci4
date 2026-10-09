<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?php
$types = $types ?? [];
$totalTypes = (int) ($typeStats['total'] ?? count($types));
$activeTypes = (int) ($typeStats['active'] ?? 0);
$inactiveTypes = $totalTypes - $activeTypes;
?>
<style>
    .tool-type-code {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        color: #253149;
        font-size: 13px;
        font-weight: 850
    }

    .tool-type-code-icon {
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

    .tool-type-name {
        color: #344054;
        font-size: 13px;
        font-weight: 700
    }

    .tool-type-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 850;
        text-transform: capitalize
    }

    .tool-type-status.active {
        background: #ecfdf3;
        color: #027a48
    }

    .tool-type-status.inactive {
        background: #f2f4f7;
        color: #667085
    }

    .tool-type-status-dot {
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: currentColor
    }

    .tool-type-actions {
        display: flex;
        justify-content: flex-end;
        gap: 6px
    }

    .tool-type-action {
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

    .tool-type-action:hover {
        border-color: #c7d2fe;
        background: #eef2ff;
        color: #4f46e5
    }

    .tool-type-filter-result {
        color: #7c8da6;
        font-size: 12px;
        font-weight: 700
    }

    .tool-type-empty {
        padding: 52px 24px !important;
        text-align: center;
        color: #98a2b3 !important
    }

    .tool-type-empty i {
        display: block;
        margin-bottom: 10px;
        font-size: 34px
    }

    .tool-type-table-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px
    }

    .tool-type-table-title {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #253149;
        font-size: 14px;
        font-weight: 850
    }

    .tool-type-table-count {
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

    .tool-type-modal-info {
        display: flex;
        gap: 9px;
        margin-top: 14px;
        padding: 10px 12px;
        border-radius: 10px;
        background: #f5f7ff;
        color: #667085;
        font-size: 11px;
        line-height: 1.5
    }

    .tool-type-modal-info i {
        margin-top: 1px;
        color: #4f46e5
    }

    @media(max-width:767.98px) {
        .tool-type-table-toolbar {
            align-items: flex-start;
            flex-direction: column
        }

        .tool-type-actions {
            justify-content: flex-start
        }
    }
</style>
<div class="page-heading">
    <div>
        <h1>Tool <span>Types</span></h1>
        <div class="page-subtitle"><?= $totalTypes ?> Tool Types · <?= $activeTypes ?> Active · <?= $inactiveTypes ?> Inactive</div>
    </div>
    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#type0"><i class="bi bi-plus-lg me-2"></i>Add Tool Type</button>
        </div>
    <?php endif ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'tool-types', 'label' => 'Tool Types', 'template' => 'tool_types_import.xlsx']]]) ?>
<div class="stat-grid mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total Tool Types</div>
        <div class="stat-value"><?= $totalTypes ?></div>
        <div class="stat-meta text-primary">Registered tool classifications</div>
        <div class="stat-icon text-primary"><i class="bi bi-tags"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">Active</div>
        <div class="stat-value text-success"><?= $activeTypes ?></div>
        <div class="stat-meta text-success">Available for production</div>
        <div class="stat-icon text-success"><i class="bi bi-check-circle"></i></div>
    </div>
    <div class="stat-card orange">
        <div class="stat-label">Inactive</div>
        <div class="stat-value text-warning"><?= $inactiveTypes ?></div>
        <div class="stat-meta text-warning">Not available for assignment</div>
        <div class="stat-icon text-warning"><i class="bi bi-pause-circle"></i></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Availability</div>
        <div class="stat-value text-primary"><?= $totalTypes > 0 ? number_format(($activeTypes / $totalTypes) * 100, 0) : 0 ?>%</div>
        <div class="stat-meta text-primary">Active tool type ratio</div>
        <div class="stat-icon text-primary"><i class="bi bi-pie-chart"></i></div>
    </div>
</div>
<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-7">
            <label for="toolTypeSearch" class="filter-label">Search Tool Type</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-secondary"></i></span>
                <input type="search" id="toolTypeSearch" class="form-control border-start-0" placeholder="Search code or tool type name..." autocomplete="off" name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
            </div>
        </div>
        <div class="col-12 col-md-7 col-lg-3">
            <label for="toolTypeStatusFilter" class="filter-label">Status</label>
            <select id="toolTypeStatusFilter" class="form-select" name="status" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="active" <?= (string) service('request')->getGet('status') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= (string) service('request')->getGet('status') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-12 col-md-5 col-lg-2 d-flex">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>" title="Reset filter">Reset</a>
        </div>
    </div>
    <div class="d-flex justify-content-end mt-2">
        <div class="tool-type-filter-result" id="toolTypeFilterResult">Showing <?= count($types) ?> of <?= (int) ($pagination['total'] ?? $totalTypes) ?> tool types</div>
    </div>
</form>
<div class="panel table-card">
    <div class="p-3 pb-0">
        <div class="tool-type-table-toolbar">
            <div class="tool-type-table-title"><i class="bi bi-tags text-primary"></i>Tool Type Master</div>
            <div class="tool-type-table-count"><i class="bi bi-database"></i><?= $totalTypes ?> records</div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle" id="toolTypeTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'code', 'label' => 'Code', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'name', 'label' => 'Name', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <th class="text-end">Actions</th>
</tr>
            </thead>
            <tbody>
                <?php foreach ($types as $t): ?>
                    <?php
                    $status = strtolower((string)($t['status'] ?? 'inactive'));
                    $searchText = strtolower(trim((string)($t['code'] ?? '') . ' ' . (string)($t['name'] ?? '')));
                    ?>
                    <tr data-tool-type-row data-search="<?= esc($searchText, 'attr') ?>" data-status="<?= esc($status, 'attr') ?>">
                        <td>
                            <div class="tool-type-code">
                                <span class="tool-type-code-icon"><i class="bi bi-tag"></i></span>
                                <span><?= esc($t['code'] ?? '-') ?></span>
                            </div>
                        </td>
                        <td><span class="tool-type-name"><?= esc($t['name'] ?? '-') ?></span></td>
                        <td>
                            <span class="tool-type-status <?= $status === 'active' ? 'active' : 'inactive' ?>">
                                <span class="tool-type-status-dot"></span>
                                <?= esc($status) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <div class="tool-type-actions">
                                    <button type="button" class="tool-type-action" data-bs-toggle="modal" data-bs-target="#type<?= (int)($t['id'] ?? 0) ?>" title="Edit Tool Type"><i class="bi bi-pencil"></i></button>
                                </div>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if (empty($types)): ?>
                    <tr id="toolTypeEmptyInitial">
                        <td colspan="4" class="tool-type-empty"><i class="bi bi-tags"></i>Belum ada Tool Type yang terdaftar.</td>
                    </tr>
                <?php endif ?>
                <tr id="toolTypeEmptyFilter" class="d-none">
                    <td colspan="4" class="tool-type-empty"><i class="bi bi-search"></i>Tidak ada Tool Type yang sesuai dengan filter.</td>
                </tr>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>
<?php if (session()->get('role') === 'admin'): ?>
    <?php foreach (array_merge([['id' => 0, 'code' => '', 'name' => '', 'status' => 'active']], $types) as $t): ?>
        <?php
        $isNew = ((int)($t['id'] ?? 0) === 0);
        $typeId = (int)($t['id'] ?? 0);
        $typeStatus = strtolower((string)($t['status'] ?? 'active'));
        ?>
        <div class="modal fade" id="type<?= $typeId ?>" tabindex="-1" aria-labelledby="typeLabel<?= $typeId ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="post" action="<?= site_url('master-data/tool-types' . ($typeId ? '/' . $typeId . '/update' : '')) ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <div>
                            <h2 class="modal-title fs-6" id="typeLabel<?= $typeId ?>"><i class="bi <?= $isNew ? 'bi-plus-circle' : 'bi-pencil-square' ?> me-2 text-primary"></i><?= $isNew ? 'Add Tool Type' : 'Edit Tool Type' ?></h2>
                            <div class="small text-secondary mt-1"><?= $isNew ? 'Create a new tool classification.' : 'Update tool type information and availability.' ?></div>
                        </div>
                        <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Code <span class="text-danger">*</span></label>
                            <input class="form-control" name="code" required maxlength="100" value="<?= esc($t['code'] ?? '', 'attr') ?>" placeholder="Example: DRILL">
                            <div class="form-text">Unique identifier untuk jenis tool.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input class="form-control" name="name" required maxlength="150" value="<?= esc($t['name'] ?? '', 'attr') ?>" placeholder="Example: Drill">
                            <div class="form-text">Nama jenis tool yang ditampilkan pada Parts dan Tools.</div>
                        </div>
                        <div>
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" <?= $typeStatus === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $typeStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="tool-type-modal-info">
                            <i class="bi bi-info-circle-fill"></i>
                            <div><strong>Tool Type</strong> digunakan sebagai klasifikasi kebutuhan tool pada setiap proses Part. Status inactive dapat digunakan untuk menonaktifkan jenis tool tanpa menghapus data historisnya.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i><?= $isNew ? 'Create Tool Type' : 'Save Changes' ?></button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach ?>
<?php endif ?>

<?= $this->endSection() ?>