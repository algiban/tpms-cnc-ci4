<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$materials = $materials ?? [];
$used = (int) ($materialStats['used'] ?? 0);
?>


<div class="page-heading">
    <div>
        <h1>Material <span>Management</span></h1>
        <div class="page-subtitle">
            <?= (int) ($materialStats['total'] ?? count($materials)) ?> Materials · <?= $used ?> Used
        </div>
    </div>

    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addMaterialModal">
                <i class="bi bi-plus-lg me-2"></i>Add Material
            </button>
        </div>
    <?php endif; ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'materials', 'label' => 'Materials', 'import' => true, 'template' => 'materials_import.xlsx']]]) ?>

<div class="stat-grid cols-3 mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total Materials</div>
        <div class="stat-value"><?= (int) ($materialStats['total'] ?? count($materials)) ?></div>
        <div class="stat-icon text-primary">
            <i class="bi bi-box"></i>
        </div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Used in Parts</div>
        <div class="stat-value text-success"><?= $used ?></div>
        <div class="stat-icon text-success">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>

    <div class="stat-card orange">
        <div class="stat-label">Not Used Yet</div>
        <div class="stat-value text-warning">
            <?= (int) ($materialStats['total'] ?? count($materials)) - $used ?>
        </div>
        <div class="stat-icon text-warning">
            <i class="bi bi-exclamation-circle"></i>
        </div>
    </div>
</div>

<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <label for="materialSearch" class="filter-label">Search Code / Name</label>
    <input
        type="search"
        id="materialSearch"
        class="form-control"
        placeholder="Code or material name..."
         name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
<div class="mt-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></div>
</form>

<div class="panel table-card">
    <div class="table-responsive">
        <table class="table" id="materialTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'code', 'label' => 'Code', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'name', 'label' => 'Material Name', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'parts', 'label' => 'Used in Parts', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'created', 'label' => 'Created', 'pagination' => $pagination]) ?>
                    <th>Actions</th>
</tr>
            </thead>

            <tbody>
                <?php foreach ($materials as $index => $m): ?>
                    <?php
                    $modalKey = 'material' . bin2hex((string) $index);
                    $partCount = $m['parts'] ?? 0;
                    ?>

                    <tr>
                        <td class="mono"><?= esc($m['code'] ?? '') ?></td>

                        <td>
                            <strong><?= esc($m['name'] ?? '') ?></strong>
                        </td>

                        <td>
                            <span class="badge-soft <?= $partCount > 0 ? 'success' : 'muted' ?>">
                                <?= esc($partCount) ?> part
                            </span>
                        </td>

                        <td><?= esc($m['created'] ?? '-') ?></td>

                        <td class="text-nowrap">
                            <button
                                type="button"
                                class="action-icon"
                                data-bs-toggle="modal"
                                data-bs-target="#<?= $modalKey ?>Detail"
                                aria-label="View material detail">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <button
                                    type="button"
                                    class="action-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Edit"
                                    aria-label="Edit material">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <button
                                    type="button"
                                    class="action-icon text-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Delete"
                                    aria-label="Delete material">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($materials)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary">
                            No materials available.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>

<?php foreach ($materials as $index => $m): ?>
    <?php
    $modalKey = 'material' . bin2hex((string) $index);
    $materialId = rawurlencode((string) $m['id']);
    $partsList = $m['parts_list'] ?? [];
    ?>

    <div
        class="modal fade"
        id="<?= $modalKey ?>Detail"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>DetailLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="<?= $modalKey ?>DetailLabel">
                        Material Detail · <?= esc($m['code'] ?? '') ?>
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="detail-list mb-4">
                        <div class="detail-item">
                            <small>Code</small>
                            <strong class="mono"><?= esc($m['code'] ?? '') ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Name</small>
                            <strong><?= esc($m['name'] ?? '') ?></strong>
                        </div>
                    </div>

                    <div class="detail-section-title">
                        Parts Using This Material
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Part Number</th>
                                    <th>Name</th>
                                    <th>Customer</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($partsList as $p): ?>
                                    <tr>
                                        <td class="mono">
                                            <?= esc($p['part_number'] ?? '-') ?>
                                        </td>
                                        <td><?= esc($p['name'] ?? '-') ?></td>
                                        <td><?= esc($p['customer_name'] ?? '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($partsList)): ?>
                                    <tr>
                                        <td colspan="3" class="text-center text-secondary">
                                            Not used yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
        id="<?= $modalKey ?>Edit"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>EditLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form
                    method="post"
                    action="<?= esc(site_url('master-data/materials/' . $materialId . '/update'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= $modalKey ?>EditLabel">
                            Edit Material
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
                            value="<?= esc($m['code'] ?? '', 'attr') ?>"
                            required>

                        <label for="<?= $modalKey ?>Name" class="form-label">
                            Name
                        </label>
                        <input
                            type="text"
                            id="<?= $modalKey ?>Name"
                            class="form-control"
                            name="name"
                            value="<?= esc($m['name'] ?? '', 'attr') ?>"
                            required>
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

    <div
        class="modal fade"
        id="<?= $modalKey ?>Delete"
        tabindex="-1"
        aria-labelledby="<?= $modalKey ?>DeleteLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form
                    method="post"
                    action="<?= esc(site_url('master-data/materials/' . $materialId . '/delete'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5
                            class="modal-title text-danger"
                            id="<?= $modalKey ?>DeleteLabel">
                            Delete Material
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p>
                            Delete <strong><?= esc($m['name'] ?? '') ?></strong>?
                        </p>
                        <p class="text-secondary mb-0">
                            Material yang masih dipakai part tidak dapat dihapus.
                        </p>
                    </div>

                    <div class="modal-footer">
                        <button
                            type="button"
                            class="btn btn-light"
                            data-bs-dismiss="modal">
                            Cancel
                        </button>

                        <button type="submit" class="btn btn-danger">
                            Delete
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<div
    class="modal fade"
    id="addMaterialModal"
    tabindex="-1"
    aria-labelledby="addMaterialModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form
                method="post"
                action="<?= esc(site_url('master-data/materials'), 'attr') ?>">
                <?= csrf_field() ?>

                <div class="modal-header">
                    <h5 class="modal-title" id="addMaterialModalLabel">
                        Add Material
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <label for="addMaterialCode" class="form-label">Code</label>
                    <input
                        type="text"
                        id="addMaterialCode"
                        class="form-control mb-3"
                        name="code"
                        required>

                    <label for="addMaterialName" class="form-label">Name</label>
                    <input
                        type="text"
                        id="addMaterialName"
                        class="form-control"
                        name="name"
                        required>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit" class="btn btn-primary">
                        Save Material
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>