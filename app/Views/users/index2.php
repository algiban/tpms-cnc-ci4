<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<div
    class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

    <div>

        <h1 class="page-title mb-1">
            Users
        </h1>

        <p class="text-secondary mb-0">
            Manage users who can access TPMS.
        </p>

    </div>

    <button
        class="btn btn-primary"
        data-bs-toggle="modal"
        data-bs-target="#addUserModal">
        <i class="bi bi-plus-lg me-2"></i>
        Add User
    </button>

</div>

<div class="card modern-card">

    <div class="card-body p-0">

        <div class="table-responsive">

            <table class="table table-hover align-middle mb-0">

                <thead>

                    <tr>
                        <th class="ps-4 py-3">#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created At</th>
                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($users as $index => $user): ?>

                        <tr>

                            <td class="ps-4">
                                <?= $index + 1 ?>
                            </td>

                            <td>

                                <div class="d-flex align-items-center gap-3">

                                    <div
                                        class="rounded-circle bg-primary-subtle
                                           text-primary d-flex
                                           align-items-center justify-content-center"
                                        style="width: 38px; height: 38px;">
                                        <i class="bi bi-person"></i>
                                    </div>

                                    <span class="fw-medium">
                                        <?= esc($user['username']) ?>
                                    </span>

                                </div>

                            </td>

                            <td>

                                <?php if ($user['role'] === 'admin'): ?>

                                    <span class="badge text-bg-primary">
                                        Admin
                                    </span>

                                <?php else: ?>

                                    <span class="badge text-bg-secondary">
                                        User
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td class="text-secondary">
                                <?= date(
                                    'd M Y H:i',
                                    strtotime($user['created_at'])
                                ) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<!-- Add User Modal -->

<div
    class="modal fade"
    id="addUserModal"
    tabindex="-1">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 rounded-4 shadow">

            <form
                action="<?= site_url('/users') ?>"
                method="post">

                <?= csrf_field() ?>

                <div class="modal-header border-0 px-4 pt-4">

                    <div>

                        <h5 class="modal-title fw-bold">
                            Add User
                        </h5>

                        <small class="text-secondary">
                            Create a new TPMS account.
                        </small>

                    </div>

                    <button
                        class="btn-close"
                        data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body px-4">

                    <?php
                    $errors = session()->getFlashdata('errors') ?? [];
                    ?>

                    <div class="mb-3">

                        <label class="form-label">
                            Username
                        </label>

                        <input
                            type="text"
                            name="username"
                            value="<?= old('username') ?>"
                            class="form-control"
                            placeholder="Username"
                            required>

                        <?php if (isset($errors['username'])): ?>

                            <small class="text-danger">
                                <?= esc($errors['username']) ?>
                            </small>

                        <?php endif; ?>

                    </div>

                    <div class="mb-3">

                        <label class="form-label">
                            Password
                        </label>

                        <input
                            type="password"
                            name="password"
                            class="form-control"
                            placeholder="Minimum 8 characters"
                            required>

                        <?php if (isset($errors['password'])): ?>

                            <small class="text-danger">
                                <?= esc($errors['password']) ?>
                            </small>

                        <?php endif; ?>

                    </div>

                    <div>

                        <label class="form-label">
                            Role
                        </label>

                        <select
                            name="role"
                            class="form-select"
                            required>

                            <option value="user">
                                User
                            </option>

                            <option value="admin">
                                Admin
                            </option>

                        </select>

                    </div>

                </div>

                <div class="modal-footer border-0 px-4 pb-4">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>
                        Create User
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php if (! empty(session()->getFlashdata('errors'))): ?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const modal = new bootstrap.Modal(
                document.getElementById('addUserModal')
            );

            modal.show();

        });
    </script>

<?php endif; ?>

<?= $this->endSection() ?>