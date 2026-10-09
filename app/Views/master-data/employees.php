<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>

<?php
$employeeId = (int) ($employeeId ?? 0);
$employees = $employees ?? [];
$schedules = $schedules ?? [];
$attendanceByEmployee = $attendanceByEmployee ?? [];
$attendanceStatsByEmployee = $attendanceStatsByEmployee ?? [];
$start = $start ?? date('Y-m-01');
$end = $end ?? date('Y-m-t');
$activeCount = (int) ($employeeStats['active'] ?? 0);
$rfidCount = (int) ($employeeStats['rfid'] ?? 0);
?>

<style>
    .employee-attendance-summary {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 18px;
    }

    .employee-attendance-card {
        padding: 14px 16px;
        border: 1px solid var(--bs-border-color);
        border-radius: 14px;
        background: var(--bs-body-bg);
    }

    .employee-attendance-card small {
        display: block;
        margin-bottom: 6px;
        color: var(--bs-secondary-color);
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .employee-attendance-card strong {
        display: block;
        font-size: 24px;
        line-height: 1;
        font-weight: 800;
    }

    .attendance-section-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin: 24px 0 12px;
    }

    .attendance-section-title h6 {
        margin: 0;
        font-weight: 800;
    }

    .attendance-section-title small {
        color: var(--bs-secondary-color);
    }

    .attendance-table {
        margin: 0;
        min-width: 920px;
    }

    .attendance-table th {
        white-space: nowrap;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    .attendance-table td {
        vertical-align: middle;
    }

    .attendance-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .attendance-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 7px;
        border-radius: 7px;
        background: var(--bs-tertiary-bg);
        color: var(--bs-body-color);
        font-size: 11px;
        font-weight: 700;
    }

    .attendance-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 800;
        white-space: nowrap;
    }

    .attendance-status.present {
        background: rgba(25, 135, 84, .12);
        color: #198754;
    }

    .attendance-status.absent {
        background: rgba(220, 53, 69, .12);
        color: #dc3545;
    }

    .attendance-status.scheduled {
        background: rgba(13, 110, 253, .12);
        color: #0d6efd;
    }

    .attendance-status.pending {
        background: rgba(255, 193, 7, .16);
        color: #a06b00;
    }

    .attendance-day-table {
        margin: 0;
        min-width: 980px;
    }

    .attendance-day-table>tbody>tr>td {
        vertical-align: top;
    }

    .attendance-date-cell {
        width: 150px;
        background: var(--bs-tertiary-bg);
    }

    .shift-attendance-list {
        display: grid;
        gap: 10px;
        min-width: 760px;
    }

    .shift-attendance-row {
        display: grid;
        grid-template-columns: 130px minmax(150px, .9fr) minmax(190px, 1.2fr) 150px 125px;
        gap: 12px;
        align-items: center;
        padding: 12px 14px;
        border: 1px solid var(--bs-border-color);
        border-radius: 12px;
        background: var(--bs-body-bg);
    }

    .shift-attendance-row.present {
        border-left: 4px solid #198754;
    }

    .shift-attendance-row.absent {
        border-left: 4px solid #dc3545;
    }

    .shift-attendance-row.pending {
        border-left: 4px solid #ffc107;
    }

    .shift-attendance-row.scheduled {
        border-left: 4px solid #0d6efd;
    }

    .shift-attendance-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--bs-secondary-color);
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-bottom: 3px;
    }

    .shift-attendance-value {
        font-size: 13px;
        font-weight: 700;
    }

    .shift-attendance-production {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 4px;
    }

    @media (max-width: 1399.98px) {
        .employee-attendance-summary {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 991.98px) {
        .employee-attendance-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
</style>


<div class="page-heading">
    <div>
        <h1>Employee <span>Management</span></h1>
        <div class="page-subtitle"><?= (int) ($employeeStats['total'] ?? count($employees)) ?> Employees · <?= $activeCount ?> Active · <?= $rfidCount ?> RFID Registered</div>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= esc(site_url('planning-employees'), 'attr') ?>">
            <i class="bi bi-calendar3 me-2"></i>Planning Employee
        </a>
        <?php if (session()->get('role') === 'admin'): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#employeeNew">
                <i class="bi bi-plus-lg me-2"></i>Add Employee
            </button>
        <?php endif; ?>
    </div>
</div>
<?= view('components/master-transfer', ['transfers' => [['resource' => 'employees', 'label' => 'Employees', 'import' => true, 'template' => 'employees_import.xlsx']]]) ?>


<div class="stat-grid cols-3 mt-3">
    <div class="stat-card blue">
        <div class="stat-label">Total Employees</div>
        <div class="stat-value"><?= (int) ($employeeStats['total'] ?? count($employees)) ?></div>
        <div class="stat-icon text-primary"><i class="bi bi-people"></i></div>
    </div>
    <div class="stat-card purple">
        <div class="stat-label">Active Employees</div>
        <div class="stat-value text-primary"><?= $activeCount ?></div>
        <div class="stat-icon text-primary"><i class="bi bi-person-check"></i></div>
    </div>
    <div class="stat-card green">
        <div class="stat-label">RFID Registered</div>
        <div class="stat-value text-success"><?= $rfidCount ?></div>
        <div class="stat-icon text-success"><i class="bi bi-credit-card-2-front"></i></div>
    </div>
</div>

<form class="panel filter-panel" method="get" action="<?= current_url() ?>">
<input type="hidden" name="sort" value="<?= esc((string) (service('request')->getGet('sort') ?? ''), 'attr') ?>">
<input type="hidden" name="dir" value="<?= esc((string) (service('request')->getGet('dir') ?? ''), 'attr') ?>">
<input type="hidden" name="per_page" value="<?= (int) ($pagination['per_page'] ?? 10) ?>">

    <label for="employeeSearch" class="filter-label">Search Name / NIK / RFID</label>
    <input type="search" id="employeeSearch" class="form-control" placeholder="Name, NIK, or RFID UID..."  name="q" value="<?= esc((string) (service('request')->getGet('q') ?? ''), 'attr') ?>">
<div class="mt-2 d-flex gap-2"><button class="btn btn-primary" type="submit"><i class="bi bi-search me-1"></i>Cari</button> <a class="btn btn-light border" href="<?= current_url() ?>">Reset</a></div>
</form>

<div class="panel table-card mb-4">
    <div class="table-responsive">
        <table class="table" id="employeeTable">
            <thead>
                <tr>
                    <?= view('components/table-sort-th', ['key' => 'employee', 'label' => 'Employee', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'nik', 'label' => 'NIK', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'department', 'label' => 'Department', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'role', 'label' => 'Role', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'rfid', 'label' => 'RFID UID', 'pagination' => $pagination]) ?>
                    <?= view('components/table-sort-th', ['key' => 'status', 'label' => 'Status', 'pagination' => $pagination]) ?>
                    <th scope="col">Actions</th>
</tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $isActive = ($employee['status'] ?? '') === 'active'; ?>
                    <tr>
                        <td><strong><?= esc($employee['name'] ?? '-') ?></strong></td>
                        <td class="mono"><?= esc($employee['nik'] ?? '-') ?></td>
                        <td><?= esc($employee['department'] ?? '-') ?></td>
                        <td>
                            <?php
                            $role = strtolower(trim($employee['role'] ?? ''));
                            ?>

                            <?php if ($role === 'operator'): ?>

                                <span class="badge-soft success text-uppercase">
                                    Operator
                                </span>

                            <?php elseif (in_array($role, ['unit head', 'unit_head', 'kanit'], true)): ?>

                                <span class="badge-soft purple text-uppercase">
                                    Kanit
                                </span>

                            <?php else: ?>

                                <span class="badge-soft muted text-uppercase">
                                    <?= esc($employee['role'] ?? 'Unknown') ?>
                                </span>

                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($employee['rfid_uid'])): ?>
                                <span class="mono"><?= esc($employee['rfid_uid']) ?></span>
                            <?php else: ?>
                                <span class="text-secondary">Belum didaftarkan</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge-soft <?= $isActive ? 'success' : 'muted' ?>">
                                <?= esc($employee['status'] ?? '-') ?>
                            </span>
                        </td>
                        <td class="text-nowrap">
                            <button type="button" class="action-icon" data-bs-toggle="modal" data-bs-target="#employee<?= (int) $employee['id'] ?>Detail" aria-label="View employee detail">
                                <i class="bi bi-eye"></i>
                            </button>
                            <?php if (session()->get('role') === 'admin'): ?>
                                <button
                                    type="button"
                                    class="action-icon"
                                    data-bs-toggle="modal"
                                    data-bs-target="#employee<?= (int) $employee['id'] ?>"
                                    aria-label="Edit data <?= esc($employee['name'] ?? 'karyawan', 'attr') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-secondary py-4">Belum ada data karyawan.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?= view('components/table-pagination', ['pagination' => $pagination]) ?>

</div>


<?php foreach (array_merge([['id' => null]], $employees) as $employee): ?>
    <?php
    $isNew = $employee['id'] === null;
    $key = $isNew ? 'New' : (string) (int) $employee['id'];
    $employeeAttendance = $isNew ? [] : ($attendanceByEmployee[(int) $employee['id']] ?? []);
    $emptyAttendanceStats = [
        'planned_days' => 0,
        'planned_shifts' => 0,
        'present_shifts' => 0,
        'absent_shifts' => 0,
        'scheduled_shifts' => 0,
        'pending_shifts' => 0,
        'attendance_rate' => 0,
    ];
    $attendanceStats = $isNew
        ? $emptyAttendanceStats
        : ($attendanceStatsByEmployee[(int) $employee['id']] ?? $emptyAttendanceStats);
    $action = $isNew
        ? site_url('master-data/employees')
        : site_url('master-data/employees/' . $key . '/update');
    $fields = [
        'nik' => 'NIK',
        'name' => 'Nama',
        'department' => 'Departemen',
        'rfid_uid' => 'RFID UID',
    ];
    ?>
    <?php if (!$isNew): ?>
        <div class="modal fade" id="employee<?= $key ?>Detail" tabindex="-1" aria-labelledby="employee<?= $key ?>DetailLabel" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="employee<?= $key ?>DetailLabel">
                                Employee Detail · <?= esc($employee['name'] ?? '') ?>
                            </h5>
                            <small class="text-secondary">
                                Attendance <?= esc(date('d M Y', strtotime($start))) ?> – <?= esc(date('d M Y', strtotime($end))) ?>
                            </small>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <div class="detail-list">
                            <?php foreach (['nik' => 'NIK', 'name' => 'Name', 'department' => 'Department', 'role' => 'Role', 'rfid_uid' => 'RFID UID', 'status' => 'Status'] as $field => $label): ?>
                                <div class="detail-item">
                                    <small><?= esc($label) ?></small>
                                    <strong><?= esc(($employee[$field] ?? '') ?: '-') ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($employee['role'] == "operator") : ?>
                            <div class="attendance-section-title">
                                <div>
                                    <h6><i class="bi bi-calendar-check me-2 text-primary"></i>Attendance</h6>
                                    <small>Planning Employee dibandingkan dengan operator pada Production Shift Detail.</small>
                                </div>
                            </div>

                            <div class="employee-attendance-summary">
                                <div class="employee-attendance-card">
                                    <small>Hari Terjadwal</small>
                                    <strong><?= (int) $attendanceStats['planned_days'] ?></strong>
                                </div>

                                <div class="employee-attendance-card">
                                    <small>Shift Terjadwal</small>
                                    <strong><?= (int) $attendanceStats['planned_shifts'] ?></strong>
                                </div>

                                <div class="employee-attendance-card">
                                    <small>Shift Masuk</small>
                                    <strong class="text-success"><?= (int) $attendanceStats['present_shifts'] ?></strong>
                                </div>

                                <div class="employee-attendance-card">
                                    <small>Shift Tidak Masuk</small>
                                    <strong class="text-danger"><?= (int) $attendanceStats['absent_shifts'] ?></strong>
                                </div>

                                <div class="employee-attendance-card">
                                    <small>Kehadiran Shift</small>
                                    <strong class="text-primary"><?= number_format((float) $attendanceStats['attendance_rate'], 1, ',', '.') ?>%</strong>
                                </div>
                            </div>


                            <div class="table-responsive border rounded-3">
                                <table class="table attendance-day-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Attendance per Shift</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($employeeAttendance): ?>
                                            <?php foreach ($employeeAttendance as $attendance): ?>
                                                <tr>
                                                    <td class="attendance-date-cell text-nowrap">
                                                        <strong><?= esc(date('d M Y', strtotime($attendance['date']))) ?></strong>
                                                        <div class="text-secondary small"><?= esc(date('l', strtotime($attendance['date']))) ?></div>
                                                        <div class="text-secondary small mt-2">
                                                            <?= count($attendance['shifts']) ?> planned shift<?= count($attendance['shifts']) === 1 ? '' : 's' ?>
                                                        </div>
                                                    </td>

                                                    <td>
                                                        <div class="shift-attendance-list">
                                                            <?php foreach ($attendance['shifts'] as $shiftAttendance): ?>
                                                                <?php
                                                                $statusIcon = match ($shiftAttendance['status_code']) {
                                                                    'present' => 'bi-check-circle-fill',
                                                                    'absent' => 'bi-x-circle-fill',
                                                                    'scheduled' => 'bi-calendar-event',
                                                                    default => 'bi-clock-fill',
                                                                };
                                                                ?>
                                                                <div class="shift-attendance-row <?= esc($shiftAttendance['status_code'], 'attr') ?>">
                                                                    <div>
                                                                        <div class="shift-attendance-label">Shift</div>
                                                                        <div class="shift-attendance-value"><?= esc($shiftAttendance['shift_name'] ?: '-') ?></div>
                                                                        <div class="text-secondary small"><?= esc(in_array(($shiftAttendance['assignment_role'] ?? 'operator'), ['unit_head', 'kanit'], true) ? 'Kanit' : ucwords(str_replace('_', ' ', $shiftAttendance['assignment_role'] ?? 'operator'))) ?></div>
                                                                        <?php if ($shiftAttendance['planned_hours']): ?>
                                                                            <div class="text-secondary small mono mt-1">
                                                                                <?= esc(implode(', ', $shiftAttendance['planned_hours'])) ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>

                                                                    <div>
                                                                        <div class="shift-attendance-label">Planning</div>
                                                                        <div class="shift-attendance-value">
                                                                            <?= esc($shiftAttendance['planned_machines'] ? implode(', ', $shiftAttendance['planned_machines']) : '-') ?>
                                                                        </div>
                                                                    </div>

                                                                    <div>
                                                                        <div class="shift-attendance-label">Actual Production</div>
                                                                        <?php if ($shiftAttendance['production_codes']): ?>
                                                                            <div class="shift-attendance-value">
                                                                                <?= esc($shiftAttendance['actual_machines'] ? implode(', ', $shiftAttendance['actual_machines']) : '-') ?>
                                                                            </div>
                                                                            <div class="shift-attendance-production">
                                                                                <?php foreach ($shiftAttendance['production_codes'] as $productionCode): ?>
                                                                                    <span class="attendance-chip mono">
                                                                                        <i class="bi bi-gear"></i><?= esc($productionCode) ?>
                                                                                    </span>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <span class="text-secondary small">No production</span>
                                                                        <?php endif; ?>
                                                                    </div>

                                                                    <div>
                                                                        <div class="shift-attendance-label">Output</div>
                                                                        <?php if ($shiftAttendance['status_code'] === 'present'): ?>
                                                                            <div class="shift-attendance-value">
                                                                                Gross <?= number_format((int) $shiftAttendance['actual_qty'], 0, ',', '.') ?> pcs
                                                                            </div>
                                                                            <div class="text-secondary small">
                                                                                Good <?= number_format((int) $shiftAttendance['good_qty'], 0, ',', '.') ?>
                                                                                · NC <?= number_format((int) $shiftAttendance['reject_qty'], 0, ',', '.') ?>
                                                                            </div>
                                                                        <?php else: ?>
                                                                            <span class="text-secondary">—</span>
                                                                        <?php endif; ?>
                                                                    </div>

                                                                    <div>
                                                                        <div class="shift-attendance-label">Status</div>
                                                                        <span class="attendance-status <?= esc($shiftAttendance['status_code'], 'attr') ?>">
                                                                            <i class="bi <?= esc($statusIcon, 'attr') ?>"></i>
                                                                            <?= esc($shiftAttendance['status_label']) ?>
                                                                        </span>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="2" class="text-center text-secondary py-5">
                                                    Tidak ada planning employee pada periode ini.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="alert alert-light border mt-3 mb-0 small text-secondary">
                                <i class="bi bi-info-circle me-2"></i>
                                Absensi dikelompokkan <strong>per hari</strong>, tetapi status dihitung <strong>per shift</strong>. Status <strong>Masuk</strong> diberikan hanya jika employee yang sama ditemukan pada Production Shift Detail dengan <strong>tanggal dan shift yang sama</strong> seperti Planning Employee.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="employee<?= $key ?>" tabindex="-1" aria-labelledby="employee<?= $key ?>Title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="<?= esc($action, 'attr') ?>">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h2 class="modal-title fs-5" id="employee<?= $key ?>Title">
                            <?= $isNew ? 'Tambah Employee' : 'Edit Employee' ?>
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
                    </div>
                    <div class="modal-body">
                        <?php foreach ($fields as $field => $label): ?>
                            <?php $inputId = 'employee' . $key . ucfirst($field); ?>
                            <div class="mb-3">
                                <label class="form-label" for="<?= esc($inputId, 'attr') ?>"><?= esc($label) ?></label>
                                <?php if ($field === 'rfid_uid'): ?>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="<?= esc($inputId, 'attr') ?>" name="rfid_uid" value="<?= esc($employee[$field] ?? '', 'attr') ?>" placeholder="A1B2C3D4">
                                        <button type="button" class="btn btn-outline-primary" data-rfid-read data-rfid-purpose="employee" data-rfid-target="#<?= esc($inputId, 'attr') ?>" data-rfid-info="#<?= esc($inputId . 'Info', 'attr') ?>"><i class="bi bi-broadcast-pin me-1"></i>Baca RFID</button>
                                    </div>
                                    <div class="form-text" id="<?= esc($inputId . 'Info', 'attr') ?>">Scan Employee pada TPMS/ESP32.</div>
                                <?php else: ?>
                                    <input type="text" class="form-control" id="<?= esc($inputId, 'attr') ?>" name="<?= esc($field, 'attr') ?>" value="<?= esc($employee[$field] ?? '', 'attr') ?>" required>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="employee<?= $key ?>Role">
                                Role
                            </label>

                            <?php
                            $currentRole = strtolower(
                                trim(
                                    str_replace(
                                        [' ', '-'],
                                        '_',
                                        (string) ($employee['role'] ?? 'operator')
                                    )
                                )
                            );
                            ?>

                            <select
                                class="form-select"
                                name="role"
                                id="employee<?= $key ?>Role"
                                required>
                                <option
                                    value="Operator"
                                    <?= $currentRole === 'operator' ? 'selected' : '' ?>>
                                    Operator
                                </option>

                                <option
                                    value="Kanit"
                                    <?= in_array($currentRole, ['kanit', 'unit_head'], true) ? 'selected' : '' ?>>
                                    Kanit
                                </option>
                                <option value="PIC" <?= $currentRole === 'pic' ? 'selected' : '' ?>>PIC</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="employee<?= $key ?>Status">Status</label>
                            <select class="form-select" name="status" id="employee<?= $key ?>Status">
                                <option value="active" <?= ($employee['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Aktif</option>
                                <option value="inactive" <?= ($employee['status'] ?? 'active') === 'inactive' ? 'selected' : '' ?>>Tidak Aktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Employee</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?= $this->endSection() ?>