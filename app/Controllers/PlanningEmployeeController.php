<?php

namespace App\Controllers;

use DateTimeImmutable;
use DomainException;
use RuntimeException;
use Throwable;

class PlanningEmployeeController extends BaseController
{
    public function index()
    {
        $month = $this->request->getGet('month');

        // URL lama dengan ?start= tetap diterima, lalu ditampilkan satu bulan.
        if ($month === null) {
            $legacy = $this->request->getGet('start');
            $month = is_string($legacy) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $legacy)
                ? substr($legacy, 0, 7)
                : date('Y-m');
        }

        if (! is_string($month) || ! preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/D', $month)) {
            return redirect()
                ->to(site_url('planning-employees'))
                ->with('error', 'Bulan tidak valid.');
        }

        $first = new DateTimeImmutable($month . '-01');
        $last = $first->format('Y-m-t');
        $db = db_connect();

        $machines = $db
            ->table('machines m')
            ->select('m.id, m.code, m.name, m.maker, ps.slot_no')
            ->join('production_slots ps', 'ps.machine_id = m.id', 'left')
            ->where('m.disposed_at', null)
            ->orderBy('ps.slot_no IS NULL', 'ASC', false)
            ->orderBy('ps.slot_no', 'ASC')
            ->orderBy('m.name', 'ASC')
            ->get()
            ->getResultArray();

        $employees = $db
            ->table('employees')
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        $roleOptions = $this->roleOptions($employees);

        if ($roleOptions === []) {
            $roleOptions = [
                'operator' => [
                    'key' => 'operator',
                    'label' => 'Operator',
                    'count' => 0,
                ],
            ];
        }

        $requestedRole = $this->roleKey((string) ($this->request->getGet('role') ?? 'operator'));

        if (! isset($roleOptions[$requestedRole])) {
            $requestedRole = isset($roleOptions['operator'])
                ? 'operator'
                : array_key_first($roleOptions);
        }

        $currentRole = $requestedRole;
        $currentRoleLabel = $roleOptions[$currentRole]['label'];

        $shifts = $db
            ->table('shifts')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResultArray();

        $rows = $this->assignments(
            $first->format('Y-m-d'),
            $last,
            $currentRole
        );

        $schedule = [];

        foreach ($rows as $row) {
            if ((int) $row['is_active'] !== 1 || (int) $row['effective_machine'] <= 0) {
                continue;
            }

            $key = (int) $row['effective_machine'] . '|' . $row['assignment_date'];
            $schedule[$key][(int) $row['shift_id']] = (int) $row['employee_id'];
        }

        $dates = [];

        for ($day = 1; $day <= (int) $first->format('t'); $day++) {
            $date = $first->setDate(
                (int) $first->format('Y'),
                (int) $first->format('m'),
                $day
            );

            $dates[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $day,
                'weekday' => (int) $date->format('N'),
            ];
        }

        $pageUrl = site_url('planning-employees');

        $planningRoles = [];

        foreach ($roleOptions as $role) {
            $planningRoles[] = [
                'key' => $role['key'],
                'label' => $role['label'],
                'count' => $role['count'],
                'url' => $pageUrl . '?' . http_build_query([
                    'month' => $month,
                    'role' => $role['key'],
                ]),
            ];
        }

        $data = [
            'month' => $month,
            'today' => date('Y-m-d'),
            'currentRole' => $currentRole,
            'currentRoleLabel' => $currentRoleLabel,
            'planningRoles' => $planningRoles,

            'previousUrl' => $pageUrl . '?' . http_build_query([
                'month' => $first->modify('-1 month')->format('Y-m'),
                'role' => $currentRole,
            ]),
            'nextUrl' => $pageUrl . '?' . http_build_query([
                'month' => $first->modify('+1 month')->format('Y-m'),
                'role' => $currentRole,
            ]),
            'pageUrl' => $pageUrl,
            'saveUrl' => site_url('planning-employees/save'),
            'viewer' => (string) (session()->get('user_id') ?? 'user'),
            'canEdit' => session()->get('role') === 'admin',

            'machines' => array_map(static fn (array $machine): array => [
                'id' => (int) $machine['id'],
                'name' => $machine['name'],
                'code' => $machine['code'],
                'maker' => $machine['maker'],
                'slot_no' => $machine['slot_no'] === null ? null : (int) $machine['slot_no'],
            ], $machines),

            // Semua employee tetap dikirim agar assignment lama tetap dapat dilabeli.
            // Picker di browser hanya menampilkan role tab yang sedang aktif.
            'employees' => array_map(fn (array $employee): array => [
                'id' => (int) $employee['id'],
                'name' => $employee['name'],
                'nik' => $employee['nik'],
                'role' => $employee['role'],
                'role_key' => $this->roleKey((string) $employee['role']),
                'status' => $employee['status'],
            ], $employees),

            'shifts' => array_map(static fn (array $shift): array => [
                'id' => (int) $shift['id'],
                'name' => $shift['name'],
                'start_time' => $shift['start_time'],
                'end_time' => $shift['end_time'],
            ], $shifts),

            'dates' => $dates,
            'schedule' => $schedule,
            'csrf' => [
                'name' => csrf_token(),
                'hash' => csrf_hash(),
            ],
        ];

        return view('planning-employees/index', [
            'title' => 'Planning Employee',
            'planningData' => $data,
        ]);
    }

    // Nama method dipertahankan agar route /save sebelumnya tetap bekerja.
    public function saveCell()
    {
        return $this->saveBatch();
    }

    public function saveBatch()
    {
        if (! session()->get('user_id') || session()->get('role') !== 'admin') {
            return $this->reply(false, 'Hanya admin yang dapat menyimpan planning.', 403);
        }

        $db = db_connect();
        $started = false;

        try {
            $planningRole = $this->roleKey((string) $this->request->getPost('planning_role'));

            if ($planningRole === '') {
                throw new DomainException('Role planning tidak valid.');
            }

            $raw = $this->request->getPost('changes');

            if (! is_string($raw) || strlen($raw) > 1000000) {
                throw new DomainException('Payload planning tidak valid.');
            }

            $changes = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);

            if (! is_array($changes) || ! array_is_list($changes) || ! $changes || count($changes) > 1000) {
                throw new DomainException('Simpan 1–1000 sel dalam satu permintaan.');
            }

            if (! $db->transBegin()) {
                throw new RuntimeException('Transaksi tidak dapat dimulai.');
            }

            $started = true;

            // Planning can affect multiple machines, so use a short maintenance
            // barrier across all per-device locks instead of the old global row.
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();

            $shifts = $db
                ->table('shifts')
                ->orderBy('code', 'ASC')
                ->get()
                ->getResultArray();

            if (count($shifts) !== 3) {
                throw new DomainException('Konfigurasikan tepat tiga shift sebelum menyimpan planning.');
            }

            $shiftIds = array_map('intval', array_column($shifts, 'id'));
            $cells = [];
            $month = null;

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    throw new DomainException('Format sel tidak valid.');
                }

                $machineId = $this->id($change['machine_id'] ?? null);
                $date = $this->date($change['date'] ?? null);
                $cellMonth = substr($date, 0, 7);
                $month ??= $cellMonth;

                if ($month !== $cellMonth) {
                    throw new DomainException('Satu penyimpanan hanya untuk satu bulan.');
                }

                $key = $machineId . '|' . $date;

                if (isset($cells[$key])) {
                    throw new DomainException('Sel yang sama dikirim lebih dari sekali.');
                }

                $cells[$key] = [
                    'machine_id' => $machineId,
                    'date' => $date,
                    'expected' => $this->selection($change['expected'] ?? null, $shiftIds),
                    'shifts' => $this->selection($change['shifts'] ?? null, $shiftIds),
                ];
            }

            $first = $month . '-01';
            $last = (new DateTimeImmutable($first))->format('Y-m-t');

            $machineRows = $db
                ->table('machines')
                ->where('disposed_at', null)
                ->get()
                ->getResultArray();

            $machineMap = array_column($machineRows, null, 'id');

            $employeeRows = $db
                ->table('employees')
                ->get()
                ->getResultArray();

            $employeeMap = array_column($employeeRows, null, 'id');
            $knownRoles = $this->roleOptions($employeeRows);

            if (! isset($knownRoles[$planningRole])) {
                throw new DomainException('Role employee untuk planning tidak ditemukan.');
            }

            $slotMap = array_column(
                $db->table('production_slots')->get()->getResultArray(),
                'id',
                'machine_id'
            );

            $rows = $this->assignments($first, $last, $planningRole);
            $current = [];

            foreach ($rows as $row) {
                $key = (int) $row['effective_machine'] . '|' . $row['assignment_date'];

                if ((int) $row['is_active'] === 1 && in_array((int) $row['shift_id'], $shiftIds, true)) {
                    $current[$key][(int) $row['shift_id']] = (int) $row['employee_id'];
                }
            }

            $empty = array_fill_keys($shiftIds, null);
            $writes = [];

            foreach ($cells as $key => $cell) {
                if (! isset($machineMap[$cell['machine_id']])) {
                    throw new DomainException('Mesin tidak ditemukan atau telah dinonaktifkan.');
                }

                $actual = $empty;

                foreach ($shiftIds as $id) {
                    $actual[$id] = $current[$key][$id] ?? null;
                }

                // Retry setelah respons terputus: kondisi tujuan sudah terpenuhi.
                if ($actual === $cell['shifts']) {
                    continue;
                }

                if ($actual !== $cell['expected']) {
                    throw new DomainException(
                        'Jadwal ' . $machineMap[$cell['machine_id']]['name']
                        . ' pada ' . $cell['date']
                        . ' berubah sejak halaman dibuka. Muat ulang dan periksa draft.',
                        409
                    );
                }

                foreach ($cell['shifts'] as $employeeId) {
                    if ($employeeId === null) {
                        continue;
                    }

                    if (! isset($employeeMap[$employeeId]) || $employeeMap[$employeeId]['status'] !== 'active') {
                        throw new DomainException('Ada karyawan tidak aktif/tidak ditemukan. Pilih karyawan aktif.');
                    }

                    $employeeRole = $this->roleKey((string) $employeeMap[$employeeId]['role']);

                    if ($employeeRole !== $planningRole) {
                        throw new DomainException(
                            $employeeMap[$employeeId]['name'] . ' bukan ' . $knownRoles[$planningRole]['label'] . '.'
                        );
                    }
                }

                $writes[$key] = $cell;
            }

            /*
             * Operator dan Unit Head dipakai oleh runtime TPMS sebagai personel shift.
             * PIC adalah technical support global dan tidak memiliki Planning Employee.
             * Karena itu assignment ROLE + SHIFT yang sedang aktif dikunci agar
             * otorisasi yang sedang dipakai perangkat tidak berubah di tengah sesi.
             * Shift lain pada tanggal yang sama tetap boleh disiapkan lebih awal.
             */
            if (in_array($planningRole, ['operator', 'unit_head'], true)) {
                $active = $db
                    ->table('production_shift_details d')
                    ->select('p.machine_id, d.id detail_id, d.work_date, d.shift_id, d.status, d.operator_employee_id')
                    ->join('productions p', 'p.id = d.production_id')
                    ->where('d.work_date >=', $first)
                    ->where('d.work_date <=', $last)
                    ->whereIn('d.status', ['running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting'])
                    ->get()
                    ->getResultArray();

                foreach ($active as $session) {
                    $cellKey = (int) $session['machine_id'] . '|' . $session['work_date'];
                    if (! isset($writes[$cellKey])) {
                        continue;
                    }

                    $shiftId = (int) $session['shift_id'];
                    $beforeEmployee = $current[$cellKey][$shiftId] ?? null;
                    $afterEmployee = $writes[$cellKey]['shifts'][$shiftId] ?? $beforeEmployee;

                    if ($beforeEmployee !== $afterEmployee) {
                        $replacementAllowed = false;
                        if ($planningRole === 'operator' && ($session['status'] ?? null) === 'operator_change_required') {
                            $latestSick = $db->table('production_alarms')
                                ->where('production_shift_detail_id', (int) $session['detail_id'])
                                ->where('kind', 'operator_sick')->where('effect', 'operator_stop')
                                ->orderBy('id', 'DESC')->get()->getRowArray();
                            $replacementAllowed = (bool) $latestSick && ! empty($latestSick['resolved_at']);
                        }
                        if (! $replacementAllowed) {
                            $message = $planningRole === 'operator'
                                ? 'Planning Operator untuk machine %s tanggal %s shift ID %d sedang dipakai production aktif. Jalankan Operator Sakit dan resolve RFID PIC sebelum mengganti Operator.'
                                : 'Planning %s untuk machine %s tanggal %s shift ID %d sedang dipakai production aktif dan tidak boleh diubah.';

                            if ($planningRole === 'operator') {
                                throw new DomainException(sprintf(
                                    $message,
                                    $machineMap[(int) $session['machine_id']]['name'] ?? ('ID ' . $session['machine_id']),
                                    $session['work_date'],
                                    $shiftId
                                ), 409);
                            }

                            throw new DomainException(sprintf(
                                $message,
                                $knownRoles[$planningRole]['label'],
                                $machineMap[(int) $session['machine_id']]['name'] ?? ('ID ' . $session['machine_id']),
                                $session['work_date'],
                                $shiftId
                            ), 409);
                        }

                        if ($planningRole === 'operator') {
                            $replacementEmployeeId = (int) ($afterEmployee ?? 0);
                            if ($replacementEmployeeId <= 0) {
                                throw new DomainException('Operator pengganti wajib dipilih. Planning Operator aktif tidak boleh dikosongkan saat prosedur replacement.', 409);
                            }
                            if ($replacementEmployeeId === (int) ($session['operator_employee_id'] ?? 0)) {
                                throw new DomainException('Operator pengganti harus berbeda dari Operator yang dihentikan karena sakit/berhalangan.', 409);
                            }
                        }
                    }
                }
            }

            /*
             * Employee yang sama tidak boleh berada pada dua machine di tanggal + shift
             * yang sama. Query hanya role aktif karena employee memiliki satu role master.
             */
            $occupied = [];

            foreach ($rows as $row) {
                $key = (int) $row['effective_machine'] . '|' . $row['assignment_date'];

                if (isset($writes[$key])) {
                    continue;
                }

                $occupied[
                    $row['assignment_date'] . '|'
                    . $row['shift_id'] . '|'
                    . $row['employee_id']
                ] = (int) $row['effective_machine'];
            }

            foreach ($writes as $cell) {
                foreach ($cell['shifts'] as $shiftId => $employeeId) {
                    if ($employeeId === null) {
                        continue;
                    }

                    $key = $cell['date'] . '|' . $shiftId . '|' . $employeeId;

                    if (array_key_exists($key, $occupied)) {
                        $name = $employeeMap[$employeeId]['name'];

                        throw new DomainException(
                            "{$name} sudah dijadwalkan pada mesin lain tanggal {$cell['date']}, shift ID {$shiftId}. Tidak ada perubahan disimpan.",
                            409
                        );
                    }

                    $occupied[$key] = $cell['machine_id'];
                }
            }

            // Hapus assignment hanya untuk role/tab yang sedang diedit.
            foreach ($rows as $row) {
                if (isset($writes[(int) $row['effective_machine'] . '|' . $row['assignment_date']])) {
                    $db
                        ->table('employee_shift_assignments')
                        ->where('id', $row['id'])
                        ->delete();
                }
            }

            $now = date('Y-m-d H:i:s');

            foreach ($writes as $cell) {
                foreach ($cell['shifts'] as $shiftId => $employeeId) {
                    if ($employeeId === null) {
                        continue;
                    }

                    $db->table('employee_shift_assignments')->insert([
                        'employee_id' => $employeeId,
                        'shift_id' => $shiftId,
                        'assignment_role' => $planningRole,
                        'machine_id' => $cell['machine_id'],
                        'slot_id' => $slotMap[$cell['machine_id']] ?? null,
                        'assignment_date' => $cell['date'],
                        'is_active' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('Transaksi gagal disimpan.');
            }

            $started = false;
            $saved = [];

            foreach ($cells as $key => $cell) {
                $saved[$key] = $cell['shifts'];
            }

            return $this->reply(
                true,
                count($cells) . ' sel ' . $knownRoles[$planningRole]['label'] . ' berhasil disimpan.',
                200,
                [
                    'saved' => $saved,
                    'planning_role' => $planningRole,
                ]
            );
        } catch (Throwable $e) {
            if ($started) {
                $db->transRollback();
            }

            if ($e instanceof DomainException || $e instanceof \JsonException) {
                return $this->reply(
                    false,
                    $e instanceof \JsonException ? 'Format JSON tidak valid.' : $e->getMessage(),
                    $e->getCode() === 409 ? 409 : 422
                );
            }

            log_message('error', 'Planning batch: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->reply(
                false,
                'Penyimpanan gagal. Draft tetap ada; periksa log lalu coba Save All kembali.',
                500
            );
        }
    }

    private function assignments(string $start, string $end, string $role): array
    {
        $builder = db_connect()
            ->table('employee_shift_assignments a')
            ->select('a.*, COALESCE(a.machine_id, ps.machine_id) effective_machine', false)
            ->join('production_slots ps', 'ps.id = a.slot_id', 'left')
            ->where('a.assignment_date >=', $start)
            ->where('a.assignment_date <=', $end);

        // Kompatibilitas data lama: Kanit pernah tersimpan sebagai kanit / unit_head.
        if ($role === 'unit_head') {
            $builder->whereIn('a.assignment_role', ['unit_head', 'kanit']);
        } else {
            $builder->where('a.assignment_role', $role);
        }

        return $builder
            ->orderBy('a.id', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function roleOptions(array $employees): array
    {
        // Planning produksi hanya memakai role yang memang terlibat di lantai produksi.
        // Kanit menggunakan key runtime `unit_head` agar sinkron dengan
        // production_shift_details.unit_head_employee_id dan ESP production service.
        $roles = [
            'operator' => [
                'key' => 'operator',
                'label' => 'Operator',
                'count' => 0,
            ],
            'unit_head' => [
                'key' => 'unit_head',
                'label' => 'Kanit',
                'count' => 0,
            ],
        ];

        foreach ($employees as $employee) {
            $key = $this->roleKey((string) ($employee['role'] ?? ''));

            if (! isset($roles[$key])) {
                continue;
            }

            if (($employee['status'] ?? null) === 'active') {
                $roles[$key]['count']++;
            }
        }

        return $roles;
    }

    private function roleKey(string $role): string
    {
        $role = trim(strtolower($role));
        $role = preg_replace('/[^a-z0-9]+/u', '_', $role) ?? '';
        $role = trim($role, '_');

        return match ($role) {
            'kanit',
            'unit_head',
            'unithead',
            'kepala_unit' => 'unit_head',
            'operator' => 'operator',
            'pic' => 'pic',
            default => $role,
        };
    }

    private function roleLabel(string $role): string
    {
        return match ($this->roleKey($role)) {
            'operator' => 'Operator',
            'pic' => 'PIC',
            'unit_head' => 'Kanit',
            default => ucwords(str_replace('_', ' ', $role)),
        };
    }

    private function selection($input, array $shiftIds): array
    {
        if (! is_array($input) || count($input) !== count($shiftIds)) {
            throw new DomainException('Pilihan tiga shift tidak lengkap.');
        }

        $result = [];

        foreach ($shiftIds as $id) {
            if (! array_key_exists($id, $input)) {
                throw new DomainException('ID shift tidak sesuai master.');
            }

            $result[$id] = $input[$id] === null || $input[$id] === ''
                ? null
                : $this->id($input[$id]);
        }

        return $result;
    }

    private function id($value): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
            throw new DomainException('ID harus bilangan bulat positif.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
            ],
        ]);

        if ($id === false) {
            throw new DomainException('ID di luar batas.');
        }

        return $id;
    }

    private function date($value): string
    {
        if (! is_string($value)) {
            throw new DomainException('Tanggal tidak valid.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (! $date || $date->format('Y-m-d') !== $value || ! preg_match('/^20\d{2}-/', $value)) {
            throw new DomainException('Tanggal tidak valid.');
        }

        return $value;
    }

    private function reply(bool $ok, string $message, int $status, array $data = [])
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'ok' => $ok,
                'message' => $message,
                'csrf' => [
                    'name' => csrf_token(),
                    'hash' => csrf_hash(),
                ],
            ] + $data);
    }
}
