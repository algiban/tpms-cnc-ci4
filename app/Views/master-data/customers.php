<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$customers = $customers ?? [];
$totalParts = (int) ($partStats['total'] ?? array_sum(array_column($customers, 'parts')));
$activeParts = (int) ($partStats['active'] ?? array_sum(array_column($customers, 'active')));
?>


<div class="page-heading">
    <div>
        <h1>Customer <span>Management</span></h1>
        <div class="page-subtitle">
            <?= (int) ($customerStats['total'] ?? count($customers)) ?> Customers · <?= $totalParts ?> Total Parts
        </div>
    </div>

    <?php if (session()->get('role') === 'admin'): ?>
        <div class="action-row">
            <button
                type="button"
                class="btn btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#addCustomerModal">
                <i class="bi bi-plus-lg me-2"></i>Add Customer
            </button>
        </div>
    <?php endif; ?>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'customers', 'label' => 'Customers', 'import' => true, 'template' => 'customers_import.xlsx']]]) ?>

<div class="stat-grid cols-3 mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total Customers</div>
        <div class="stat-value"><?= (int) ($customerStats['total'] ?? count($customers)) ?></div>
        <div class="stat-icon text-primary">
            <i class="bi bi-buildings"></i>
        </div>
    </div>

    <div class="stat-card purple">
        <div class="stat-label">Total Parts</div>
        <div class="stat-value text-primary"><?= $totalParts ?></div>
        <div class="stat-icon text-primary">
            <i class="bi bi-puzzle"></i>
        </div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Active Parts</div>
        <div class="stat-value text-success"><?= $activeParts ?></div>
        <div class="stat-icon text-success">
            <i class="bi bi-check-circle"></i>
        </div>
    </div>
</div>

<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <label for="customerSearch" class="filter-label">
        Search Name / Customer Code
    </label>
    <input
        type="search"
        id="customerSearch"
        class="form-control"
        placeholder="Name or customer code..."
         name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
<div class="mt-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></div>
</form>

<div class="panel table-card">
    <div class="table-responsive">
        <table class="table" id="customerTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'customer', 'label' => 'Customer', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'code', 'label' => 'Code', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'parts', 'label' => 'Total Parts', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'active', 'label' => 'Active Parts', 'pagination' => $pagination]) ?>
                    <th>Actions</th>
</tr>
            </thead>

            <tbody>
                <?php foreach ($customers as $index => $c): ?>
                    <?php $modalKey = 'customer' . bin2hex((string) $index); ?>

                    <tr>
                        <td>
                            <strong><?= esc($c['name'] ?? '') ?></strong>
                        </td>

                        <td class="mono"><?= esc($c['code'] ?? '') ?></td>

                        <td><?= esc($c['parts'] ?? 0) ?></td>

                        <td>
                            <span class="badge-soft success">
                                <?= esc($c['active'] ?? 0) ?> active
                            </span>
                        </td>

                        <td class="text-nowrap">
                            <button
                                type="button"
                                class="action-icon"
                                data-bs-toggle="modal"
                                data-bs-target="#<?= $modalKey ?>Detail"
                                aria-label="View customer detail">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <button
                                    type="button"
                                    class="action-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Edit"
                                    aria-label="Edit customer">
                                    <i class="bi bi-pencil"></i>
                                </button>

                                <button
                                    type="button"
                                    class="action-icon text-danger"
                                    data-bs-toggle="modal"
                                    data-bs-target="#<?= $modalKey ?>Delete"
                                    aria-label="Delete customer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-secondary">
                            No customers available.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>

<?php foreach ($customers as $index => $c): ?>
    <?php
    $modalKey = 'customer' . bin2hex((string) $index);
    $customerId = rawurlencode((string) $c['id']);
    $partsList = $c['parts_list'] ?? [];
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
                        Customer Detail · <?= esc($c['name'] ?? '') ?>
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
                            <strong><?= esc($c['code'] ?? '') ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Name</small>
                            <strong><?= esc($c['name'] ?? '') ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Total Parts</small>
                            <strong><?= esc($c['parts'] ?? 0) ?></strong>
                        </div>

                        <div class="detail-item">
                            <small>Active Parts</small>
                            <strong><?= esc($c['active'] ?? 0) ?></strong>
                        </div>
                    </div>

                    <div class="detail-section-title">Ordered Parts</div>

                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Part Number</th>
                                    <th>Name</th>
                                    <th>Material</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($partsList as $p): ?>
                                    <?php $partStatus = $p['status'] ?? ''; ?>

                                    <tr>
                                        <td class="mono">
                                            <?= esc($p['part_number'] ?? '-') ?>
                                        </td>

                                        <td><?= esc($p['name'] ?? '-') ?></td>

                                        <td>
                                            <?= esc(
                                                ($p['material_code'] ?? '-')
                                                    . ' · '
                                                    . ($p['material_name'] ?? '-')
                                            ) ?>
                                        </td>

                                        <td>
                                            <span class="badge-soft <?= $partStatus === 'active' ? 'success' : 'muted' ?>">
                                                <?= esc($partStatus !== '' ? $partStatus : '-') ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($partsList)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-secondary">
                                            No parts yet.
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
                    action="<?= esc(site_url('master-data/customers/' . $customerId . '/update'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= $modalKey ?>EditLabel">
                            Edit Customer
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
                            value="<?= esc($c['code'] ?? '', 'attr') ?>"
                            required>

                        <label for="<?= $modalKey ?>Name" class="form-label">
                            Name
                        </label>
                        <input
                            type="text"
                            id="<?= $modalKey ?>Name"
                            class="form-control"
                            name="name"
                            value="<?= esc($c['name'] ?? '', 'attr') ?>"
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
                    action="<?= esc(site_url('master-data/customers/' . $customerId . '/delete'), 'attr') ?>">
                    <?= csrf_field() ?>

                    <div class="modal-header">
                        <h5
                            class="modal-title text-danger"
                            id="<?= $modalKey ?>DeleteLabel">
                            Delete Customer
                        </h5>

                        <button
                            type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <p>
                            Delete <strong><?= esc($c['name'] ?? '') ?></strong>?
                        </p>
                        <p class="text-secondary mb-0">
                            Customer yang masih memiliki part tidak dapat dihapus.
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
    id="addCustomerModal"
    tabindex="-1"
    aria-labelledby="addCustomerModalLabel"
    aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form
                method="post"
                action="<?= esc(site_url('master-data/customers'), 'attr') ?>">
                <?= csrf_field() ?>

                <div class="modal-header">
                    <h5 class="modal-title" id="addCustomerModalLabel">
                        Add Customer
                    </h5>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <label for="addCustomerName" class="form-label">
                        Customer Name
                    </label>
                    <input
                        type="text"
                        id="addCustomerName"
                        class="form-control mb-3"
                        name="name"
                        required>

                    <label for="addCustomerCode" class="form-label">
                        Customer Code
                    </label>
                    <input
                        type="text"
                        id="addCustomerCode"
                        class="form-control"
                        name="code"
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
                        Save Customer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>