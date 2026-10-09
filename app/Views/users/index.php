<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$users = $users ?? [];
$errors = session()->getFlashdata('errors') ?? [];
$currentUserId = (int) (session()->get('user_id') ?? 0);

$totalUsers = count($users);
$adminCount = count(array_filter(
    $users,
    static fn (array $user): bool => ($user['role'] ?? '') === 'admin'
));
$userCount = $totalUsers - $adminCount;

$createdToday = count(array_filter(
    $users,
    static function (array $user): bool {
        if (empty($user['created_at'])) {
            return false;
        }

        $timestamp = strtotime((string) $user['created_at']);

        return $timestamp !== false
            && date('Y-m-d', $timestamp) === date('Y-m-d');
    }
));

$initial = static function (?string $username): string {
    $username = trim((string) $username);

    if ($username === '') {
        return '?';
    }

    return strtoupper(substr($username, 0, 1));
};

$formatDate = static function (?string $value): string {
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date('d M Y, H:i', $timestamp)
        : '-';
};
?>

<style>
.user-avatar-table {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    flex: 0 0 42px;
    border-radius: 13px;
    color: #fff;
    background: linear-gradient(135deg, #4f46e5, #2563eb);
    font-size: 14px;
    font-weight: 900;
    box-shadow: 0 7px 18px rgba(79, 70, 229, .18);
}

.user-table-name {
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-table-name strong {
    display: block;
    color: #253149;
    font-size: 14px;
    font-weight: 800;
}

.user-table-name small {
    display: block;
    margin-top: 2px;
    color: #98a2b3;
    font-size: 11px;
    font-weight: 600;
}

.user-current-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-left: 7px;
    padding: 3px 7px;
    border-radius: 999px;
    background: #eef2ff;
    color: #4f46e5;
    font-size: 10px;
    font-weight: 800;
    vertical-align: middle;
}

.user-role-icon {
    width: 30px;
    height: 30px;
    display: inline-grid;
    place-items: center;
    margin-right: 7px;
    border-radius: 9px;
    font-size: 13px;
}

.user-role-icon.admin {
    background: #ede9fe;
    color: #6d28d9;
}

.user-role-icon.user {
    background: #eef2f7;
    color: #667085;
}

.user-empty-state {
    padding: 52px 24px !important;
    text-align: center;
    color: #98a2b3 !important;
}

.user-empty-state i {
    display: block;
    margin-bottom: 10px;
    font-size: 34px;
}

.user-filter-result {
    color: #7c8da6;
    font-size: 12px;
    font-weight: 700;
}

.password-wrap {
    position: relative;
}

.password-wrap .form-control {
    padding-right: 46px;
}

.password-toggle {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    width: 32px;
    height: 32px;
    display: grid;
    place-items: center;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #7c8da6;
}

.password-toggle:hover {
    background: #f2f4f7;
    color: #344054;
}

@media (max-width: 767.98px) {
    .user-filter-actions {
        width: 100%;
    }

    .user-filter-actions .btn {
        flex: 1;
    }
}
</style>

<div class="page-heading">
    <div>
        <h1>User <span>Management</span></h1>
        <div class="page-subtitle">
            <?= $totalUsers ?> Accounts · <?= $adminCount ?> Admin · <?= $userCount ?> User
        </div>
    </div>

    <div class="action-row">
        <button
            type="button"
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#addUserModal">
            <i class="bi bi-plus-lg me-2"></i>Add User
        </button>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card blue">
        <div class="stat-label">Total Accounts</div>
        <div class="stat-value"><?= $totalUsers ?></div>
        <div class="stat-meta text-primary">Registered TPMS users</div>
        <div class="stat-icon text-primary">
            <i class="bi bi-people"></i>
        </div>
    </div>

    <div class="stat-card purple">
        <div class="stat-label">Administrators</div>
        <div class="stat-value text-primary"><?= $adminCount ?></div>
        <div class="stat-meta text-primary">Full system access</div>
        <div class="stat-icon text-primary">
            <i class="bi bi-shield-lock"></i>
        </div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Standard Users</div>
        <div class="stat-value text-success"><?= $userCount ?></div>
        <div class="stat-meta text-success">Operational access</div>
        <div class="stat-icon text-success">
            <i class="bi bi-person-check"></i>
        </div>
    </div>

    <div class="stat-card orange">
        <div class="stat-label">Created Today</div>
        <div class="stat-value text-warning"><?= $createdToday ?></div>
        <div class="stat-meta text-warning"><?= date('d M Y') ?></div>
        <div class="stat-icon text-warning">
            <i class="bi bi-person-plus"></i>
        </div>
    </div>
</div>

<div class="panel filter-panel">
    <div class="row g-3 align-items-end">
        <div class="col-12 col-lg-7">
            <label for="userSearch" class="filter-label">Search Username</label>
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0">
                    <i class="bi bi-search text-secondary"></i>
                </span>
                <input
                    type="search"
                    id="userSearch"
                    class="form-control border-start-0"
                    placeholder="Search user account..."
                    autocomplete="off">
            </div>
        </div>

        <div class="col-12 col-md-6 col-lg-3">
            <label for="userRoleFilter" class="filter-label">Role</label>
            <select id="userRoleFilter" class="form-select">
                <option value="">All Roles</option>
                <option value="admin">Admin</option>
                <option value="user">User</option>
            </select>
        </div>

        <div class="col-12 col-md-6 col-lg-2 d-flex gap-2 user-filter-actions">
            <button type="button" class="btn btn-light border w-100" id="resetUserFilter">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </button>
        </div>
    </div>

    <div class="d-flex justify-content-end mt-2">
        <div class="user-filter-result" id="userFilterResult">
            Showing <?= $totalUsers ?> of <?= $totalUsers ?> users
        </div>
    </div>
</div>

<div class="panel table-card">
    <div class="panel-header">
        <div>
            <h2 class="panel-title">
                <i class="bi bi-people me-2 text-primary"></i>User Accounts
            </h2>
        </div>

        <span class="badge-soft info">
            <i class="bi bi-database"></i>
            <?= $totalUsers ?> records
        </span>
    </div>

    <div class="table-responsive">
        <table class="table" id="userTable">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Role</th>
                    <th>RFID UID</th>
                    <th>Access</th>
                    <th>Created At</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($users as $user): ?>
                    <?php
                    $role = strtolower((string) ($user['role'] ?? 'user'));
                    $isAdmin = $role === 'admin';
                    $isCurrent = $currentUserId > 0 && $currentUserId === (int) ($user['id'] ?? 0);
                    ?>

                    <tr
                        data-user-row
                        data-username="<?= esc(strtolower((string) ($user['username'] ?? '')), 'attr') ?>"
                        data-role="<?= esc($role, 'attr') ?>">
                        <td>
                            <div class="user-table-name">
                                <div class="user-avatar-table">
                                    <?= esc($initial($user['username'] ?? '')) ?>
                                </div>

                                <div>
                                    <strong>
                                        <?= esc($user['username'] ?? '-') ?>

                                        <?php if ($isCurrent): ?>
                                            <span class="user-current-badge">
                                                <i class="bi bi-check-circle-fill"></i>
                                                Current
                                            </span>
                                        <?php endif; ?>
                                    </strong>

                                    <small>
                                        Account ID #<?= (int) ($user['id'] ?? 0) ?>
                                    </small>
                                </div>
                            </div>
                        </td>

                        <td>
                            <span class="user-role-icon <?= $isAdmin ? 'admin' : 'user' ?>">
                                <i class="bi <?= $isAdmin ? 'bi-shield-lock' : 'bi-person' ?>"></i>
                            </span>

                            <span class="badge-soft <?= $isAdmin ? 'purple' : 'muted' ?>">
                                <?= $isAdmin ? 'Admin' : 'User' ?>
                            </span>
                        </td>

                        <td>
                            <?php if (!empty($user['rfid_uid'])): ?>
                                <span class="mono"><?= esc($user['rfid_uid']) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">-</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($isAdmin): ?>
                                <span class="badge-soft success">
                                    <i class="bi bi-check-circle"></i>
                                    Full Access
                                </span>
                            <?php else: ?>
                                <span class="badge-soft info">
                                    <i class="bi bi-eye"></i>
                                    Operational
                                </span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?= esc($formatDate($user['created_at'] ?? null)) ?></strong>
                            <div class="text-secondary small">
                                <?= ! empty($user['created_at'])
                                    ? esc(date('l', strtotime($user['created_at'])))
                                    : '-' ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (empty($users)): ?>
                    <tr id="userEmptyInitial">
                        <td colspan="5" class="user-empty-state">
                            <i class="bi bi-people"></i>
                            Belum ada user terdaftar.
                        </td>
                    </tr>
                <?php endif; ?>

                <tr id="userEmptyFilter" class="d-none">
                    <td colspan="5" class="user-empty-state">
                        <i class="bi bi-search"></i>
                        Tidak ada user yang sesuai dengan filter.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD USER MODAL -->
<div
    class="modal fade"
    id="addUserModal"
    tabindex="-1"
    aria-labelledby="addUserModalLabel"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="<?= esc(site_url('users'), 'attr') ?>" method="post">
                <?= csrf_field() ?>

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="addUserModalLabel">
                            Add User
                        </h5>
                        <div class="text-secondary small mt-1">
                            Create a new account for TPMS access.
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="newUsername" class="form-label">Username</label>
                        <input
                            type="text"
                            id="newUsername"
                            name="username"
                            value="<?= esc(old('username'), 'attr') ?>"
                            class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                            placeholder="Enter username"
                            minlength="3"
                            maxlength="50"
                            autocomplete="username"
                            required>

                        <?php if (isset($errors['username'])): ?>
                            <div class="invalid-feedback">
                                <?= esc($errors['username']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="newPassword" class="form-label">Password</label>

                        <div class="password-wrap">
                            <input
                                type="password"
                                id="newPassword"
                                name="password"
                                class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                                placeholder="Minimum 8 characters"
                                minlength="8"
                                autocomplete="new-password"
                                required>

                            <button
                                type="button"
                                class="password-toggle"
                                id="passwordToggle"
                                aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                        <?php if (isset($errors['password'])): ?>
                            <div class="text-danger small mt-1">
                                <?= esc($errors['password']) ?>
                            </div>
                        <?php else: ?>
                            <div class="form-text">
                                Gunakan minimal 8 karakter.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="newUserRfidUid" class="form-label">RFID UID</label>
                        <div class="input-group">
                            <input type="text" id="newUserRfidUid" name="rfid_uid" value="<?= esc(old('rfid_uid'), 'attr') ?>" class="form-control <?= isset($errors['rfid_uid']) ? 'is-invalid' : '' ?>" placeholder="A1B2C3D4">
                            <button type="button" class="btn btn-outline-primary" data-rfid-read data-rfid-purpose="user" data-rfid-target="#newUserRfidUid" data-rfid-info="#newUserRfidInfo"><i class="bi bi-broadcast-pin me-1"></i>Baca RFID</button>
                        </div>
                        <div id="newUserRfidInfo" class="form-text">Klik Baca RFID lalu scan User pada TPMS.</div>
                        <?php if (isset($errors['rfid_uid'])): ?><div class="text-danger small mt-1"><?= esc($errors['rfid_uid']) ?></div><?php endif; ?>
                    </div>

                    <div>
                        <label for="newRole" class="form-label">Role</label>
                        <select
                            id="newRole"
                            name="role"
                            class="form-select <?= isset($errors['role']) ? 'is-invalid' : '' ?>"
                            required>
                            <option value="user" <?= old('role', 'user') === 'user' ? 'selected' : '' ?>>
                                User — Operational Access
                            </option>
                            <option value="admin" <?= old('role') === 'admin' ? 'selected' : '' ?>>
                                Admin — Full Access
                            </option>
                        </select>

                        <?php if (isset($errors['role'])): ?>
                            <div class="invalid-feedback">
                                <?= esc($errors['role']) ?>
                            </div>
                        <?php endif; ?>
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
                        <i class="bi bi-plus-lg me-1"></i>
                        Create User
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const search = document.getElementById('userSearch');
    const role = document.getElementById('userRoleFilter');
    const reset = document.getElementById('resetUserFilter');
    const result = document.getElementById('userFilterResult');
    const rows = Array.from(document.querySelectorAll('[data-user-row]'));
    const emptyFilter = document.getElementById('userEmptyFilter');
    const password = document.getElementById('newPassword');
    const passwordToggle = document.getElementById('passwordToggle');

    const applyFilter = () => {
        const keyword = (search?.value || '').trim().toLowerCase();
        const selectedRole = role?.value || '';
        let visible = 0;

        rows.forEach((row) => {
            const username = row.dataset.username || '';
            const rowRole = row.dataset.role || '';

            const matchesSearch = keyword === '' || username.includes(keyword);
            const matchesRole = selectedRole === '' || rowRole === selectedRole;
            const show = matchesSearch && matchesRole;

            row.classList.toggle('d-none', !show);

            if (show) {
                visible++;
            }
        });

        if (emptyFilter) {
            emptyFilter.classList.toggle('d-none', visible !== 0 || rows.length === 0);
        }

        if (result) {
            result.textContent = `Showing ${visible} of ${rows.length} users`;
        }
    };

    search?.addEventListener('input', applyFilter);
    role?.addEventListener('change', applyFilter);

    reset?.addEventListener('click', () => {
        if (search) search.value = '';
        if (role) role.value = '';
        applyFilter();
        search?.focus();
    });

    passwordToggle?.addEventListener('click', () => {
        if (!password) return;

        const showing = password.type === 'text';
        password.type = showing ? 'password' : 'text';

        const icon = passwordToggle.querySelector('i');
        icon?.classList.toggle('bi-eye', showing);
        icon?.classList.toggle('bi-eye-slash', !showing);

        passwordToggle.setAttribute(
            'aria-label',
            showing ? 'Show password' : 'Hide password'
        );
    });

    <?php if (! empty($errors)): ?>
        const addUserModalElement = document.getElementById('addUserModal');

        if (addUserModalElement && window.bootstrap) {
            bootstrap.Modal
                .getOrCreateInstance(addUserModalElement)
                .show();
        }
    <?php endif; ?>
});
</script>

<?= $this->endSection() ?>
