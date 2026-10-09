<?php



namespace App\Services;



use DateTimeImmutable;

use DateTimeZone;

use DomainException;

use RuntimeException;

use Throwable;



final class EspProductionService

{

    private const TOOL_WARNING_REMAINING = 5;

    private const TOOL_LAST_REMAINING = 1;

    private const TOOL_CRITICAL_REMAINING = 0;

    private $db;

    private array $device;



    public function __construct()

    {

        $this->db = db_connect();

    }



    /**

     * Handle semua request dari ESP Production.

     *

     * Authentication:

     * - mac_address = identitas device

     * - X-TPMS-Key  = tpms_devices.token

     *

     * TPMS_API_KEY dari .env TIDAK digunakan di sini.

     * TPMS_API_KEY hanya digunakan saat registrasi perangkat.

     */

    public function handle(
        string $action,
        array $input,
        string $token
    ): array {
        $mac = strtoupper(
            ProductionRules::text(
                $input['mac_address'] ?? null,
                'MAC',
                17
            )
        );

        if (! preg_match('/^(?:[A-F0-9]{2}:){5}[A-F0-9]{2}$/D', $mac)) {
            throw new DomainException('MAC tidak valid.', 422);
        }

        $token = trim($token);
        if ($token === '') {
            throw new DomainException('Token perangkat tidak dikirim.', 401);
        }

        /*
         * Phase 1 concurrency rule:
         * - read actions do not take a production runtime lock;
         * - write actions are serialized only per TPMS device.
         *
         * Authentication is intentionally done before starting the writer
         * transaction so an invalid device/token never occupies a runtime lock.
         */
        $device = $this->db
            ->table('tpms_devices')
            ->where('mac_address', $mac)
            ->get()
            ->getRowArray();

        if (! $device) {
            throw new DomainException('Perangkat TPMS belum terdaftar.', 401);
        }

        $this->assertDeviceToken($device, $token);
        $this->device = $device;

        $write = $this->isWriteAction($action);
        $transactionStarted = false;

        try {
            if ($write) {
                if (! $this->db->transBegin()) {
                    throw new RuntimeException('Transaksi database tidak dapat dimulai.');
                }
                $transactionStarted = true;

                /*
                 * The old implementation locked tpms_runtime_lock.id=1, which
                 * serialized every TPMS in the plant. Locking by device keeps
                 * same-device counter/idempotency writes safe while allowing
                 * different machines to execute concurrently.
                 */
                (new TpmsRuntimeLockService($this->db))->lockDevice((int) $device['id']);

                /*
                 * Re-read after acquiring the device lock. A registration or
                 * master-data operation may have changed token/slot while this
                 * request was waiting, so production must use the locked state.
                 */
                $device = $this->db
                    ->table('tpms_devices')
                    ->where('id', (int) $device['id'])
                    ->get()
                    ->getRowArray();

                if (! $device || strtoupper((string) ($device['mac_address'] ?? '')) !== $mac) {
                    throw new DomainException('Perangkat TPMS tidak lagi tersedia.', 401);
                }

                $this->assertDeviceToken($device, $token);
                $this->device = $device;
            }

            /*
             * Phase 3: production polling is allowed to be read-heavy. A normal
             * /state request no longer writes tpms_devices on every poll. We only
             * refresh liveness when the configured interval has elapsed or the
             * connection was previously marked unreachable.
             */
            $this->touchDeviceConnectivityIfDue();

            $event = null;
            $hash = null;

            if ($write) {
                $event = ProductionRules::text(
                    $input['event_id'] ?? null,
                    'event_id',
                    80
                );

                if (! preg_match('/^[a-zA-Z0-9_.:-]+$/D', $event)) {
                    throw new DomainException(
                        'event_id hanya boleh huruf, angka, titik, underscore, titik dua, atau strip.'
                    );
                }

                $hash = hash(
                    'sha256',
                    $action . json_encode($this->canonical($input), JSON_THROW_ON_ERROR)
                );

                $old = $this->db
                    ->table('production_api_events')
                    ->where('device_id', $this->device['id'])
                    ->where('event_id', $event)
                    ->get()
                    ->getRowArray();

                if ($old) {
                    if (! hash_equals($old['request_hash'], $hash)) {
                        throw new DomainException(
                            'event_id sudah dipakai dengan payload berbeda.',
                            409
                        );
                    }

                    $storedResult = json_decode(
                        $old['response_json'],
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
                    $result = $storedResult;

                    /*
                     * Preserve action-specific metadata while rebuilding current
                     * production state for a safe idempotent replay.
                     */
                    if (! empty($storedResult['production_id'])) {
                        $result = $this->state((int) $storedResult['production_id']);
                        foreach (['cycle', 'pause_alarm_id'] as $extraKey) {
                            if (array_key_exists($extraKey, $storedResult)) {
                                $result[$extraKey] = $storedResult[$extraKey];
                            }
                        }
                    }

                    $result['replayed'] = true;
                    $this->commit();
                    $transactionStarted = false;

                    return $result;
                }
            }

            switch ($action) {
                case 'context':
                    $result = $this->context();
                    break;

                case 'parts':
                    $result = $this->catalogue($input);
                    break;

                case 'pic':
                    // PIC adalah technical support global: tidak terikat Planning/shift/machine.
                    $pic = $this->pic($input['pic_uid'] ?? null);
                    $result = [
                        'id' => (int) $pic['id'],
                        'nik' => $pic['nik'],
                        'name' => $pic['name'],
                        'role' => 'PIC',
                    ];
                    break;

                case 'operator':
                    $machine = $this->machine();
                    $shift = ProductionRules::shift(
                        $this->db->table('shifts')->get()->getResultArray(),
                        $this->clock()
                    );
                    $operator = $this->operator(
                        $input['operator_uid'] ?? null,
                        (int) $machine['machine_id'],
                        $shift
                    );
                    $result = [
                        'id' => (int) $operator['id'],
                        'nik' => $operator['nik'],
                        'name' => $operator['name'],
                        'role' => 'Operator',
                    ];
                    break;

                case 'setting-start':
                    $result = $this->beginSetting($input);
                    break;

                case 'setting-configure':
                    $result = $this->configureSetting($input);
                    break;

                case 'setting-finish':
                    $result = $this->finishSetting($input);
                    break;

                case 'change-edge':
                    $result = $this->deviceChangeEdge($input);
                    break;

                case 'reset-tool':
                    $result = $this->deviceResetTool($input);
                    break;

                case 'start':
                    $result = $this->start($input);
                    break;

                case 'cycle-start':
                    $result = $this->cycleStart($input);
                    break;

                case 'cycle-stop':
                    $result = $this->cycleStop($input);
                    break;

                case 'count':
                case 'pause':
                case 'alarm':
                case 'resume':
                case 'service-complete':
                case 'stop':
                case 'finish':
                    $result = $this->change($action, $input);
                    break;

                case 'operator-sick':
                    $result = $this->operatorSick($input);
                    break;

                case 'operator-sick-resolve':
                    $result = $this->resolveOperatorSick($input);
                    break;

                case 'operator-replacement':
                    $result = $this->activateReplacementOperator($input);
                    break;

                case 'state':
                    $result = $this->stateForDevice($input);
                    break;

                default:
                    throw new DomainException('Operasi tidak dikenal.', 404);
            }

            if ($write) {
                $this->db
                    ->table('production_api_events')
                    ->insert([
                        'device_id' => $this->device['id'],
                        'event_id' => $event,
                        'request_hash' => $hash,
                        'response_json' => json_encode($this->idempotencySnapshot($result), JSON_THROW_ON_ERROR),
                        'created_at' => $this->now(),
                    ]);

                $this->commit();
                $transactionStarted = false;
            }

            return $result;
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $this->db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Validate the per-device production token.
     */
    private function assertDeviceToken(array $device, string $token): void
    {
        $storedToken = trim((string) ($device['token'] ?? ''));

        if ($storedToken === '' || ! hash_equals($storedToken, $token)) {
            throw new DomainException('Token perangkat tidak valid.', 401);
        }
    }

    /**
     * Only actions that can mutate production state require a device lock and
     * an idempotency event_id.
     */
    private function isWriteAction(string $action): bool
    {
        return in_array(
            $action,
            [
                'start',
                'setting-start',
                'setting-configure',
                'setting-finish',
                'change-edge',
                'reset-tool',
                'count',
                'cycle-start',
                'cycle-stop',
                'pause',
                'alarm',
                'resume',
                'service-complete',
                'operator-sick',
                'operator-sick-resolve',
                'operator-replacement',
                'stop',
                'finish',
            ],
            true
        );
    }

    /**

     * Commit transaction.

     */

    private function commit(): void

    {

        if (

            ! $this->db->transStatus()

            || ! $this->db->transCommit()

        ) {

            throw new RuntimeException(

                'Transaksi database gagal.'

            );

        }

    }



    /**
     * Phase 6: idempotency table is a retry cache, not the production audit log.
     * Most write responses already contain production_id and replay logic rebuilds
     * the current state. Store only the identifiers/metadata required to replay.
     * Small non-production responses (for example change-edge) are kept intact.
     */
    private function idempotencySnapshot(array $result): array
    {
        if (empty($result['production_id'])) {
            return $result;
        }

        $snapshot = [
            'production_id' => (int) $result['production_id'],
        ];

        foreach (['cycle', 'pause_alarm_id'] as $key) {
            if (array_key_exists($key, $result)) {
                $snapshot[$key] = $result[$key];
            }
        }

        return $snapshot;
    }

    /**

     * Canonical array untuk idempotency hash.

     */

    private function canonical(array $value): array

    {

        ksort($value);



        foreach ($value as &$item) {

            if (is_array($item)) {

                $item = $this->canonical($item);

            }

        }



        unset($item);



        return $value;

    }



    /**

     * Current datetime.

     */

    private function now(): string

    {

        return $this

            ->clock()

            ->format('Y-m-d H:i:s');

    }


    /**
     * Minimum distance between liveness writes generated by production API
     * traffic. Heartbeat has the same throttle contract so 1-second polling does
     * not become 100 UPDATE/s for 100 devices.
     */
    private function livenessTouchIntervalSeconds(): int
    {
        return max(1, (int) env('TPMS_LIVENESS_TOUCH_SECONDS', 10));
    }

    private function deviceConnectivityTouchDue(): bool
    {
        if (strtolower((string) ($this->device['connection_status'] ?? 'unknown')) !== 'reachable') {
            return true;
        }

        $lastSeen = trim((string) ($this->device['last_seen_at'] ?? ''));
        if ($lastSeen === '') {
            return true;
        }

        $lastSeenTs = strtotime($lastSeen);
        if ($lastSeenTs === false) {
            return true;
        }

        return ($this->clock()->getTimestamp() - $lastSeenTs) >= $this->livenessTouchIntervalSeconds();
    }

    private function touchDeviceConnectivityIfDue(bool $force = false): void
    {
        if (! $force && ! $this->deviceConnectivityTouchDue()) {
            return;
        }

        $now = $this->now();
        $this->db
            ->table('tpms_devices')
            ->where('id', (int) $this->device['id'])
            ->update([
                'last_seen_at' => $now,
                'connection_status' => 'reachable',
            ]);

        $this->device['last_seen_at'] = $now;
        $this->device['connection_status'] = 'reachable';
    }



    /**

     * Application timezone clock.

     */

    private function clock(): DateTimeImmutable

    {

        return new DateTimeImmutable(

            'now',

            new DateTimeZone(

                config('App')->appTimezone

            )

        );

    }



    /**

     * Ambil machine berdasarkan current slot device.

     */

    private function machine(): array

    {

        $slot = $this->db

            ->table('production_slots s')

            ->select(

                's.id slot_id,

                 m.id machine_id,

                 m.name'

            )

            ->join(

                'machines m',

                'm.id = s.machine_id'

            )

            ->where(

                's.id',

                $this->device['current_slot_id']

            )

            ->where(

                'm.disposed_at',

                null

            )

            ->get()

            ->getRowArray();



        if (! $slot) {

            throw new DomainException(

                'Perangkat belum terpasang pada slot mesin aktif.',

                409

            );

        }



        return $slot;

    }



    /**
     * Read-only context untuk HMI/TPMS.
     *
     * Endpoint ini sengaja memakai token device yang sama dengan production API
     * agar HMI tidak perlu akses database atau session web admin. Data yang
     * dikembalikan hanya konteks operasional: assignment slot/machine, shift,
     * planning employee hari kerja aktif, serta histori shift produksi terbaru.
     */
    private function context(): array
    {
        $machine = $this->machine();

        $slot = $this->db
            ->table('production_slots s')
            ->select('s.id, s.slot_no, s.name, s.area, s.status, m.id AS machine_id, m.code AS machine_code, m.name AS machine_name, m.registration_code, m.serial_number')
            ->join('machines m', 'm.id = s.machine_id')
            ->where('s.id', $machine['slot_id'])
            ->where('m.disposed_at', null)
            ->get()
            ->getRowArray();

        if (! $slot) {
            throw new DomainException('Assignment slot/machine perangkat tidak ditemukan.', 409);
        }

        $shifts = $this->db
            ->table('shifts')
            ->select('id, code, name, start_time, end_time')
            ->orderBy('start_time', 'ASC')
            ->get()
            ->getResultArray();

        $currentShift = ProductionRules::shift($shifts, $this->clock());
        $workDate = (string) $currentShift['work_date'];

        $assignments = $this->db
            ->table('employee_shift_assignments a')
            ->select('a.shift_id, a.assignment_role, e.id AS employee_id, e.nik, e.name, e.department, e.role, sh.code AS shift_code, sh.name AS shift_name, sh.start_time, sh.end_time')
            ->join('employees e', 'e.id = a.employee_id')
            ->join('shifts sh', 'sh.id = a.shift_id')
            ->where('a.machine_id', $slot['machine_id'])
            ->where('a.assignment_date', $workDate)
            ->where('a.is_active', 1)
            ->where('e.status', 'active')
            ->whereIn('a.assignment_role', ['operator', 'unit_head', 'kanit'])
            ->orderBy('sh.start_time', 'ASC')
            ->orderBy('a.assignment_role', 'ASC')
            ->get()
            ->getResultArray();

        $planning = [];
        foreach ($shifts as $shiftRow) {
            $shiftId = (int) $shiftRow['id'];
            $planning[$shiftId] = [
                'shift_id' => $shiftId,
                'shift_code' => $shiftRow['code'],
                'shift_name' => $shiftRow['name'],
                'start_time' => $shiftRow['start_time'],
                'end_time' => $shiftRow['end_time'],
                'operator' => null,
                'unit_head' => null,
            ];
        }

        foreach ($assignments as $row) {
            $shiftId = (int) $row['shift_id'];
            if (! isset($planning[$shiftId])) {
                continue;
            }

            $employee = [
                'id' => (int) $row['employee_id'],
                'nik' => $row['nik'],
                'name' => $row['name'],
                'department' => $row['department'],
                'role' => $row['role'],
            ];

            if (($row['assignment_role'] ?? '') === 'operator') {
                $planning[$shiftId]['operator'] = $employee;
            } elseif (in_array(($row['assignment_role'] ?? ''), ['unit_head', 'kanit'], true)) {
                $planning[$shiftId]['unit_head'] = $employee;
            }
        }

        $openProduction = $this->db
            ->table('productions p')
            ->select(
                'p.id, p.production_code, p.part_id, p.target_qty, p.target_duration_days, p.shifts_per_day, '
                . 'p.production_batch_id, p.part_process_id, p.process_no, p.process_name_snapshot, p.process_mode_snapshot, '
                . 'p.actual_qty, p.good_qty, p.reject_qty, p.status, p.session_state, '
                . 'part.part_number, part.name AS part_name, p.mode'
            )
            ->join('parts part', 'part.id = p.part_id')
            ->where('p.machine_id', $slot['machine_id'])
            ->whereIn('p.status', ['running', 'active'])
            ->groupStart()->where('p.good_qty < p.target_qty', null, false)->orWhere('p.mode','setting')->groupEnd()
            ->orderBy('p.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($openProduction) {
            $openProduction = [
                'id' => (int) $openProduction['id'],
                'production_code' => $openProduction['production_code'],
                'part_id' => (int) $openProduction['part_id'],
                'mode' => $openProduction['mode'],
                'plan_qty' => (int)$openProduction['target_qty'],
                'part_number' => $openProduction['part_number'],
                'part_name' => $openProduction['part_name'],
                'production_batch_id' => $openProduction['production_batch_id'] !== null ? (int) $openProduction['production_batch_id'] : null,
                'part_process_id' => $openProduction['part_process_id'] !== null ? (int) $openProduction['part_process_id'] : null,
                'process_no' => (int) ($openProduction['process_no'] ?? 1),
                'process_name' => $openProduction['process_name_snapshot'] ?? 'Single Process',
                'process_mode' => $openProduction['process_mode_snapshot'] ?? 'auto',
                'target_qty' => (int) $openProduction['target_qty'],
                'target_duration_days' => (int) $openProduction['target_duration_days'],
                'shifts_per_day' => (int) $openProduction['shifts_per_day'],
                'gross_qty' => (int) $openProduction['actual_qty'],
                'good_qty' => (int) ($openProduction['good_qty'] ?? 0),
                'reject_qty' => (int) ($openProduction['reject_qty'] ?? 0),
                'status' => $openProduction['status'],
                'session_state' => $openProduction['session_state'],
            ];
        }

        $history = $this->db
            ->table('production_shift_details d')
            ->select(
                'd.id, d.production_id, d.work_date, d.target_qty, d.actual_qty, d.good_qty, d.reject_qty, d.status, d.started_at, d.ended_at, '
                . 'p.production_code, p.production_batch_id, p.part_process_id, p.process_no, p.process_name_snapshot, p.process_mode_snapshot, p.target_qty AS production_target_qty, p.good_qty AS production_good_qty, '
                . 'part.part_number, part.name AS part_name, '
                . 'sh.code AS shift_code, sh.name AS shift_name, '
                . 'op.name AS operator_name, pic.name AS pic_name, uh.name AS unit_head_name'
            )
            ->join('productions p', 'p.id = d.production_id')
            ->join('parts part', 'part.id = p.part_id')
            ->join('shifts sh', 'sh.id = d.shift_id')
            ->join('employees op', 'op.id = d.operator_employee_id', 'left')
            ->join('employees pic', 'pic.id = d.pic_employee_id', 'left')
            ->join('employees uh', 'uh.id = d.unit_head_employee_id', 'left')
            ->where('p.machine_id', $slot['machine_id'])
            ->where('d.status', 'completed')
            ->orderBy('COALESCE(d.ended_at, d.started_at)', 'DESC', false)
            ->orderBy('d.id', 'DESC')
            ->limit(3)
            ->get()
            ->getResultArray();

        $history = array_map(static function (array $row): array {
            return [
                'production_shift_detail_id' => (int) $row['id'],
                'production_id' => (int) $row['production_id'],
                'production_code' => $row['production_code'],
                'work_date' => $row['work_date'],
                'shift_code' => $row['shift_code'],
                'shift_name' => $row['shift_name'],
                'part_number' => $row['part_number'],
                'part_name' => $row['part_name'],
                'production_batch_id' => $row['production_batch_id'] !== null ? (int) $row['production_batch_id'] : null,
                'part_process_id' => $row['part_process_id'] !== null ? (int) $row['part_process_id'] : null,
                'process_no' => (int) ($row['process_no'] ?? 1),
                'process_name' => $row['process_name_snapshot'] ?? 'Single Process',
                'process_mode' => $row['process_mode_snapshot'] ?? 'auto',
                'operator_name' => $row['operator_name'] ?? null,
                'pic_name' => $row['pic_name'] ?? null,
                'unit_head_name' => $row['unit_head_name'] ?? null,
                'target_qty' => (int) $row['target_qty'],
                'gross_qty' => (int) $row['actual_qty'],
                'good_qty' => (int) $row['good_qty'],
                'reject_qty' => (int) $row['reject_qty'],
                'production_target_qty' => (int) $row['production_target_qty'],
                'production_good_qty' => (int) ($row['production_good_qty'] ?? 0),
                'started_at' => $row['started_at'],
                'ended_at' => $row['ended_at'],
            ];
        }, $history);

        return [
            'device' => [
                'id' => (int) $this->device['id'],
                'mac_address' => $this->device['mac_address'],
                'ip_address' => $this->device['ip_address'],
                'firmware_version' => $this->device['firmware_version'],
                'hmi_version' => $this->device['hmi_version'],
                'device_status' => $this->device['device_status'],
                'connection_status' => $this->device['connection_status'],
            ],
            'slot' => [
                'id' => (int) $slot['id'],
                'slot_no' => (int) $slot['slot_no'],
                'name' => $slot['name'],
                'area' => $slot['area'],
                'status' => $slot['status'],
            ],
            'machine' => [
                'id' => (int) $slot['machine_id'],
                'code' => $slot['machine_code'],
                'name' => $slot['machine_name'],
                'registration_code'=>$slot['registration_code'],
                'serial_number'=>$slot['serial_number'],
            ],
            'current_shift' => [
                'id' => (int) $currentShift['id'],
                'code' => $currentShift['code'],
                'name' => $currentShift['name'],
                'work_date' => $workDate,
                'start_time' => $currentShift['start_time'] ?? null,
                'end_time' => $currentShift['end_time'] ?? null,
            ],
            'planning' => array_values($planning),
            'open_production' => $openProduction ?: null,
            'recent_completed_shifts' => $history,
            'server_time' => $this->now(),
        ];
    }

    /**

     * Daftar Part dan process untuk Machine, terpisah untuk Production atau Setting.

     */

    private function catalogue(array $input): array
    {
        $machine=$this->machine();
        $mode=$input['mode']??'production';
        if (!in_array($mode,['production','setting'],true)) throw new DomainException('Mode harus production atau setting.',422);
        $builder=$this->db->table('parts')->where('status','active');
        if (isset($input['part_code'])) $builder->where('part_number',strtoupper(ProductionRules::text($input['part_code'],'Part Code',100)));
        if (isset($input['part_id'])) $builder->where('id',ProductionRules::integer($input['part_id'],'Part',1));
        $parts=$builder->orderBy('part_number')->get()->getResultArray();$available=[];
        foreach ($parts as $part) {
            $processes=$this->db->table('part_processes')->where('part_id',$part['id'])->where('status','active')->orderBy('process_no')->get()->getResultArray();$rows=[];
            foreach ($processes as $process) {
                $error=null;$tools=[];$plan=null;
                try {
                    $plan=ProcessPlan::calculate((int)$process['machine_time_target_ms'],(int)$process['loading_time_target_ms'],(int)$part['shifts_per_day']);
                    $tools=(new MachineToolService($this->db))->resolve(
                        (int)$machine['machine_id'],
                        (int)$process['id'],
                        $mode==='production'
                    );
                } catch (DomainException $e) { $error=$e->getMessage(); }
                $row=['id'=>(int)$process['id'],'process_no'=>(int)$process['process_no'],'name'=>$process['process_name'],'process_name'=>$process['process_name'],'mode'=>$process['is_next_grinding']?'none':$process['process_mode'],'process_mode'=>$process['is_next_grinding']?'none':$process['process_mode'],'is_next_grinding'=>(bool)$process['is_next_grinding'],'is_first_process'=>(int)$process['process_no']===1,'is_last_process'=>(int)$process['process_no']===(int)$part['process_count'],'machine_time_target_ms'=>(int)$process['machine_time_target_ms'],'loading_time_target_ms'=>(int)$process['loading_time_target_ms'],'selectable'=>$error===null,'selection_message'=>$error,'tools'=>$tools,'plan'=>$plan];
                $row['setup_hash']=hash('sha256',json_encode([$machine['machine_id'],$part['id'],$row],JSON_THROW_ON_ERROR));
                $rows[]=$row;
            }
            $available[]=['id'=>(int)$part['id'],'part_number'=>$part['part_number'],'name'=>$part['name'],'process_count'=>(int)$part['process_count'],'shifts_per_day'=>(int)$part['shifts_per_day'],'processes'=>$rows,'selectable'=>count(array_filter($rows,static fn($r)=>$r['selectable']))>0];
        }
        return ['machine_id'=>(int)$machine['machine_id'],'mode'=>$mode,'parts'=>$available,'flow_contract'=>'part_position_machine_tools_v2','server_time'=>$this->now()];
    }


    private function advanceProductionBatch(int $productionId, string $now): void
    {
        $production = $this->db->table('productions')
            ->where('id', $productionId)
            ->get()
            ->getRowArray();

        if (! $production || empty($production['production_batch_id'])) {
            return;
        }

        $batch = $this->db->table('production_batches')
            ->where('id', (int) $production['production_batch_id'])
            ->get()
            ->getRowArray();
        if (! $batch) {
            return;
        }

        $processNo = (int) ($production['process_no'] ?? 1);
        $processCount = max(1, (int) ($batch['process_count'] ?? 1));

        if ($processNo >= $processCount) {
            $this->db->table('production_batches')
                ->where('id', (int) $batch['id'])
                ->update([
                    'current_process_no' => $processCount,
                    'final_good_qty' => (int) ($production['good_qty'] ?? 0),
                    'status' => 'completed',
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);


            return;
        }

        $this->db->table('production_batches')
            ->where('id', (int) $batch['id'])
            ->update([
                'current_process_no' => $processNo + 1,
                'updated_at' => $now,
            ]);
    }

    /**

     * Validasi Employee PIC aktif dari RFID. PIC tidak terikat Planning, machine, tanggal, atau shift.

     */

    private function pic(mixed $uid): array
    {
        $uid=ProductionRules::uid($uid);
        $employee=$this->db->table('employees')->where('rfid_uid',$uid)->where('status','active')->get()->getRowArray();
        if (!$employee || strtoupper(trim((string)$employee['role'])) !== 'PIC') throw new DomainException('RFID tidak valid sebagai PIC aktif.',403);
        return $employee;
    }


    /**
     * Mulai mode Setting setelah PIC valid memasukkan Part Code.
     * Process dan physical Tool sengaja belum dipilih pada tahap ini agar
     * durasi Setting tercatat sejak Part Code diterima, sesuai alur HMI.
     */
    private function beginSetting(array $input): array
    {
        $machine = $this->machine();
        $shift = ProductionRules::shift(
            $this->db->table('shifts')->get()->getResultArray(),
            $this->clock()
        );
        $pic = $this->pic($input['pic_uid'] ?? null);

        $partCode = strtoupper(ProductionRules::text($input['part_code'] ?? null, 'Part Code', 100));
        $part = $this->db
            ->table('parts')
            ->where('part_number', $partCode)
            ->where('status', 'active')
            ->get()
            ->getRowArray();

        if (! $part) {
            throw new DomainException('Part Code tidak aktif atau tidak ditemukan.', 422);
        }

        $active = $this->db
            ->table('production_shift_details d')
            ->select('d.*, p.machine_id, p.part_id, p.mode, p.status production_status, p.session_state')
            ->join('productions p', 'p.id=d.production_id')
            ->where('p.machine_id', $machine['machine_id'])
            ->whereIn('d.status', ['running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting'])
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        $now = $this->now();

        /*
         * Mid-production Setting: jangan menutup production/shift detail.
         * Selama machine, work date, shift dan Part masih sama, Setting hanyalah
         * runtime interruption pada detail aktif yang sama.
         */
        if ($active) {
            if (($active['mode'] ?? 'production') !== 'production') {
                throw new DomainException('Machine masih memiliki sesi Setting aktif.', 409);
            }
            if (($active['status'] ?? null) !== 'running') {
                throw new DomainException('Setting di tengah production hanya dapat dimulai saat production berstatus running.', 409);
            }
            if ((string) ($active['work_date'] ?? '') !== (string) $shift['work_date']
                || (int) ($active['shift_id'] ?? 0) !== (int) $shift['id']) {
                throw new DomainException('Shift production aktif sudah berubah. Selesaikan rollover shift terlebih dahulu.', 409);
            }
            if ((int) ($active['part_id'] ?? 0) !== (int) $part['id']) {
                throw new DomainException('Part Code Setting harus sama dengan Part production yang sedang aktif.', 409);
            }

            $productionId = (int) $active['production_id'];
            $detailId = (int) $active['id'];
            $this->interruptOpenCycle($detailId, $now);
            $this->closeRuntimeState($detailId, $now);
            $this->db->table('production_shift_details')->where('id', $detailId)->update([
                'status' => 'setting',
                'pic_employee_id' => (int) $pic['id'],
                'setting_configured_at' => null,
                'updated_at' => $now,
            ]);
            $this->db->table('productions')->where('id', $productionId)->update([
                'session_state' => 'setting',
                'updated_at' => $now,
            ]);
            $this->transitionRuntimeState($productionId, $detailId, 'setting', $now);

            $settingAlertId = $this->alarm(
                $detailId,
                null,
                'setting',
                'info',
                'notify',
                sprintf(
                    'Setting di tengah production dimulai oleh PIC %s (%s) untuk Part %s. Shift detail tetap digunakan.',
                    $pic['name'],
                    $pic['nik'],
                    $part['part_number']
                )
            );

            $state = $this->state($productionId);
            $state['part'] = [
                'id' => (int) $part['id'],
                'part_number' => $part['part_number'],
                'name' => $part['name'],
            ];
            $state['setting_configured'] = false;
            $state['setting_alert_id'] = $settingAlertId;
            $state['setting_scope'] = 'production_interrupt';
            $state['reused_shift_detail'] = true;
            return $state;
        }

        $head = $this->plannedEmployeeForRole((int) $machine['machine_id'], $shift, 'unit_head');

        $this->db->table('productions')->insert([
            'production_code' => 'SET-' . $this->clock()->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6))),
            'production_date' => $shift['work_date'],
            'slot_id' => $machine['slot_id'],
            'machine_id' => $machine['machine_id'],
            'device_id' => $this->device['id'],
            'part_id' => (int) $part['id'],
            'part_process_id' => null,
            'process_no' => 1,
            'process_name_snapshot' => 'Pending Process',
            'process_mode_snapshot' => 'none',
            'production_batch_id' => null,
            'rack_tool_id' => null,
            'mode' => 'setting',
            'plan_basis' => 'cycle_7h',
            'standard_machine_time_ms' => null,
            'standard_loading_time_ms' => null,
            'target_qty' => 0,
            'target_duration_days' => 1,
            'shifts_per_day' => (int) $part['shifts_per_day'],
            'actual_qty' => 0,
            'good_qty' => 0,
            'reject_qty' => 0,
            'status' => 'running',
            'session_state' => 'setting',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productionId = (int) $this->db->insertID();
        $detailId = $this->createShiftDetail(
            $productionId,
            $shift,
            null,
            (int) $pic['id'],
            $head ? (int) $head['id'] : null,
            0,
            [],
            $now
        );

        $this->db->table('production_shift_details')
            ->where('id', $detailId)
            ->update(['status' => 'setting', 'setting_configured_at' => null]);
        $this->transitionRuntimeState($productionId, $detailId, 'setting', $now);

        $settingAlertId = $this->alarm(
            $detailId,
            null,
            'setting',
            'info',
            'notify',
            sprintf(
                'Setting dimulai oleh PIC %s (%s) untuk Part %s.',
                $pic['name'],
                $pic['nik'],
                $part['part_number']
            )
        );

        $state = $this->state($productionId);
        $state['part'] = [
            'id' => (int) $part['id'],
            'part_number' => $part['part_number'],
            'name' => $part['name'],
        ];
        $state['setting_configured'] = false;
        $state['setting_alert_id'] = $settingAlertId;
        $state['setting_scope'] = 'standalone';
        $state['reused_shift_detail'] = false;

        return $state;
    }

    /**
     * Pilih Process dan konfirmasi physical Tool + posisi untuk sesi Setting
     * yang sudah aktif. Lifetime/status Tool tidak memblokir mode Setting,
     * karena PIC dapat sedang menyiapkan atau mengganti Tool tersebut.
     */
    private function configureSetting(array $input): array
    {
        $productionId = ProductionRules::integer($input['production_id'] ?? null, 'Session', 1);
        $production = $this->session($productionId);
        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'setting') {
            throw new DomainException('Tidak ada sesi Setting aktif.', 409);
        }
        $isStandaloneSetting = ($production['mode'] ?? 'production') === 'setting';
        $isProductionSetting = ($production['mode'] ?? 'production') === 'production';
        if (! $isStandaloneSetting && ! $isProductionSetting) {
            throw new DomainException('Sesi tidak mendukung Setting.', 409);
        }

        $machine = $this->machine();
        $pic = $this->pic($input['pic_uid'] ?? null);

        if ((int) $pic['id'] !== (int) ($detail['pic_employee_id'] ?? 0)) {
            throw new DomainException('Konfigurasi setting harus menggunakan RFID PIC yang memulai sesi.', 403);
        }

        $processId = ProductionRules::integer($input['part_process_id'] ?? null, 'Process', 1);
        $process = $this->db
            ->table('part_processes')
            ->where('id', $processId)
            ->where('part_id', $production['part_id'])
            ->where('status', 'active')
            ->get()
            ->getRowArray();

        if (! $process) {
            throw new DomainException('Process tidak sesuai Part atau tidak aktif.', 422);
        }
        if ($isProductionSetting && (int) ($production['part_process_id'] ?? 0) !== $processId) {
            throw new DomainException('Setting di tengah production harus menggunakan Process yang sedang aktif.', 409);
        }

        $tools = (new MachineToolService($this->db))->resolve(
            (int) $machine['machine_id'],
            $processId,
            false
        );

        if (($input['tools_position_confirmed'] ?? false) !== true) {
            throw new DomainException('Konfirmasi tools_position_confirmed wajib dikirim.', 422);
        }
        if (! is_array($input['tool_ids'] ?? null)) {
            throw new DomainException('tool_ids wajib berupa array.', 422);
        }

        $ids = array_map(
            static fn ($value) => ProductionRules::integer($value, 'Tool', 1),
            $input['tool_ids']
        );
        sort($ids);
        $resolved = array_map('intval', array_column($tools, 'tool_id'));
        sort($resolved);

        if ($ids !== $resolved) {
            throw new DomainException(
                'Physical tools berubah/tidak sesuai kebutuhan Process pada Machine. Muat ulang catalogue.',
                409
            );
        }

        $existingUsages = $this->db
            ->table('production_tool_usages')
            ->where('production_shift_detail_id', $detail['id'])
            ->get()
            ->getResultArray();

        if ($existingUsages) {
            $existingIds = array_map('intval', array_column($existingUsages, 'tool_id'));
            sort($existingIds);
            if ((int) ($production['part_process_id'] ?? 0) === $processId && $existingIds === $resolved) {
                $now = $this->now();
                $this->db->table('production_shift_details')->where('id', $detail['id'])->update([
                    'setting_configured_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->alarm(
                    (int) $detail['id'], null, 'setting', 'info', 'notify',
                    sprintf(
                        'Setting oleh PIC %s (%s). Process %s; posisi Tool dikonfirmasi ulang pada shift detail yang sama.',
                        $pic['name'], $pic['nik'], $process['process_name']
                    )
                );
                $state = $this->state($productionId);
                $state['setting_configured'] = true;
                $state['setting_scope'] = $isProductionSetting ? 'production_interrupt' : 'standalone';
                $state['reused_shift_detail'] = $isProductionSetting;
                return $state;
            }

            throw new DomainException('Setting sudah dikonfigurasi. Selesaikan sesi sebelum mengubah Process.', 409);
        }

        $now = $this->now();
        if ($isStandaloneSetting) {
            $this->db->table('productions')->where('id', $productionId)->update([
                'part_process_id' => $processId,
                'process_no' => (int) $process['process_no'],
                'process_name_snapshot' => $process['process_name'],
                'process_mode_snapshot' => $process['is_next_grinding'] ? 'none' : $process['process_mode'],
                'standard_machine_time_ms' => (int) $process['machine_time_target_ms'],
                'standard_loading_time_ms' => (int) $process['loading_time_target_ms'],
                'plan_basis' => 'cycle_7h',
                'updated_at' => $now,
            ]);
            $this->insertToolUsages((int) $detail['id'], $tools, $now);
        }
        $this->db->table('production_shift_details')->where('id', $detail['id'])->update([
            'setting_configured_at' => $now,
            'updated_at' => $now,
        ]);

        $this->alarm(
            (int) $detail['id'],
            null,
            'setting',
            'info',
            'notify',
            sprintf(
                'Setting oleh PIC %s (%s). Process %s; posisi Tool dikonfirmasi: %s.',
                $pic['name'],
                $pic['nik'],
                $process['process_name'],
                implode(', ', array_map(
                    static fn (array $tool): string => $tool['position'] . '=' . $tool['code'],
                    $tools
                ))
            )
        );

        $state = $this->state($productionId);
        $state['setting_configured'] = true;
        $state['setting_scope'] = $isProductionSetting ? 'production_interrupt' : 'standalone';
        $state['reused_shift_detail'] = $isProductionSetting;
        $state['tools'] = $tools;

        return $state;
    }

    private function start(array $input): array
    {
        return $this->startSession($input,'production');
    }

    private function startSession(array $input, string $mode): array
    {
        if (isset($input['mode']) && $input['mode'] !== $mode) throw new DomainException('Mode tidak sesuai endpoint.',422);
        $machine=$this->machine();
        $shift=ProductionRules::shift($this->db->table('shifts')->get()->getResultArray(),$this->clock());
        $pic=null;
        $employee=null;
        if ($mode==='setting') {
            $pic=$this->pic($input['pic_uid'] ?? null);
        } else {
            if (empty($input['operator_uid'])) throw new DomainException('RFID Operator wajib untuk memulai production.',422);
            $employee=$this->operator($input['operator_uid'],(int)$machine['machine_id'],$shift);
            if (empty($input['part_code'])) throw new DomainException('Part Code wajib untuk memulai production.',422);
        }
        $partId=ProductionRules::integer($input['part_id']??null,'Part',1);
        $part=$this->db->table('parts')->where('id',$partId)->where('status','active')->get()->getRowArray();
        if (!$part) throw new DomainException('Part tidak aktif/tidak ditemukan.',422);
        if (isset($input['part_code']) && strtoupper(trim((string)$input['part_code'])) !== strtoupper($part['part_number'])) throw new DomainException('Part Code tidak sesuai Part terpilih.',422);
        $processId=ProductionRules::integer($input['part_process_id']??null,'Process',1);
        $process=$this->db->table('part_processes')->where('id',$processId)->where('part_id',$partId)->where('status','active')->get()->getRowArray();
        if (!$process) throw new DomainException('Process tidak sesuai Part atau tidak aktif.',422);
        $plan=ProcessPlan::calculate((int)$process['machine_time_target_ms'],(int)$process['loading_time_target_ms'],(int)$part['shifts_per_day']);
        $now=$this->now();

        /*
         * START Production wajib business-idempotent terhadap identitas shift detail.
         * Jangan membuat row baru jika kombinasi berikut masih sama:
         *   production_id + work_date + shift_id + operator_employee_id.
         *
         * Karena production_id belum diketahui pada awal START, pencarian pertama
         * dibatasi ke Machine + Part + Process + work_date + shift + Operator.
         * Row yang ditemukan membawa production_id existing dan itulah yang direuse.
         */
        $reusableDetail = null;
        if ($mode === 'production' && $employee) {
            $reusableDetail = $this->findReusableShiftDetailForStart(
                (int) $machine['machine_id'],
                $partId,
                $processId,
                (string) $shift['work_date'],
                (int) $shift['id'],
                (int) $employee['id']
            );
        }

        $activeBuilder=$this->db->table('production_shift_details d')
            ->join('productions p','p.id=d.production_id')
            ->where('p.machine_id',$machine['machine_id'])
            ->whereIn('d.status',['running','paused','service_required','awaiting_defects',
                'operator_change_required','setting']);
        if ($reusableDetail) {
            $activeBuilder->where('d.id !=', (int) $reusableDetail['id']);
        }
        $active=$activeBuilder->countAllResults();
        if ($active) throw new DomainException('Machine masih memiliki sesi aktif. Tutup sesi sebelumnya.',409);
        $tools=$mode==='production' ? (new MachineToolService($this->db))->resolve((int)$machine['machine_id'],$processId) : [];
        if ($mode==='production') {
            if (($input['tools_installed']??false)!==true) throw new DomainException('Konfirmasi tools_installed wajib dikirim.',422);
            if (!is_array($input['tool_ids']??null)) throw new DomainException('tool_ids wajib berupa array.',422);
            $ids=array_map(static fn($v)=>ProductionRules::integer($v,'Tool',1),$input['tool_ids']);sort($ids);
            $resolved=array_map('intval',array_column($tools,'tool_id'));sort($resolved);
            if ($ids!==$resolved) throw new DomainException('Physical tools berubah/tidak sesuai kebutuhan Process pada Machine. Muat ulang catalogue.',409);
            /*
             * Jika detail yang sama sedang running, Tool memang sedang dipakai
             * oleh detail itu sendiri. START ulang cukup mengembalikan state lama.
             */
            if (! $reusableDetail || (string) $reusableDetail['status'] !== 'running') {
                foreach ($tools as $tool) (new ProductionRuntimeGuard($this->db))->assertToolMutable((int)$tool['tool_id'],'dipakai');
            }
        }
        if ($mode==='setting') $employee=$pic;
        $head=$this->plannedEmployeeForRole((int)$machine['machine_id'],$shift,'unit_head');

        if ($mode === 'production' && $reusableDetail) {
            return $this->reuseShiftDetailOnStart(
                $reusableDetail,
                $tools,
                $head ? (int) $head['id'] : null,
                $now
            );
        }

        /*
         * Tidak ada detail exact-match. Sebelum membuat production header baru,
         * cari production Part/Process yang sama dan targetnya belum tercapai.
         * Dengan begitu pergantian shift membuat detail baru DI BAWAH production
         * yang sama, bukan menggandakan productions/target.
         */
        $reusableProduction = null;
        if ($mode === 'production') {
            $reusableProduction = $this->findReusableProductionForStart(
                (int) $machine['machine_id'],
                $partId,
                $processId
            );
        }

        if ($mode === 'production' && $reusableProduction) {
            $productionId = (int) $reusableProduction['id'];
            $remainingTarget = max(
                0,
                (int) $reusableProduction['target_qty'] - (int) ($reusableProduction['good_qty'] ?? 0)
            );
            if ($remainingTarget <= 0) {
                throw new DomainException('Target production Part/Process ini sudah tercapai.', 409);
            }

            $shiftTarget = min((int) $plan['plan_per_shift'], $remainingTarget);
            $this->db->table('productions')->where('id', $productionId)->update([
                'slot_id' => $machine['slot_id'],
                'device_id' => $this->device['id'],
                'status' => 'running',
                'session_state' => 'running',
                'completed_at' => null,
                'updated_at' => $now,
            ]);

            $detailId=$this->createShiftDetail(
                $productionId,
                $shift,
                $employee ? (int)$employee['id'] : null,
                null,
                $head?(int)$head['id']:null,
                $shiftTarget,
                $tools,
                $now
            );
            $this->transitionRuntimeState($productionId,$detailId,'production',$now);
            $state = $this->state($productionId);
            $state['reused_production'] = true;
            $state['reused_shift_detail'] = false;
            return $state;
        }

        $this->db->table('productions')->insert([
            'production_code'=>($mode==='setting'?'SET-':'PRD-').$this->clock()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(6))),
            'production_date'=>$shift['work_date'],'slot_id'=>$machine['slot_id'],'machine_id'=>$machine['machine_id'],'device_id'=>$this->device['id'],
            'part_id'=>$partId,'part_process_id'=>$processId,'process_no'=>(int)$process['process_no'],'process_name_snapshot'=>$process['process_name'],
            'process_mode_snapshot'=>$process['is_next_grinding']?'none':$process['process_mode'],'production_batch_id'=>null,'rack_tool_id'=>null,
            'mode'=>$mode,'plan_basis'=>'cycle_7h','standard_machine_time_ms'=>(int)$process['machine_time_target_ms'],'standard_loading_time_ms'=>(int)$process['loading_time_target_ms'],
            'target_qty'=>$mode==='production'?$plan['plan_per_shift']:0,'target_duration_days'=>1,'shifts_per_day'=>(int)$part['shifts_per_day'],
            'actual_qty'=>0,'good_qty'=>0,'reject_qty'=>0,'status'=>'running','session_state'=>$mode==='setting'?'setting':'running','started_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $id=(int)$this->db->insertID();
        $detailId=$this->createShiftDetail(
            $id,
            $shift,
            $mode==='production' && $employee ? (int)$employee['id'] : null,
            $mode==='setting' && $pic ? (int)$pic['id'] : null,
            $head?(int)$head['id']:null,
            $mode==='production'?$plan['plan_per_shift']:0,
            $tools,
            $now
        );
        if ($mode==='setting') $this->db->table('production_shift_details')->where('id',$detailId)->update(['status'=>'setting']);
        $this->transitionRuntimeState($id,$detailId,$mode==='setting'?'setting':'production',$now);
        return $this->state($id);
    }

    private function finishSetting(array $input): array
    {
        $id = ProductionRules::integer($input['production_id'] ?? null, 'Session', 1);
        $production = $this->session($id);
        $detail = $this->activeShiftDetail($id);
        if (! $detail || $detail['status'] !== 'setting') {
            throw new DomainException('Tidak ada sesi Setting aktif.', 409);
        }

        $pic = $this->pic($input['pic_uid'] ?? null);
        if ((int) $pic['id'] !== (int) ($detail['pic_employee_id'] ?? 0)) {
            throw new DomainException('Penutupan setting harus menggunakan RFID PIC yang memulai sesi.', 403);
        }
        if (empty($production['part_process_id']) || empty($detail['setting_configured_at'])) {
            throw new DomainException('Setting belum dikonfigurasi Process/Tools dan posisi Tool belum dikonfirmasi.', 409);
        }

        $now = $this->now();
        $this->closeRuntimeState((int) $detail['id'], $now);
        $this->db->table('production_alarms')
            ->where('production_shift_detail_id', $detail['id'])
            ->where('kind', 'setting')
            ->where('resolved_at', null)
            ->update([
                'status' => 'resolved',
                'resolved_at' => $now,
                'resolution_notes' => 'Setting selesai oleh PIC ' . $pic['name'] . ' (' . $pic['nik'] . ').',
                'updated_at' => $now,
            ]);

        if (($production['mode'] ?? 'production') === 'production') {
            /*
             * Setting di tengah shift bukan FINISH production. Reuse detail,
             * quantity, counter epoch, Tool usage, Operator dan target lama.
             */
            $this->db->table('production_shift_details')->where('id', $detail['id'])->update([
                'status' => 'running',
                'updated_at' => $now,
            ]);
            $this->db->table('productions')->where('id', $id)->update([
                'status' => 'running',
                'session_state' => 'running',
                'updated_at' => $now,
            ]);
            $this->transitionRuntimeState($id, (int) $detail['id'], 'production', $now);
            $state = $this->state($id);
            $state['setting_scope'] = 'production_interrupt';
            $state['reused_shift_detail'] = true;
            return $state;
        }

        if (($production['mode'] ?? 'production') !== 'setting') {
            throw new DomainException('Sesi tidak mendukung Setting.', 409);
        }

        $this->db->table('production_shift_details')->where('id', $detail['id'])->update([
            'status' => 'completed',
            'ended_at' => $now,
            'updated_at' => $now,
        ]);
        $this->db->table('productions')->where('id', $id)->update([
            'status' => 'completed',
            'session_state' => 'completed',
            'completed_at' => $now,
            'updated_at' => $now,
        ]);
        $state = $this->state($id);
        $state['setting_scope'] = 'standalone';
        $state['reused_shift_detail'] = false;
        return $state;
    }

    private function settingState(array $production, bool $persistDeviceStatus = true): array
    {
        $detail=$this->latestShiftDetail((int)$production['id']);$active=$production['status']!=='completed';
        if ($persistDeviceStatus) {
            $this->syncDeviceOperationalStatus($active?'setting':'idle','setting_state');
        }
        $elapsed=$this->timeDiffMs($detail['started_at'],$detail['ended_at']?:$this->now());
        $pic = ! empty($detail['pic_employee_id'])
            ? $this->db->table('employees')->select('id,nik,name')->where('id',$detail['pic_employee_id'])->get()->getRowArray()
            : null;
        $tools = $this->db->table('production_tool_usages')
            ->select('tool_id,position_snapshot,tool_code_snapshot,tool_name_snapshot,tool_type_code_snapshot,set_lifetime_snapshot,start_lifetime,end_lifetime')
            ->where('production_shift_detail_id',$detail['id'])
            ->orderBy('id')
            ->get()
            ->getResultArray();
        return [
            'production_id'=>(int)$production['id'],
            'production_shift_detail_id'=>(int)$detail['id'],
            'production_code'=>$production['production_code'],
            'part_id'=>(int)$production['part_id'],
            'part_process_id'=>$production['part_process_id']===null?null:(int)$production['part_process_id'],
            'process_name'=>$production['process_name_snapshot'],
            'process_mode'=>$production['process_mode_snapshot'],
            'mode'=>'setting',
            'state'=>$active?'setting':'completed',
            'execution_state'=>$active?'setting':'completed',
            'command'=>'stop',
            'should_stop'=>true,
            'setting_active'=>$active,
            'setting_configured'=>!empty($detail['setting_configured_at']) && !empty($production['part_process_id']) && !empty($tools),
            'setting_duration_ms'=>$elapsed,
            'pic'=>$pic ? ['id'=>(int)$pic['id'],'nik'=>$pic['nik'],'name'=>$pic['name']] : null,
            'tools'=>$tools,
            'gross_qty'=>0,
            'good_qty'=>0,
            'reject_qty'=>0,
            'plan_qty'=>0,
            'server_time'=>$this->now(),
        ];
    }

    private function deviceChangeEdge(array $input): array
    {
        $machine=$this->machine();
        $pic=$this->pic($input['pic_uid'] ?? null);
        $toolId=ProductionRules::integer($input['tool_id']??null,'Tool',1);
        if (!$this->db->table('machine_tools')->where('machine_id',$machine['machine_id'])->where('tool_id',$toolId)->countAllResults()) throw new DomainException('Tool tidak terpasang pada Machine perangkat ini.',403);
        (new ToolEdgeService($this->db))->change(
            $toolId,
            ProductionRules::integer($input['current_edge']??null,'Current Edge',1),
            ProductionRules::text($input['reason']??null,'Reason',500),
            'tpms',
            ['pic_employee_id'=>(int)$pic['id'],'pic_nik'=>$pic['nik'],'pic_name'=>$pic['name']]
        );
        $result=$this->db->table('tools')->select('id,code,cutting_edge,current_edge,actual_lifetime,status')->where('id',$toolId)->get()->getRowArray();
        $result['maintenance_pic']=['id'=>(int)$pic['id'],'nik'=>$pic['nik'],'name'=>$pic['name']];
        return $result;
    }

    private function deviceResetTool(array $input): array
    {
        $machine = $this->machine();
        $pic = $this->pic($input['pic_uid'] ?? null);
        $toolId = ProductionRules::integer($input['tool_id'] ?? null, 'Tool', 1);
        $reason = ProductionRules::text($input['reason'] ?? null, 'Reason', 500);

        if (! $this->db->table('machine_tools')->where('machine_id', $machine['machine_id'])->where('tool_id', $toolId)->countAllResults()) {
            throw new DomainException('Tool tidak terpasang pada Machine perangkat ini.', 403);
        }

        $usage = $this->db
            ->table('production_tool_usages u')
            ->select('u.id AS usage_id,u.production_shift_detail_id,u.set_lifetime_snapshot,d.production_id,d.status AS detail_status')
            ->join('production_shift_details d', 'd.id=u.production_shift_detail_id')
            ->join('productions p', 'p.id=d.production_id')
            ->where('u.tool_id', $toolId)
            ->where('p.machine_id', $machine['machine_id'])
            ->where('d.status', 'service_required')
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $usage) {
            throw new DomainException('Reset/ganti Tool dari TPMS hanya diperbolehkan saat Tool berada pada critical service.', 409);
        }

        $alarm = $this->db
            ->table('production_alarms')
            ->where('production_shift_detail_id', $usage['production_shift_detail_id'])
            ->where('tool_id', $toolId)
            ->where('effect', 'service_stop')
            ->where('resolved_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $alarm) {
            throw new DomainException('Critical service alarm aktif untuk Tool tidak ditemukan.', 409);
        }

        $tool = $this->db->table('tools')->where('id', $toolId)->get()->getRowArray();
        if (! $tool) {
            throw new DomainException('Tool tidak ditemukan.', 404);
        }

        $before = $tool;
        $now = $this->now();
        $this->db->table('tools')->where('id', $toolId)->update([
            'current_edge' => 1,
            'actual_lifetime' => 0,
            'status' => 'ready',
            'updated_at' => $now,
        ]);
        $this->db->table('production_tool_usages')->where('id', $usage['usage_id'])->update([
            'end_lifetime' => 0,
            'updated_at' => $now,
        ]);

        $actorText = sprintf('PIC %s (%s)', $pic['name'], $pic['nik']);
        $this->db->table('tool_lifetime_logs')->insert([
            'tool_id' => $toolId,
            'event_type' => 'reset',
            'previous_lifetime' => (int) $tool['actual_lifetime'],
            'new_lifetime' => 0,
            'quantity' => null,
            'reason' => $reason . ' | ' . $actorText,
            'actor_user_id' => null,
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        $after = $this->db->table('tools')->where('id', $toolId)->get()->getRowArray();
        (new ActivityLogService())->tool(
            $toolId,
            'lifetime_reset',
            $before,
            $after,
            [
                'production_id' => (int) $usage['production_id'],
                'production_shift_detail_id' => (int) $usage['production_shift_detail_id'],
                'source' => 'tpms',
                'actor_type' => 'employee',
                'previous_lifetime' => (int) $tool['actual_lifetime'],
                'new_lifetime' => 0,
                'message' => 'Reset/ganti Tool fisik oleh ' . $actorText . '. ' . $reason,
                'metadata' => [
                    'change_type' => 'tool_reset_or_replacement',
                    'tool_replaced' => true,
                    'previous_edge' => (int) $tool['current_edge'],
                    'new_edge' => 1,
                    'pic_employee_id' => (int) $pic['id'],
                    'pic_nik' => $pic['nik'],
                    'pic_name' => $pic['name'],
                    'alarm_id' => (int) $alarm['id'],
                ],
            ]
        );

        return [
            'id' => (int) $after['id'],
            'code' => $after['code'],
            'cutting_edge' => (int) $after['cutting_edge'],
            'current_edge' => (int) $after['current_edge'],
            'actual_lifetime' => (int) $after['actual_lifetime'],
            'status' => $after['status'],
            'maintenance_pic' => ['id'=>(int)$pic['id'],'nik'=>$pic['nik'],'name'=>$pic['name']],
        ];
    }


    /**
     * Cari exact shift-detail untuk START ulang.
     *
     * Business identity:
     * - production Part/Process yang sama pada Machine yang sama;
     * - work_date sama;
     * - shift_id sama;
     * - operator_employee_id sama.
     *
     * production_id diperoleh dari row yang ditemukan dan kemudian dipertahankan.
     */
    private function findReusableShiftDetailForStart(
        int $machineId,
        int $partId,
        int $processId,
        string $workDate,
        int $shiftId,
        int $operatorEmployeeId
    ): ?array {
        $row = $this->db->table('production_shift_details d')
            ->select(
                'd.*, '
                . 'p.id matched_production_id, p.target_qty production_target_qty, '
                . 'p.good_qty production_good_qty, p.status production_status, '
                . 'p.session_state production_session_state'
            )
            ->join('productions p', 'p.id = d.production_id')
            ->where('p.machine_id', $machineId)
            ->where('p.part_id', $partId)
            ->where('p.part_process_id', $processId)
            ->where('p.mode', 'production')
            ->where('d.work_date', $workDate)
            ->where('d.shift_id', $shiftId)
            ->where('d.operator_employee_id', $operatorEmployeeId)
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Cari production header yang sama dan masih belum mencapai target.
     * Production sengaja dapat melintasi pergantian shift/work_date; histori
     * harian/shift tetap berada di production_shift_details.
     */
    private function findReusableProductionForStart(
        int $machineId,
        int $partId,
        int $processId
    ): ?array {
        $row = $this->db->table('productions')
            ->where('machine_id', $machineId)
            ->where('part_id', $partId)
            ->where('part_process_id', $processId)
            ->where('mode', 'production')
            ->where('good_qty < target_qty', null, false)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Buka kembali exact shift detail yang sama tanpa mereset quantity, target,
     * counter_epoch, cycle, alarm history, Tool usage, maupun started_at.
     */
    private function reuseShiftDetailOnStart(
        array $detail,
        array $tools,
        ?int $unitHeadEmployeeId,
        string $now
    ): array {
        $detailId = (int) $detail['id'];
        $productionId = (int) $detail['matched_production_id'];

        if (
            (int) ($detail['production_good_qty'] ?? 0)
            >= (int) ($detail['production_target_qty'] ?? 0)
        ) {
            throw new DomainException('Target production Part/Process ini sudah tercapai.', 409);
        }

        if (in_array((string) $detail['status'], [
            'paused',
            'service_required',
            'awaiting_defects',
            'operator_change_required',
            'setting',
        ], true)) {
            throw new DomainException(
                'Shift detail yang sama masih berstatus ' . $detail['status'] . '. Selesaikan state tersebut sebelum START ulang.',
                409
            );
        }

        if (! in_array((string) $detail['status'], ['running', 'completed'], true)) {
            throw new DomainException('Status shift detail tidak dapat direuse untuk START.', 409);
        }

        /* Physical Tool snapshot tidak boleh berubah diam-diam saat detail direuse. */
        $requestedToolIds = array_map('intval', array_column($tools, 'tool_id'));
        sort($requestedToolIds);
        $snapshotToolIds = array_map(
            'intval',
            array_column(
                $this->db->table('production_tool_usages')
                    ->select('tool_id')
                    ->where('production_shift_detail_id', $detailId)
                    ->orderBy('tool_id')
                    ->get()
                    ->getResultArray(),
                'tool_id'
            )
        );
        sort($snapshotToolIds);

        if ($requestedToolIds !== $snapshotToolIds) {
            throw new DomainException(
                'Physical Tool pada shift detail lama berbeda dengan Tool Machine saat ini. Lakukan Setting/penyesuaian Tool terlebih dahulu.',
                409
            );
        }

        /* START ulang ketika row masih running bersifat idempotent secara business. */
        if ((string) $detail['status'] === 'running') {
            if (
                (string) ($detail['production_status'] ?? '') !== 'running'
                || (string) ($detail['production_session_state'] ?? '') !== 'running'
            ) {
                $this->db->table('productions')->where('id', $productionId)->update([
                    'status' => 'running',
                    'session_state' => 'running',
                    'completed_at' => null,
                    'updated_at' => $now,
                ]);
            }

            $state = $this->state($productionId);
            $state['reused_production'] = true;
            $state['reused_shift_detail'] = true;
            $state['reused_same_work_date_shift_operator'] = true;
            return $state;
        }

        $this->db->table('production_shift_details')
            ->where('id', $detailId)
            ->update([
                'unit_head_employee_id' => $unitHeadEmployeeId,
                'status' => 'running',
                'ended_at' => null,
                'updated_at' => $now,
            ]);

        $this->db->table('productions')->where('id', $productionId)->update([
            'status' => 'running',
            'session_state' => 'running',
            'completed_at' => null,
            'updated_at' => $now,
        ]);

        $openHistory = $this->db->table('production_operator_histories')
            ->where('production_shift_detail_id', $detailId)
            ->where('employee_id', (int) $detail['operator_employee_id'])
            ->where('ended_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        if (! $openHistory) {
            $this->openOperatorHistory(
                $detailId,
                (int) $detail['operator_employee_id'],
                $now,
                'same_shift_restart',
                'tpms'
            );
        }

        $this->transitionRuntimeState($productionId, $detailId, 'production', $now);

        $state = $this->state($productionId);
        $state['reused_production'] = true;
        $state['reused_shift_detail'] = true;
        $state['reused_same_work_date_shift_operator'] = true;
        return $state;
    }


    private function createShiftDetail(

        int $productionId,

        array $shift,

        ?int $operatorEmployeeId,

        ?int $picEmployeeId,

        ?int $unitHeadEmployeeId,

        int $targetQty,

        array $tools,

        string $now

    ): int {

        /*
         * Defensive dedupe di level writer juga. Composite business key final:
         * production_id + work_date + shift_id + operator_employee_id.
         * Jangan pernah reset quantity/counter/tool usage kalau row sudah ada.
         */
        $existingBuilder = $this->db->table('production_shift_details')
            ->where('production_id', $productionId)
            ->where('work_date', $shift['work_date'])
            ->where('shift_id', $shift['id']);
        if ($operatorEmployeeId === null) {
            $existingBuilder->where('operator_employee_id', null);
        } else {
            $existingBuilder->where('operator_employee_id', $operatorEmployeeId);
        }
        $existing = $existingBuilder
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($existing) {
            $this->db->table('production_shift_details')
                ->where('id', (int) $existing['id'])
                ->update([
                    'pic_employee_id' => $picEmployeeId ?? $existing['pic_employee_id'],
                    'unit_head_employee_id' => $unitHeadEmployeeId ?? $existing['unit_head_employee_id'],
                    'updated_at' => $now,
                ]);
            return (int) $existing['id'];
        }

        $this->db->table('production_shift_details')->insert([

            'production_id' => $productionId,

            'shift_id' => $shift['id'],

            'work_date' => $shift['work_date'],

            'operator_employee_id' => $operatorEmployeeId,

            'pic_employee_id' => $picEmployeeId,

            'unit_head_employee_id' => $unitHeadEmployeeId,

            /* Counter selalu dimulai ulang dari 0 setiap shift. */
            'counter_epoch' => bin2hex(random_bytes(16)),

            'target_qty' => $targetQty,

            'actual_qty' => 0,

            'good_qty' => 0,

            'reject_qty' => 0,

            'status' => 'running',

            'started_at' => $now,

            'created_at' => $now,

            'updated_at' => $now,

        ]);



        $detailId = (int) $this->db->insertID();



        $this->insertToolUsages($detailId, $tools, $now);

        if ($operatorEmployeeId !== null) {
            $this->openOperatorHistory($detailId, $operatorEmployeeId, $now, 'production_start', 'tpms');
        }

        return $detailId;

    }

    private function insertToolUsages(int $detailId, array $tools, string $now): void
    {
        foreach ($tools as $tool) {
            $this->db->table('production_tool_usages')->insert([
                'production_shift_detail_id' => $detailId,
                'tool_id' => $tool['tool_id'],
                'position_snapshot' => $tool['position'],
                'tool_code_snapshot' => $tool['code'],
                'tool_name_snapshot' => $tool['name'],
                'tool_type_code_snapshot' => $tool['type_code'],
                'cutting_edge_snapshot' => $tool['cutting_edge'],
                'current_edge_snapshot' => $tool['current_edge'],
                'set_lifetime_snapshot' => $tool['set_lifetime'],
                'start_lifetime' => $tool['actual_lifetime'],
                'end_lifetime' => $tool['actual_lifetime'],
                'quantity_increment' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }



    /**

     * Validasi operator sesuai planning employee.

     */

    private function operator(

        $uid,

        int $machine,

        array $shift

    ): array {

        $employeeUid =

            ProductionRules::uid($uid);



        $employee = $this->db

            ->table('employees')

            ->where(

                'rfid_uid',

                $employeeUid

            )

            ->where(

                'status',

                'active'

            )

            ->get()

            ->getRowArray();



        if (! $employee) {

            throw new DomainException(

                'RFID operator tidak ditemukan atau employee tidak aktif.',

                403

            );

        }

        if (strtoupper(trim((string) ($employee['role'] ?? ''))) !== 'OPERATOR') {
            throw new DomainException(
                'RFID hanya dapat digunakan oleh employee dengan role Operator.',
                403
            );
        }



        $scheduled = $this->db

            ->table('employee_shift_assignments')

            ->where(

                'employee_id',

                $employee['id']

            )

            ->where(

                'machine_id',

                $machine

            )

            ->where(

                'assignment_date',

                $shift['work_date']

            )

            ->where(

                'shift_id',

                $shift['id']

            )

            ->where(

                'assignment_role',

                'operator'

            )

            ->where(

                'is_active',

                1

            )

            ->countAllResults();



        if (! $scheduled) {

            throw new DomainException(

                'RFID operator tidak sesuai planning mesin, tanggal kerja, dan shift saat ini.',

                403

            );

        }



        return $employee;

    }



    /**
     * Ambil personel planning berdasarkan role untuk snapshot shift.
     */
    private function plannedEmployeeForRole(int $machineId, array $shift, string $role): ?array
    {
        $builder = $this->db
            ->table('employee_shift_assignments a')
            ->select('e.*')
            ->join('employees e', 'e.id = a.employee_id')
            ->where('a.machine_id', $machineId)
            ->where('a.assignment_date', $shift['work_date'])
            ->where('a.shift_id', $shift['id'])
            ->where('a.is_active', 1)
            ->where('e.status', 'active');

        if ($role === 'unit_head') {
            $builder->whereIn('a.assignment_role', ['unit_head', 'kanit']);
        } else {
            $builder->where('a.assignment_role', $role);
        }

        $row = $builder
            ->orderBy('a.id', 'DESC')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**

     * Ambil production session milik device.

     */

    private function session(int $id): array

    {

        $production = $this->db

            ->table('productions')

            ->where(

                'id',

                $id

            )

            ->where(

                'device_id',

                $this->device['id']

            )

            ->get()

            ->getRowArray();



        if (! $production) {

            throw new DomainException(

                'Sesi tidak ditemukan untuk perangkat ini.',

                404

            );

        }



        return $production;

    }



    /**

     * Detail shift yang masih aktif secara operasional.

     */

    private function activeShiftDetail(int $productionId): ?array

    {

        $row = $this->db

            ->table('production_shift_details')

            ->where('production_id', $productionId)

            ->whereIn('status', [

                'running',

                'paused',

                'service_required',

                'awaiting_defects',
                    'operator_change_required',
                    'setting',

            ])

            ->orderBy('id', 'DESC')

            ->get()

            ->getRowArray();



        return $row ?: null;

    }



    /**

     * Detail shift terakhir, termasuk yang sudah completed.

     */

    private function latestShiftDetail(int $productionId): ?array

    {

        $row = $this->db

            ->table('production_shift_details')

            ->where('production_id', $productionId)

            ->orderBy('id', 'DESC')

            ->get()

            ->getRowArray();



        return $row ?: null;

    }



    /**

     * Apakah detail masih memiliki alarm yang memblokir produksi.

     */

    private function hasBlockingAlarm(int $detailId): bool

    {

        return $this->db

            ->table('production_alarms')

            ->where('production_shift_detail_id', $detailId)

            ->where('resolved_at', null)

            ->whereIn('effect', ['pause', 'service_stop', 'operator_stop'])

            ->countAllResults() > 0;

    }



    /**

     * Hitung ulang state detail dari alarm yang masih aktif.

     */

    private function refreshDetailState(int $detailId): string

    {

        $detail = $this->db

            ->table('production_shift_details')

            ->where('id', $detailId)

            ->get()

            ->getRowArray();



        if (! $detail) {

            throw new RuntimeException('Detail shift tidak ditemukan.');

        }



        if (in_array($detail['status'], ['awaiting_defects',
                    'operator_change_required',
                    'setting', 'completed'], true)) {

            return $detail['status'];

        }



        $service = $this->db

            ->table('production_alarms')

            ->where('production_shift_detail_id', $detailId)

            ->where('resolved_at', null)

            ->where('effect', 'service_stop')

            ->countAllResults();



        $pause = $this->db

            ->table('production_alarms')

            ->where('production_shift_detail_id', $detailId)

            ->where('resolved_at', null)

            ->where('effect', 'pause')

            ->countAllResults();



        $status = $service ? 'service_required' : ($pause ? 'paused' : 'running');



        $this->db

            ->table('production_shift_details')

            ->where('id', $detailId)

            ->update([

                'status' => $status,

                'updated_at' => $this->now(),

            ]);



        return $status;

    }





    /**

     * Count / Alarm / Resume / Service Complete / Stop / Finish.

     */

    /**
     * Trigger 1: mesin mulai memproses satu part.
     * Tidak menaikkan counter. Counter naik saat cycle-stop.
     */
    private function cycleStart(array $input): array
    {
        $productionId = ProductionRules::integer(
            $input['production_id'] ?? null,
            'production_id',
            1
        );
        $production = $this->session($productionId);
        if (($production['mode'] ?? 'production') === 'setting') {
            throw new DomainException('Setting Mode tidak menerima production cycle.', 409);
        }

        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'running') {
            throw new DomainException('CYCLE START hanya diterima saat production running.', 409);
        }

        $this->assertCounterContract($detail, $input);
        $this->assertCountShiftCurrent($production, $detail);

        /*
         * Phase 2 optimization:
         * Sebelumnya CYCLE START membaca production_cycles tiga kali:
         *  - open cycle
         *  - last completed cycle
         *  - MAX(sequence_no)
         *
         * Per-device writer lock dari Phase 1 menjamin tidak ada dua writer
         * untuk device yang sama pada transaction yang sama. Karena sequence_no
         * selalu monoton per detail, row terakhir cukup untuk ketiga kebutuhan.
         */
        $lastCycle = $this->db->table('production_cycles')
            ->where('production_shift_detail_id', (int) $detail['id'])
            ->orderBy('sequence_no', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (
            $lastCycle
            && ($lastCycle['status'] ?? null) === 'started'
            && empty($lastCycle['machine_stopped_at'])
        ) {
            throw new DomainException('Masih ada cycle yang belum menerima trigger STOP.', 409);
        }

        $now = $this->now();
        $triggerMs = array_key_exists('trigger_ms', $input)
            ? ProductionRules::integer($input['trigger_ms'], 'trigger_ms')
            : null;

        $loadingMs = 0;
        $loadingSource = $lastCycle;

        /*
         * Preserve perilaku lama jika row terakhir adalah interrupted: loading
         * dihitung dari cycle completed terakhir. Query tambahan hanya terjadi
         * pada jalur interrupted, bukan pada normal production path.
         */
        if ($lastCycle && ($lastCycle['status'] ?? null) !== 'completed') {
            $loadingSource = $this->db->table('production_cycles')
                ->where('production_shift_detail_id', (int) $detail['id'])
                ->where('status', 'completed')
                ->orderBy('sequence_no', 'DESC')
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();
        }

        if ($loadingSource) {
            if (
                $triggerMs !== null
                && isset($loadingSource['stop_tick_ms'])
                && $loadingSource['stop_tick_ms'] !== null
                && $triggerMs >= (int) $loadingSource['stop_tick_ms']
            ) {
                $loadingMs = $triggerMs - (int) $loadingSource['stop_tick_ms'];
            } elseif (! empty($loadingSource['machine_stopped_at'])) {
                $loadingMs = $this->timeDiffMs($loadingSource['machine_stopped_at'], $now);
            }
        }

        $sequenceNo = $lastCycle
            ? ((int) ($lastCycle['sequence_no'] ?? 0)) + 1
            : 1;

        $cycleInsert = [
            'production_id' => $productionId,
            'production_shift_detail_id' => (int) $detail['id'],
            'part_process_id' => $production['part_process_id'] ?? null,
            'sequence_no' => $sequenceNo,
            'machine_started_at' => $now,
            'machine_stopped_at' => null,
            'start_tick_ms' => $triggerMs,
            'stop_tick_ms' => null,
            'machine_time_ms' => 0,
            'loading_time_ms' => max(0, $loadingMs),
            'cycle_time_ms' => max(0, $loadingMs),
            'counter_total_snapshot' => (int) $detail['actual_qty'],
            'status' => 'started',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        // Snapshot operator per cycle dipakai Cycle Time Report agar histori
        // tetap benar saat operator shift detail diganti. Dibuat kompatibel
        // dengan database yang migration barunya belum dijalankan.
        if ($this->db->fieldExists('operator_employee_id', 'production_cycles')) {
            $cycleInsert['operator_employee_id'] = ! empty($detail['operator_employee_id'])
                ? (int) $detail['operator_employee_id']
                : null;
        }

        $this->db->table('production_cycles')->insert($cycleInsert);

        $cycleId = (int) $this->db->insertID();
        $result = $this->state($productionId);
        $result['cycle'] = [
            'id' => $cycleId,
            'sequence_no' => $sequenceNo,
            'status' => 'started',
            'machine_started_at' => $now,
            'loading_time_ms' => max(0, $loadingMs),
        ];

        return $result;
    }

    /**
     * Trigger 2: mesin selesai memproses satu part.
     * Satu STOP yang valid = satu gross part selesai, sehingga counter +1.
     */
    private function cycleStop(array $input): array
    {
        $productionId = ProductionRules::integer(
            $input['production_id'] ?? null,
            'production_id',
            1
        );
        $production = $this->session($productionId);
        if (($production['mode'] ?? 'production') === 'setting') {
            throw new DomainException('Setting Mode tidak menerima production cycle.', 409);
        }

        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'running') {
            throw new DomainException('CYCLE STOP hanya diterima saat production running.', 409);
        }

        $this->assertCounterContract($detail, $input);
        $cycle = $this->db->table('production_cycles')
            ->where('production_shift_detail_id', (int) $detail['id'])
            ->where('status', 'started')
            ->where('machine_stopped_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $cycle) {
            throw new DomainException('Tidak ada cycle aktif. Kirim trigger START terlebih dahulu.', 409);
        }

        if (isset($input['cycle_id']) && (int) $input['cycle_id'] !== (int) $cycle['id']) {
            throw new DomainException('cycle_id tidak sesuai cycle aktif.', 409);
        }

        $now = $this->now();
        $triggerMs = array_key_exists('trigger_ms', $input)
            ? ProductionRules::integer($input['trigger_ms'], 'trigger_ms')
            : null;

        if (
            $triggerMs !== null
            && $cycle['start_tick_ms'] !== null
            && $triggerMs >= (int) $cycle['start_tick_ms']
        ) {
            $machineMs = $triggerMs - (int) $cycle['start_tick_ms'];
        } else {
            $machineMs = $this->timeDiffMs($cycle['machine_started_at'], $now);
        }

        $loadingMs = max(0, (int) ($cycle['loading_time_ms'] ?? 0));
        $cycleMs = max(0, $machineMs + $loadingMs);

        /*
         * Phase 2: applyCounterDelta mengembalikan snapshot counter hasil update.
         * Dengan begitu cycle-stop tidak perlu SELECT activeShiftDetail sekali lagi.
         */
        $counter = $this->applyCounterDelta($production, $detail, 1, $input);

        $this->db->table('production_cycles')
            ->where('id', (int) $cycle['id'])
            ->update([
                'machine_stopped_at' => $now,
                'stop_tick_ms' => $triggerMs,
                'machine_time_ms' => max(0, $machineMs),
                'loading_time_ms' => $loadingMs,
                'cycle_time_ms' => $cycleMs,
                'counter_total_snapshot' => (int) $counter['detail_actual_qty'],
                'status' => 'completed',
                'updated_at' => $now,
            ]);

        $result = $this->state($productionId);
        $result['cycle'] = [
            'id' => (int) $cycle['id'],
            'sequence_no' => (int) $cycle['sequence_no'],
            'status' => 'completed',
            'machine_time_ms' => max(0, $machineMs),
            'loading_time_ms' => $loadingMs,
            'cycle_time_ms' => $cycleMs,
            'counter_total' => (int) $counter['detail_actual_qty'],
        ];

        return $result;
    }

    /** Operator break. Berbeda dari machine fault. */
    private function pauseProduction(int $productionId, array $detail, array $input): array
    {
        if ($detail['status'] !== 'running') {
            throw new DomainException('Production hanya dapat di-pause ketika running.', 409);
        }

        $now = $this->now();

        /*
         * Jika operator menekan Pause saat trigger START sudah diterima tetapi
         * trigger STOP belum datang, cycle tersebut tidak boleh menjadi satu
         * produk jadi. Tutup sebagai interrupted tanpa menambah counter.
         */
        $this->interruptOpenCycle((int) $detail['id'], $now);

        $message = isset($input['message'])
            ? ProductionRules::optionalText($input['message'], 'message', 500)
            : null;
        $alarmId = $this->alarm(
            (int) $detail['id'],
            null,
            'operator_break',
            'non_critical',
            'pause',
            $message ?: 'Operator break / istirahat.'
        );
        $this->refreshDetailState((int) $detail['id']);
        $this->transitionRuntimeState(
            $productionId,
            (int) $detail['id'],
            'break',
            $now
        );

        $result = $this->state($productionId);
        $result['pause_alarm_id'] = $alarmId;
        return $result;
    }

    /**
     * Tutup cycle START yang belum memiliki STOP sebagai interrupted.
     * Cycle interrupted tidak menambah gross counter dan tetap disimpan untuk audit.
     */
    private function interruptOpenCycle(int $detailId, string $now): void
    {
        if (! $this->db->tableExists('production_cycles')) {
            return;
        }

        $cycle = $this->db->table('production_cycles')
            ->where('production_shift_detail_id', $detailId)
            ->where('status', 'started')
            ->where('machine_stopped_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $cycle) {
            return;
        }

        $machineMs = $this->timeDiffMs($cycle['machine_started_at'] ?? null, $now);
        $loadingMs = max(0, (int) ($cycle['loading_time_ms'] ?? 0));

        $this->db->table('production_cycles')
            ->where('id', (int) $cycle['id'])
            ->update([
                'machine_stopped_at' => $now,
                'machine_time_ms' => max(0, $machineMs),
                'cycle_time_ms' => max(0, $machineMs + $loadingMs),
                'status' => 'interrupted',
                'updated_at' => $now,
            ]);
    }

    private function transitionRuntimeState(
        int $productionId,
        int $detailId,
        string $state,
        ?string $now = null
    ): void {
        if (! $this->db->tableExists('production_runtime_intervals')) {
            return;
        }
        if (! in_array($state, ['production', 'break', 'idle', 'setting'], true)) {
            throw new RuntimeException('Runtime state tidak valid.');
        }

        $now ??= $this->now();
        $open = $this->db->table('production_runtime_intervals')
            ->where('production_shift_detail_id', $detailId)
            ->where('ended_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($open && $open['state'] === $state) {
            return;
        }

        if ($open) {
            $this->db->table('production_runtime_intervals')
                ->where('id', (int) $open['id'])
                ->update([
                    'ended_at' => $now,
                    'duration_ms' => $this->timeDiffMs($open['started_at'], $now),
                    'updated_at' => $now,
                ]);
        }

        $this->db->table('production_runtime_intervals')->insert([
            'production_id' => $productionId,
            'production_shift_detail_id' => $detailId,
            'state' => $state,
            'source' => 'production_api',
            'started_at' => $now,
            'ended_at' => null,
            'duration_ms' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function closeRuntimeState(int $detailId, ?string $now = null): void
    {
        if (! $this->db->tableExists('production_runtime_intervals')) {
            return;
        }
        $now ??= $this->now();
        $open = $this->db->table('production_runtime_intervals')
            ->where('production_shift_detail_id', $detailId)
            ->where('ended_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        if (! $open) {
            return;
        }
        $this->db->table('production_runtime_intervals')
            ->where('id', (int) $open['id'])
            ->update([
                'ended_at' => $now,
                'duration_ms' => $this->timeDiffMs($open['started_at'], $now),
                'updated_at' => $now,
            ]);
    }

    private function timeDiffMs(?string $start, ?string $end): int
    {
        if (! $start || ! $end) {
            return 0;
        }
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if ($startTs === false || $endTs === false || $endTs < $startTs) {
            return 0;
        }
        return (int) (($endTs - $startTs) * 1000);
    }


    /**
     * Operator aktif menghentikan production karena sakit/berhalangan.
     * Shift detail tetap sama dan dikunci sampai PIC resolve + Planning diganti.
     */
    private function operatorSick(array $input): array
    {
        $productionId = ProductionRules::integer($input['production_id'] ?? null, 'production_id', 1);
        $production = $this->session($productionId);
        if (($production['mode'] ?? 'production') !== 'production') {
            throw new DomainException('Operator sakit hanya berlaku untuk Production Mode.', 409);
        }
        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'running') {
            throw new DomainException('Operator sakit hanya dapat dijalankan saat production running.', 409);
        }
        if (empty($detail['operator_employee_id'])) {
            throw new DomainException('Shift detail tidak memiliki Operator aktif.', 409);
        }

        $currentShift = ProductionRules::shift(
            $this->db->table('shifts')->get()->getResultArray(),
            $this->clock()
        );
        if ((int) $currentShift['id'] !== (int) $detail['shift_id']
            || (string) $currentShift['work_date'] !== (string) $detail['work_date']) {
            throw new DomainException('Shift sudah berganti. Jalankan prosedur rollover shift, bukan Operator Sakit pada detail lama.', 409);
        }

        $this->assertCounterContract($detail, $input);
        $counter = ProductionRules::integer($input['counter_total'] ?? null, 'counter_total');
        if ($counter !== (int) $detail['actual_qty']) {
            throw new DomainException('Sinkronkan counter terlebih dahulu sebelum menghentikan production karena Operator sakit.', 409);
        }

        $uid = ProductionRules::uid($input['operator_uid'] ?? null);
        $operator = $this->db->table('employees')
            ->where('id', (int) $detail['operator_employee_id'])
            ->where('rfid_uid', $uid)
            ->where('status', 'active')
            ->get()->getRowArray();
        if (! $operator || strtoupper(trim((string) ($operator['role'] ?? ''))) !== 'OPERATOR') {
            throw new DomainException('Konfirmasi Operator sakit harus menggunakan RFID Operator yang sedang menjalankan shift detail.', 403);
        }

        $reason = ProductionRules::text($input['reason'] ?? null, 'Alasan operator sakit', 350);
        $now = $this->now();
        $this->interruptOpenCycle((int) $detail['id'], $now);
        $alarmId = $this->alarm(
            (int) $detail['id'], null, 'operator_sick', 'critical', 'operator_stop',
            sprintf('Operator %s (%s) menghentikan production: %s', $operator['name'], $operator['nik'], $reason)
        );
        $this->closeOperatorHistory((int) $detail['id'], (int) $operator['id'], $now, 'operator_sick');
        $this->db->table('production_shift_details')->where('id', (int) $detail['id'])->update([
            'status' => 'operator_change_required', 'updated_at' => $now,
        ]);
        $this->db->table('productions')->where('id', $productionId)->update([
            'session_state' => 'operator_change_required',
            'updated_at' => $now,
        ]);
        $this->transitionRuntimeState($productionId, (int) $detail['id'], 'idle', $now);

        (new ActivityLogService())->machine((int) $production['machine_id'], 'operator_sick_stop',
            ['operator_employee_id' => (int) $operator['id'], 'status' => 'running'],
            ['operator_employee_id' => (int) $operator['id'], 'status' => 'operator_change_required'],
            [
                'source' => 'tpms', 'actor_type' => 'employee', 'severity' => 'critical',
                'message' => sprintf('Production dihentikan karena Operator %s sakit/berhalangan.', $operator['name']),
                'metadata' => [
                    'production_id' => $productionId,
                    'production_shift_detail_id' => (int) $detail['id'],
                    'operator_employee_id' => (int) $operator['id'],
                    'operator_nik' => $operator['nik'], 'operator_name' => $operator['name'],
                    'alarm_id' => $alarmId, 'reason' => $reason,
                ],
            ]
        );
        return $this->state($productionId);
    }

    /** PIC menyelesaikan critical alert Operator Sakit. Detail tetap terkunci. */
    private function resolveOperatorSick(array $input): array
    {
        $productionId = ProductionRules::integer($input['production_id'] ?? null, 'production_id', 1);
        $production = $this->session($productionId);
        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'operator_change_required') {
            throw new DomainException('Production tidak sedang menunggu pergantian Operator.', 409);
        }
        $alarmId = ProductionRules::integer($input['alarm_id'] ?? null, 'alarm_id', 1);
        $alarm = $this->db->table('production_alarms')
            ->where('id', $alarmId)->where('production_shift_detail_id', (int) $detail['id'])
            ->where('kind', 'operator_sick')->where('effect', 'operator_stop')
            ->where('resolved_at', null)->get()->getRowArray();
        if (! $alarm) {
            throw new DomainException('Critical alert Operator Sakit tidak ditemukan atau sudah selesai.', 409);
        }
        $shift = ProductionRules::shift($this->db->table('shifts')->get()->getResultArray(), $this->clock());
        if ((int) $shift['id'] !== (int) $detail['shift_id']
            || (string) $shift['work_date'] !== (string) $detail['work_date']) {
            throw new DomainException('Shift sudah berganti. Alert Operator Sakit detail lama tidak dapat di-resolve pada shift baru.', 409);
        }
        $pic = $this->pic($input['pic_uid'] ?? null);
        $now = $this->now();
        $note = ProductionRules::optionalText($input['message'] ?? null, 'message', 300);
        $this->db->table('production_alarms')->where('id', $alarmId)->update([
            'status' => 'resolved', 'resolved_at' => $now,
            'resolution_notes' => trim('PIC ' . $pic['name'] . ' (' . $pic['nik'] . '). ' . ($note ?? 'Operator pengganti dapat dijadwalkan oleh Admin.')),
            'updated_at' => $now,
        ]);
        (new ActivityLogService())->machine((int) $production['machine_id'], 'operator_sick_resolved',
            ['status' => 'operator_change_required'], ['status' => 'operator_change_required'], [
                'source' => 'tpms', 'actor_type' => 'employee', 'severity' => 'info',
                'message' => 'Critical Operator Sakit di-resolve PIC ' . $pic['name'] . '. Menunggu Admin mengganti Planning Operator.',
                'metadata' => [
                    'production_id' => $productionId,
                    'production_shift_detail_id' => (int) $detail['id'],
                    'pic_employee_id' => (int) $pic['id'], 'pic_nik' => $pic['nik'], 'pic_name' => $pic['name'],
                    'alarm_id' => $alarmId,
                ],
            ]
        );
        $state = $this->state($productionId);
        $state['operator_sick_resolved'] = true;
        $state['planning_change_required'] = true;
        return $state;
    }

    /** Operator pengganti masuk setelah Admin mengganti Planning Operator. */
    private function activateReplacementOperator(array $input): array
    {
        $productionId = ProductionRules::integer($input['production_id'] ?? null, 'production_id', 1);
        $production = $this->session($productionId);
        $detail = $this->activeShiftDetail($productionId);
        if (! $detail || $detail['status'] !== 'operator_change_required') {
            throw new DomainException('Production tidak sedang menunggu Operator pengganti.', 409);
        }
        $latestSick = $this->db->table('production_alarms')
            ->where('production_shift_detail_id', (int) $detail['id'])
            ->where('kind', 'operator_sick')->where('effect', 'operator_stop')
            ->orderBy('id', 'DESC')->get()->getRowArray();
        if (! $latestSick || empty($latestSick['resolved_at'])) {
            throw new DomainException('Alert Operator Sakit terbaru harus di-resolve PIC sebelum Operator pengganti dapat masuk.', 409);
        }
        $shift = ProductionRules::shift($this->db->table('shifts')->get()->getResultArray(), $this->clock());
        if ((int) $shift['id'] !== (int) $detail['shift_id'] || (string) $shift['work_date'] !== (string) $detail['work_date']) {
            throw new DomainException('Shift sudah berganti. Operator pengganti tidak dapat melanjutkan detail shift lama.', 409);
        }
        $replacement = $this->operator($input['operator_uid'] ?? null, (int) $production['machine_id'], $shift);
        $previousOperatorId = (int) ($detail['operator_employee_id'] ?? 0);
        if ((int) $replacement['id'] === $previousOperatorId) {
            throw new DomainException('Planning belum berubah ke Operator pengganti.', 409);
        }
        $now = $this->now();
        $this->db->table('production_shift_details')->where('id', (int) $detail['id'])->update([
            'operator_employee_id' => (int) $replacement['id'], 'status' => 'running', 'updated_at' => $now,
        ]);
        $this->db->table('productions')->where('id', $productionId)->update([
            'session_state' => 'running',
            'updated_at' => $now,
        ]);
        $this->openOperatorHistory((int) $detail['id'], (int) $replacement['id'], $now, 'replacement_after_operator_sick', 'tpms');
        $this->transitionRuntimeState($productionId, (int) $detail['id'], 'production', $now);
        (new ActivityLogService())->machine((int) $production['machine_id'], 'operator_replaced',
            ['operator_employee_id' => $previousOperatorId, 'status' => 'operator_change_required'],
            ['operator_employee_id' => (int) $replacement['id'], 'status' => 'running'], [
                'source' => 'tpms', 'actor_type' => 'employee', 'severity' => 'info',
                'message' => 'Operator pengganti ' . $replacement['name'] . ' masuk dan production dilanjutkan pada shift detail yang sama.',
                'metadata' => [
                    'production_id' => $productionId, 'production_shift_detail_id' => (int) $detail['id'],
                    'previous_operator_employee_id' => $previousOperatorId ?: null,
                    'new_operator_employee_id' => (int) $replacement['id'],
                    'new_operator_nik' => $replacement['nik'], 'new_operator_name' => $replacement['name'],
                ],
            ]
        );
        return $this->state($productionId);
    }

    private function employeeSummary(int $employeeId): ?array
    {
        if ($employeeId <= 0) return null;
        $row = $this->db->table('employees')->select('id,nik,name,role')->where('id', $employeeId)->get()->getRowArray();
        return $row ? ['id'=>(int)$row['id'],'nik'=>$row['nik'],'name'=>$row['name'],'role'=>$row['role']] : null;
    }

    private function openOperatorHistory(int $detailId, int $employeeId, string $startedAt, string $reason, string $source): void
    {
        if (! $this->db->tableExists('production_operator_histories')) return;
        $open = $this->db->table('production_operator_histories')->where('production_shift_detail_id',$detailId)->where('ended_at',null)->get()->getRowArray();
        if ($open) {
            if ((int)$open['employee_id'] === $employeeId) return;
            $this->db->table('production_operator_histories')->where('id',(int)$open['id'])->update(['ended_at'=>$startedAt,'change_reason'=>'replaced','updated_at'=>$startedAt]);
        }
        $this->db->table('production_operator_histories')->insert([
            'production_shift_detail_id'=>$detailId,'employee_id'=>$employeeId,'started_at'=>$startedAt,'ended_at'=>null,
            'change_reason'=>$reason,'source'=>$source,'created_at'=>$startedAt,'updated_at'=>$startedAt,
        ]);
    }

    private function closeOperatorHistory(int $detailId, int $employeeId, string $endedAt, string $reason): void
    {
        if ($employeeId <= 0 || ! $this->db->tableExists('production_operator_histories')) return;
        $open = $this->db->table('production_operator_histories')->where('production_shift_detail_id',$detailId)
            ->where('employee_id',$employeeId)->where('ended_at',null)->orderBy('id','DESC')->get()->getRowArray();
        if (! $open) {
            $detail = $this->db->table('production_shift_details')->where('id',$detailId)->get()->getRowArray();
            $startedAt = (string)($detail['started_at'] ?? $endedAt);
            $this->db->table('production_operator_histories')->insert([
                'production_shift_detail_id'=>$detailId,'employee_id'=>$employeeId,'started_at'=>$startedAt,'ended_at'=>$endedAt,
                'change_reason'=>$reason,'source'=>'tpms','created_at'=>$startedAt,'updated_at'=>$endedAt,
            ]);
            return;
        }
        $this->db->table('production_operator_histories')->where('id',(int)$open['id'])->update([
            'ended_at'=>$endedAt,'change_reason'=>$reason,'updated_at'=>$endedAt,
        ]);
    }

    private function change(

        string $action,

        array $input

    ): array {

        $id = ProductionRules::integer(

            $input['production_id'] ?? null,

            'production_id',

            1

        );



        $production = $this->session($id);
        if (($production['mode']??'production')==='setting') throw new DomainException('Setting Mode hanya dapat ditutup melalui setting-finish.',409);



        if (

            $production['status'] === 'completed'

            || $production['session_state'] === 'completed'

        ) {

            throw new DomainException(

                'Production sudah selesai. Gunakan event_id yang sama untuk retry.',

                409

            );

        }



        $detail = $this->activeShiftDetail($id);

        if (! $detail) {

            throw new DomainException(

                'Tidak ada shift detail aktif. Mulai shift berikutnya terlebih dahulu.',

                409

            );

        }



        if (

            $action === 'count'

            && (int) $this->device['current_slot_id'] !== (int) $production['slot_id']

        ) {

            throw new DomainException(

                'Assignment perangkat berubah. Hentikan proses pada slot lama.',

                409

            );

        }

        if ($action === 'count') {
            $this->assertCountShiftCurrent($production, $detail);
            $this->assertCounterContract($detail, $input);
        }



        if ($action === 'resume') {

            return $this->resumeProduction($id, $detail, $input);

        }



        if ($action === 'service-complete') {

            return $this->completeService($id, $detail, $input);

        }

        if ($action === 'pause') {

            return $this->pauseProduction($id, $detail, $input);

        }



        if ($action === 'count' && $detail['status'] !== 'running') {

            throw new DomainException(

                'Counter hanya diterima ketika detail shift berstatus running.',

                409

            );

        }



        $counter = ProductionRules::integer(

            $input['counter_total'] ?? null,

            'counter_total'

        );



        if ($counter < (int) $detail['actual_qty']) {

            throw new DomainException(

                'Counter ESP32 lebih kecil dari counter shift pada server. Pulihkan counter ESP32.',

                409

            );

        }



        $delta = $counter - (int) $detail['actual_qty'];



        if ($delta > 0 && $detail['status'] !== 'running') {

            throw new DomainException(

                'Counter tidak boleh bertambah ketika produksi sedang pause/service/menunggu defect.',

                409

            );

        }



        if ($delta > 0) {

            $counterResult = $this->applyCounterDelta($production, $detail, $delta, $input);

            /*
             * Phase 2: gunakan snapshot hasil atomic update, tidak perlu SELECT
             * production + active shift detail lagi hanya untuk refresh counter.
             */
            $production['actual_qty'] = $counterResult['production_actual_qty'];
            $production['good_qty'] = $counterResult['production_good_qty'];
            $detail['actual_qty'] = $counterResult['detail_actual_qty'];
            $detail['good_qty'] = $counterResult['detail_good_qty'];
            $detail['status'] = $counterResult['status'];

        }



        $now = $this->now();



        if ($action === 'alarm') {

            $kind = ProductionRules::alarmKind($input['kind'] ?? null);

            $severity = ProductionRules::alarmSeverity(

                $input['severity'] ?? null,

                $kind

            );

            $effect = ProductionRules::alarmEffect($kind, $severity);



            $toolId = null;

            if ($kind === 'tool_broken') {

                $toolId = ProductionRules::integer(

                    $input['tool_id'] ?? null,

                    'tool_id',

                    1

                );



                $usage = $this->db

                    ->table('production_tool_usages')

                    ->where('production_shift_detail_id', $detail['id'])

                    ->where('tool_id', $toolId)

                    ->get()

                    ->getRowArray();



                if (! $usage) {

                    throw new DomainException('Tool bukan bagian dari shift detail aktif.', 422);

                }



                $this->db

                    ->table('tools')

                    ->where('id', $toolId)

                    ->update([

                        'status' => 'broken',

                        'updated_at' => $now,

                    ]);

            }



            $message = ProductionRules::text(

                $input['message'] ?? null,

                'message',

                500

            );

            if (in_array($effect, ['pause', 'service_stop'], true)) {
                // Cycle yang belum menerima STOP tidak boleh dihitung sebagai part jadi.
                $this->interruptOpenCycle((int) $detail['id'], $now);
            }



            $this->alarm(

                (int) $detail['id'],

                $toolId,

                $kind,

                $severity,

                $effect,

                $message

            );



            $this->refreshDetailState((int) $detail['id']);

            if ($effect === 'pause') {
                /*
                 * Hanya operator_break yang dihitung sebagai Break / Istirahat.
                 * Alarm mesin/tool yang menyebabkan pause bukan waktu istirahat;
                 * untuk chart 3-state saat ini dicatat sebagai Idle/Downtime.
                 */
                $runtimeState = $kind === 'operator_break' ? 'break' : 'idle';
                $this->transitionRuntimeState($id, (int) $detail['id'], $runtimeState, $now);
            } elseif ($effect === 'service_stop') {
                $this->transitionRuntimeState($id, (int) $detail['id'], 'idle', $now);
            }

        }



        if ($action === 'stop') {

            if ($detail['status'] !== 'running' || $this->hasBlockingAlarm((int) $detail['id'])) {

                throw new DomainException(

                    'Alarm harus diselesaikan dan detail harus kembali running sebelum shift dihentikan.',

                    409

                );

            }

            // STOP shift ketika cycle belum selesai membatalkan cycle itu tanpa +1 gross.
            $this->interruptOpenCycle((int) $detail['id'], $now);



            $this->db

                ->table('production_shift_details')

                ->where('id', $detail['id'])

                ->update([

                    'status' => 'awaiting_defects',

                    'ended_at' => $now,

                    'updated_at' => $now,

                ]);

            $this->transitionRuntimeState($id, (int) $detail['id'], 'idle', $now);

        }



        if ($action === 'finish') {

            if (

                ! in_array($detail['status'], ['running', 'awaiting_defects'], true)

                || $this->hasBlockingAlarm((int) $detail['id'])

            ) {

                throw new DomainException(

                    'Alarm harus diselesaikan sebelum data defect dan shift ditutup.',

                    409

                );

            }



            $uid = !empty($detail['operator_employee_id']) ? ProductionRules::uid($input['operator_uid'] ?? null) : null;

            $operator = $this->db

                ->table('employees')

                ->where('id', $detail['operator_employee_id'])

                ->where('rfid_uid', $uid)

                ->where('status', 'active')

                ->get()

                ->getRowArray();



            if (!$operator && !empty($detail['operator_employee_id'])) {

                throw new DomainException(

                    'Penutupan harus memakai RFID operator shift detail.',

                    403

                );

            }



            if (empty($detail['operator_employee_id'])) {
                throw new DomainException('Production aktif tidak memiliki Operator. Periksa data shift detail.',409);
            }
            $detailActual = (int) $detail['actual_qty'];

            $defects = ProductionRules::defects(

                $input['defects'] ?? null,

                $detailActual

            );


            /*
             * Detail completed dapat dibuka kembali pada hari+shift+operator yang
             * sama. Karena itu defect adalah snapshot final detail, bukan append
             * tanpa batas setiap kali FINISH dipanggil ulang.
             */
            $this->db->table('production_nc_details')
                ->where('production_shift_detail_id', $detail['id'])
                ->delete();



            foreach ($defects as $type => $qty) {

                if ($qty <= 0) {

                    continue;

                }



                $this->db->table('production_nc_details')->insert([

                    'production_shift_detail_id' => $detail['id'],

                    'defect_type' => $type,

                    'quantity' => $qty,

                    'created_at' => $now,

                    'updated_at' => $now,

                ]);

            }



            $rejectQty = array_sum($defects);

            $goodQty = $detailActual - $rejectQty;



            $this->closeOperatorHistory(
                (int) $detail['id'],
                (int) $detail['operator_employee_id'],
                $now,
                'shift_completed'
            );

            $this->db

                ->table('production_shift_details')

                ->where('id', $detail['id'])

                ->update([

                    'actual_qty' => $detailActual,

                    'good_qty' => $goodQty,

                    'reject_qty' => $rejectQty,

                    'status' => 'completed',

                    'ended_at' => $detail['ended_at'] ?: $now,

                    'updated_at' => $now,

                ]);



            /* Alarm notify yang tidak memblokir ikut ditutup bersama shift detail. */

            $this->db

                ->table('production_alarms')

                ->where('production_shift_detail_id', $detail['id'])

                ->where('resolved_at', null)

                ->update([

                    'status' => 'resolved',

                    'resolved_at' => $now,

                    'updated_at' => $now,

                ]);



            $this->syncProductionQuantities($id);
            $production = $this->session($id);
            $this->closeRuntimeState((int) $detail['id'], $now);

            if (ProductionQuantity::reached(
                (int) $production['target_qty'],
                (int) ($production['good_qty'] ?? 0)
            )) {

                $this->db

                    ->table('productions')

                    ->where('id', $id)

                    ->update([

                        'status' => 'completed',

                        'session_state' => 'completed',

                        'completed_at' => $now,

                        'updated_at' => $now,

                    ]);

                $this->advanceProductionBatch($id, $now);

            } else {
                /*
                 * FINISH menutup shift detail, BUKAN otomatis menutup job Part.
                 * Production header tetap dapat dipakai shift berikutnya atau
                 * START ulang shift yang sama sampai target GOOD tercapai.
                 */
                $this->db
                    ->table('productions')
                    ->where('id', $id)
                    ->update([
                        'status' => 'running',
                        'session_state' => 'awaiting_next_shift',
                        'completed_at' => null,
                        'updated_at' => $now,
                    ]);
            }

        }



        return $this->state($id);

    }



    /**
     * COUNT hanya valid untuk shift yang sedang aktif menurut jam server.
     */
    private function assertCountShiftCurrent(array $production, array $detail): void
    {
        $shift = ProductionRules::shift(
            $this->db->table('shifts')->get()->getResultArray(),
            $this->clock()
        );

        $workDate = (string) ($detail['work_date'] ?: $production['production_date']);
        if ((int) $detail['shift_id'] !== (int) $shift['id'] || $workDate !== (string) $shift['work_date']) {
            throw new DomainException(
                'COUNT ditolak karena shift/work_date sudah berganti. Tutup shift lama lalu START shift berikutnya.',
                409
            );
        }
    }

    /**
     * Counter contract v2: reset per shift + counter_epoch unik.
     * Event dari shift lama tidak boleh diterapkan ke detail baru.
     */
    private function assertCounterContract(array $detail, array $input): void
    {
        $detailId = ProductionRules::integer(
            $input['production_shift_detail_id'] ?? null,
            'production_shift_detail_id',
            1
        );

        if ($detailId !== (int) $detail['id']) {
            throw new DomainException('COUNT berasal dari production shift detail yang sudah tidak aktif.', 409);
        }

        $epoch = trim((string) ($input['counter_epoch'] ?? ''));
        $expected = trim((string) ($detail['counter_epoch'] ?? ''));

        if ($epoch === '' || $expected === '' || ! hash_equals($expected, $epoch)) {
            throw new DomainException(
                'counter_epoch tidak sesuai. Reset counter ke 0 dan gunakan counter_epoch dari START/STATE terbaru.',
                409
            );
        }
    }

    /**

     * Terapkan delta counter ke header production, detail shift, tools, dan lifetime log.

     */

    private function applyCounterDelta(
        array $production,
        array $detail,
        int $delta,
        array $input
    ): array {
        if ($delta <= 0) {
            return [
                'detail_actual_qty' => (int) $detail['actual_qty'],
                'detail_good_qty' => (int) $detail['good_qty'],
                'production_actual_qty' => (int) $production['actual_qty'],
                'production_good_qty' => (int) ($production['good_qty'] ?? 0),
                'status' => (string) $detail['status'],
                'critical_lifetime' => false,
            ];
        }

        $now = $this->now();
        $usages = $this->db
            ->table('production_tool_usages')
            ->where('production_shift_detail_id', (int) $detail['id'])
            ->orderBy('tool_id')
            ->get()
            ->getResultArray();

        if (! $usages) {
            throw new RuntimeException('Snapshot tools shift detail kosong.');
        }

        /*
         * Phase 2 optimization:
         * Lock seluruh physical tools dalam SATU SELECT ... FOR UPDATE.
         * Sebelumnya satu tool = satu SELECT FOR UPDATE.
         */
        $toolIds = array_values(array_unique(array_map(
            static fn (array $usage): int => (int) $usage['tool_id'],
            $usages
        )));
        sort($toolIds, SORT_NUMERIC);

        $placeholders = implode(',', array_fill(0, count($toolIds), '?'));
        $lockedTools = $this->db
            ->query(
                'SELECT * FROM tools WHERE id IN (' . $placeholders . ') ORDER BY id FOR UPDATE',
                $toolIds
            )
            ->getResultArray();

        $toolsById = [];
        foreach ($lockedTools as $tool) {
            $toolsById[(int) $tool['id']] = $tool;
        }

        if (count($toolsById) !== count($toolIds)) {
            throw new RuntimeException('Satu atau lebih tool snapshot tidak ditemukan.');
        }

        $criticalLifetime = false;
        $toolUpdates = [];
        $usageUpdates = [];
        $lifetimeLogs = [];
        $activityLogger = null;

        /*
         * production_tool_usages normalnya unik per tool. Tetap gunakan current
         * lifetime map agar hasil benar jika suatu tool muncul lebih dari sekali.
         */
        $currentLifetime = [];
        foreach ($toolsById as $toolId => $tool) {
            $currentLifetime[$toolId] = (int) $tool['actual_lifetime'];
        }

        foreach ($usages as $usage) {
            $toolId = (int) $usage['tool_id'];
            $tool = $toolsById[$toolId];
            $beforeLifetime = $currentLifetime[$toolId];
            $after = $beforeLifetime + $delta;

            if ($after > 4294967295) {
                throw new DomainException('Lifetime melebihi kapasitas kolom. Hentikan mesin.', 409);
            }

            $setLifetime = (int) $usage['set_lifetime_snapshot'];
            $beforeRemaining = $setLifetime - $beforeLifetime;
            $remaining = $setLifetime - $after;

            $status = in_array($tool['status'], ['ready', 'warning'], true)
                ? ($remaining <= self::TOOL_CRITICAL_REMAINING
                    ? 'maintenance'
                    : ($remaining <= self::TOOL_WARNING_REMAINING ? 'warning' : 'ready'))
                : $tool['status'];

            $currentLifetime[$toolId] = $after;
            $toolsById[$toolId]['actual_lifetime'] = $after;
            $toolsById[$toolId]['status'] = $status;

            $usageUpdates[] = [
                'id' => (int) $usage['id'],
                'end_lifetime' => $after,
                'quantity_increment' => (int) $usage['quantity_increment'] + $delta,
                'updated_at' => $now,
            ];

            /*
             * Per-part lifetime log sangat cepat membesarkan database.
             * Default Phase 2: nonaktif. History agregat tetap tersedia di
             * production_tool_usages (start/end/quantity_increment).
             * Bisa diaktifkan kembali dari .env untuk kebutuhan audit khusus.
             */
            if ($this->logEveryLifetimeIncrement()) {
                $lifetimeLogs[] = [
                    'tool_id' => $toolId,
                    'event_type' => 'production_increment',
                    'previous_lifetime' => $beforeLifetime,
                    'new_lifetime' => $after,
                    'quantity' => $delta,
                    'reason' => $production['production_code'] . ' / ' . ($input['event_id'] ?? '-'),
                    'occurred_at' => $now,
                    'created_at' => $now,
                ];
            }

            $crossedWarning = $beforeRemaining > self::TOOL_WARNING_REMAINING
                && $remaining <= self::TOOL_WARNING_REMAINING;
            $crossedLast = $beforeRemaining > self::TOOL_LAST_REMAINING
                && $remaining <= self::TOOL_LAST_REMAINING;
            $crossedCritical = $beforeRemaining > self::TOOL_CRITICAL_REMAINING
                && $remaining <= self::TOOL_CRITICAL_REMAINING;

            if ($crossedWarning || $crossedLast || $crossedCritical) {
                $activityLogger ??= new ActivityLogService();
            }

            if ($crossedWarning) {
                $activityLogger->tool(
                    $toolId,
                    'lifetime_warning',
                    [
                        'actual_lifetime' => $beforeLifetime,
                        'remaining_lifetime' => $beforeRemaining,
                        'status' => $tool['status'],
                    ],
                    [
                        'actual_lifetime' => $after,
                        'remaining_lifetime' => $remaining,
                        'status' => $status,
                    ],
                    [
                        'production_id' => (int) $production['id'],
                        'production_shift_detail_id' => (int) $detail['id'],
                        'source' => 'production_api',
                        'actor_type' => 'device',
                        'severity' => 'warning',
                        'previous_lifetime' => $beforeLifetime,
                        'new_lifetime' => $after,
                        'quantity' => $delta,
                        'message' => sprintf(
                            '%s mencapai batas warning. Sisa lifetime %d dari set lifetime %d.',
                            $tool['code'],
                            $remaining,
                            $setLifetime
                        ),
                        'metadata' => [
                            'event_id' => $input['event_id'] ?? null,
                            'production_code' => $production['production_code'],
                            'set_lifetime' => $setLifetime,
                            'remaining_lifetime' => $remaining,
                        ],
                    ]
                );
            }

            if ($crossedCritical) {
                $activityLogger->tool(
                    $toolId,
                    'lifetime_critical',
                    [
                        'actual_lifetime' => $beforeLifetime,
                        'remaining_lifetime' => $beforeRemaining,
                        'status' => $tool['status'],
                    ],
                    [
                        'actual_lifetime' => $after,
                        'remaining_lifetime' => $remaining,
                        'status' => $status,
                    ],
                    [
                        'production_id' => (int) $production['id'],
                        'production_shift_detail_id' => (int) $detail['id'],
                        'source' => 'production_api',
                        'actor_type' => 'device',
                        'severity' => 'critical',
                        'previous_lifetime' => $beforeLifetime,
                        'new_lifetime' => $after,
                        'quantity' => $delta,
                        'message' => sprintf(
                            '%s mencapai critical lifetime. Sisa lifetime %d dari set lifetime %d.',
                            $tool['code'],
                            $remaining,
                            $setLifetime
                        ),
                        'metadata' => [
                            'event_id' => $input['event_id'] ?? null,
                            'production_code' => $production['production_code'],
                            'set_lifetime' => $setLifetime,
                            'remaining_lifetime' => $remaining,
                        ],
                    ]
                );
            }

            if ($crossedLast && ! $crossedCritical) {
                $activityLogger->tool(
                    $toolId,
                    'lifetime_last',
                    [
                        'actual_lifetime' => $beforeLifetime,
                        'remaining_lifetime' => $beforeRemaining,
                        'status' => $tool['status'],
                    ],
                    [
                        'actual_lifetime' => $after,
                        'remaining_lifetime' => $remaining,
                        'status' => $status,
                    ],
                    [
                        'production_id' => (int) $production['id'],
                        'production_shift_detail_id' => (int) $detail['id'],
                        'source' => 'production_api',
                        'actor_type' => 'device',
                        'severity' => 'danger',
                        'previous_lifetime' => $beforeLifetime,
                        'new_lifetime' => $after,
                        'quantity' => $delta,
                        'message' => sprintf(
                            '%s tersisa 1 lifetime sebelum critical. Sisa lifetime %d dari set lifetime %d.',
                            $tool['code'],
                            $remaining,
                            $setLifetime
                        ),
                        'metadata' => [
                            'event_id' => $input['event_id'] ?? null,
                            'production_code' => $production['production_code'],
                            'set_lifetime' => $setLifetime,
                            'remaining_lifetime' => $remaining,
                        ],
                    ]
                );
            }

            /*
             * Alarm hanya ditulis ketika threshold DILEWATI, bukan di setiap part
             * selama tool sudah berada pada band warning/critical.
             */
            if ($crossedCritical) {
                $this->alarm(
                    (int) $detail['id'],
                    $toolId,
                    'lifetime',
                    'critical',
                    'service_stop',
                    "{$tool['code']}: sisa lifetime {$remaining}."
                );
            } elseif ($crossedLast) {
                $this->alarm(
                    (int) $detail['id'],
                    $toolId,
                    'lifetime',
                    'danger',
                    'notify',
                    "{$tool['code']}: sisa lifetime {$remaining}. Segera siapkan maintenance."
                );
            } elseif ($crossedWarning) {
                $this->alarm(
                    (int) $detail['id'],
                    $toolId,
                    'lifetime',
                    'warning',
                    'notify',
                    "{$tool['code']}: sisa lifetime {$remaining}."
                );
            }

            if ($remaining <= self::TOOL_CRITICAL_REMAINING) {
                $criticalLifetime = true;
            }
        }

        /* Satu UPDATE BATCH untuk seluruh tool. */
        foreach ($toolsById as $toolId => $tool) {
            $toolUpdates[] = [
                'id' => (int) $toolId,
                'actual_lifetime' => (int) $currentLifetime[$toolId],
                'status' => (string) $tool['status'],
                'updated_at' => $now,
            ];
        }

        if ($toolUpdates) {
            $this->db->table('tools')->updateBatch($toolUpdates, 'id');
        }
        if ($usageUpdates) {
            $this->db->table('production_tool_usages')->updateBatch($usageUpdates, 'id');
        }
        if ($lifetimeLogs) {
            $this->db->table('tool_lifetime_logs')->insertBatch($lifetimeLogs);
        }

        /*
         * Atomic counter: tidak lagi SELECT SUM seluruh shift pada setiap part.
         * Reject shift lama sudah tersimpan pada header; satu gross part baru
         * dengan reject yang tidak berubah selalu menambah good sebanyak delta.
         */
        $detailStatus = $criticalLifetime ? 'service_required' : (string) $detail['status'];

        $this->db->query(
            'UPDATE productions '
            . 'SET actual_qty = actual_qty + ?, good_qty = good_qty + ?, updated_at = ? '
            . 'WHERE id = ?',
            [$delta, $delta, $now, (int) $production['id']]
        );

        $this->db->query(
            'UPDATE production_shift_details '
            . 'SET actual_qty = actual_qty + ?, good_qty = good_qty + ?, status = ?, updated_at = ? '
            . 'WHERE id = ?',
            [$delta, $delta, $detailStatus, $now, (int) $detail['id']]
        );

        $detailActual = (int) $detail['actual_qty'] + $delta;
        $detailGood = (int) $detail['good_qty'] + $delta;
        $productionActual = (int) $production['actual_qty'] + $delta;
        $productionGood = (int) ($production['good_qty'] ?? 0) + $delta;

        if ($criticalLifetime) {
            $this->transitionRuntimeState(
                (int) $production['id'],
                (int) $detail['id'],
                'idle',
                $now
            );
        }

        return [
            'detail_actual_qty' => $detailActual,
            'detail_good_qty' => $detailGood,
            'production_actual_qty' => $productionActual,
            'production_good_qty' => $productionGood,
            'status' => $detailStatus,
            'critical_lifetime' => $criticalLifetime,
        ];
    }

    /**
     * Apakah setiap kenaikan lifetime perlu disimpan sebagai row log terpisah.
     * Default false untuk production skala besar; summary shift tetap tersimpan
     * di production_tool_usages.
     */
    private function logEveryLifetimeIncrement(): bool
    {
        return filter_var(
            env('TPMS_LOG_EVERY_LIFETIME_INCREMENT', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Sinkronkan header production dari seluruh shift detail.
     * actual_qty pada schema lama diperlakukan sebagai GROSS quantity.
     */
    private function syncProductionQuantities(int $productionId): array
    {
        $totals = $this->db
            ->table('production_shift_details')
            ->select(
                'COALESCE(SUM(actual_qty), 0) gross_qty, '
                . 'COALESCE(SUM(good_qty), 0) good_qty, '
                . 'COALESCE(SUM(reject_qty), 0) reject_qty',
                false
            )
            ->where('production_id', $productionId)
            ->get()
            ->getRowArray() ?: [];

        $payload = [
            'actual_qty' => (int) ($totals['gross_qty'] ?? 0),
            'good_qty' => (int) ($totals['good_qty'] ?? 0),
            'reject_qty' => (int) ($totals['reject_qty'] ?? 0),
            'updated_at' => $this->now(),
        ];

        $this->db->table('productions')->where('id', $productionId)->update($payload);

        return $payload;
    }

    /**

     * Resume hanya untuk alarm non-critical/effect pause.

     */

    private function resumeProduction(int $productionId, array $detail, array $input): array

    {

        if ($detail['status'] !== 'paused') {

            throw new DomainException('Detail shift tidak sedang paused.', 409);

        }



        $alarmId = ProductionRules::integer($input['alarm_id'] ?? null, 'alarm_id', 1);

        $alarm = $this->db

            ->table('production_alarms')

            ->where('id', $alarmId)

            ->where('production_shift_detail_id', $detail['id'])

            ->where('resolved_at', null)

            ->get()

            ->getRowArray();



        if (! $alarm || $alarm['effect'] !== 'pause') {

            throw new DomainException(

                'Alarm tidak ditemukan, sudah selesai, atau bukan alarm yang dapat di-resume.',

                409

            );

        }



        $now = $this->now();

        $this->db

            ->table('production_alarms')

            ->where('id', $alarmId)

            ->update([

                'status' => 'resolved',

                'resolved_at' => $now,

                'resolution_notes' => isset($input['message'])

                    ? ProductionRules::optionalText($input['message'], 'message', 500)

                    : null,

                'updated_at' => $now,

            ]);



        $this->refreshDetailState((int) $detail['id']);
        $this->transitionRuntimeState(
            $productionId,
            (int) $detail['id'],
            'production',
            $now
        );

        return $this->state($productionId);

    }



    /**

     * Critical alarm hanya dapat dibuka setelah ESP mengirim service-complete.

     */

    private function completeService(int $productionId, array $detail, array $input): array

    {

        if ($detail['status'] !== 'service_required') {

            throw new DomainException('Detail shift tidak sedang menunggu service.', 409);

        }

        $pic = $this->pic($input['pic_uid'] ?? null);



        $alarmId = ProductionRules::integer($input['alarm_id'] ?? null, 'alarm_id', 1);

        $alarm = $this->db

            ->table('production_alarms')

            ->where('id', $alarmId)

            ->where('production_shift_detail_id', $detail['id'])

            ->where('resolved_at', null)

            ->get()

            ->getRowArray();



        if (! $alarm || $alarm['effect'] !== 'service_stop') {

            throw new DomainException(

                'Critical alarm tidak ditemukan atau sudah selesai.',

                409

            );

        }



        if (! empty($alarm['tool_id'])) {

            $usage = $this->db

                ->table('production_tool_usages')

                ->where('production_shift_detail_id', $detail['id'])

                ->where('tool_id', $alarm['tool_id'])

                ->get()

                ->getRowArray();



            $tool = $this->db

                ->table('tools')

                ->where('id', $alarm['tool_id'])

                ->get()

                ->getRowArray();



            if (! $usage || ! $tool) {

                throw new RuntimeException('Tool critical alarm tidak ditemukan.');

            }



            $remaining = (int) $usage['set_lifetime_snapshot'] - (int) $tool['actual_lifetime'];

            if ($remaining <= self::TOOL_CRITICAL_REMAINING) {

                throw new DomainException(

                    'Service belum dapat ditutup karena lifetime Tool masih habis. Reset/replace Tool atau Change Edge terlebih dahulu.',

                    409

                );

            }



            /* Tool broken dianggap sudah diperbaiki saat ESP mengonfirmasi service selesai. */

            if ($alarm['kind'] === 'tool_broken') {

                $this->db

                    ->table('tools')

                    ->where('id', $tool['id'])

                    ->update([

                        'status' => $remaining <= self::TOOL_WARNING_REMAINING ? 'warning' : 'ready',

                        'updated_at' => $this->now(),

                    ]);

            }

        }



        $now = $this->now();

        $this->db

            ->table('production_alarms')

            ->where('id', $alarmId)

            ->update([

                'status' => 'resolved',

                'resolved_at' => $now,

                'resolution_notes' => trim(
                    'PIC ' . $pic['name'] . ' (' . $pic['nik'] . '). '
                    . (isset($input['message'])
                        ? (ProductionRules::optionalText($input['message'], 'message', 350) ?? '')
                        : '')
                ),

                'updated_at' => $now,

            ]);

        if (! empty($alarm['tool_id'])) {
            (new ActivityLogService())->tool(
                (int) $alarm['tool_id'],
                'service_completed',
                null,
                null,
                [
                    'production_id' => $productionId,
                    'production_shift_detail_id' => (int) $detail['id'],
                    'source' => 'tpms',
                    'actor_type' => 'employee',
                    'severity' => 'info',
                    'message' => 'Critical service dikonfirmasi selesai oleh PIC ' . $pic['name'] . ' (' . $pic['nik'] . ').',
                    'metadata' => [
                        'pic_employee_id' => (int) $pic['id'],
                        'pic_nik' => $pic['nik'],
                        'pic_name' => $pic['name'],
                        'alarm_id' => (int) $alarmId,
                    ],
                ]
            );
        }



        $this->refreshDetailState((int) $detail['id']);
        $this->transitionRuntimeState(
            $productionId,
            (int) $detail['id'],
            'production',
            $now
        );

        return $this->state($productionId);

    }



    /**

     * Create/update alarm scoped ke production_shift_details.

     */

    private function alarm(

        int $detailId,

        ?int $toolId,

        string $kind,

        string $severity,

        string $effect,

        string $message

    ): int {

        $builder = $this->db

            ->table('production_alarms')

            ->where('production_shift_detail_id', $detailId)

            ->where('kind', $kind)

            ->where('resolved_at', null);



        if ($toolId === null) {

            $builder->where('tool_id', null);

        } else {

            $builder->where('tool_id', $toolId);

        }



        $old = $builder->get()->getRowArray();

        $now = $this->now();



        if ($old) {

            $this->db

                ->table('production_alarms')

                ->where('id', $old['id'])

                ->update([

                    'severity' => $severity,

                    'effect' => $effect,

                    'status' => 'active',

                    'message' => $message,

                    'updated_at' => $now,

                ]);



            return (int) $old['id'];

        }



        $this->db->table('production_alarms')->insert([

            'production_shift_detail_id' => $detailId,

            'tool_id' => $toolId,

            'kind' => $kind,

            'severity' => $severity,

            'effect' => $effect,

            'status' => 'active',

            'message' => $message,

            'created_at' => $now,

            'updated_at' => $now,

        ]);



        return (int) $this->db->insertID();

    }





    /**

     * Sinkronkan status operasional TPMS dan simpan audit trail hanya saat status berubah.

     *

     * Status ini adalah status runtime (run/idle/alarm/offline), bukan master status

     * pada tabel machines. machine_logs menjadi sumber histori runtime machine.

     */

    private function syncDeviceOperationalStatus(

        string $newStatus,

        string $source = 'tpms',

        ?string $message = null

    ): void {

        $newStatus = strtolower(trim($newStatus));



        if (! in_array($newStatus, ['online', 'run', 'idle', 'paused', 'alarm', 'offline', 'setting'], true)) {

            throw new RuntimeException('Status operasional TPMS tidak valid.');

        }



        $deviceId = (int) $this->device['id'];

        $oldStatus = strtolower(trim((string) ($this->device['device_status'] ?? 'offline')));
        $statusChanged = $oldStatus !== $newStatus;
        $touchDue = $this->deviceConnectivityTouchDue();

        /*
         * Phase 3: don't UPDATE the device row just because /state was polled.
         * Status changes are persisted immediately; liveness is only refreshed
         * when its throttle window expires.
         */
        if (! $statusChanged && ! $touchDue) {
            return;
        }

        $now = $this->now();
        $update = [];
        if ($statusChanged) {
            $update['device_status'] = $newStatus;
        }
        if ($touchDue) {
            $update['connection_status'] = 'reachable';
            $update['last_seen_at'] = $now;
        }

        if ($update !== []) {
            $this->db
                ->table('tpms_devices')
                ->where('id', $deviceId)
                ->update($update);
        }

        if ($statusChanged) {
            $this->device['device_status'] = $newStatus;
        }
        if ($touchDue) {
            $this->device['connection_status'] = 'reachable';
            $this->device['last_seen_at'] = $now;
        }

        if (! $statusChanged) {
            return;
        }



        $logger = new ActivityLogService();

        $slotId = ! empty($this->device['current_slot_id'])

            ? (int) $this->device['current_slot_id']

            : null;



        $slot = null;

        if ($slotId !== null) {

            $slot = $this->db

                ->table('production_slots')

                ->where('id', $slotId)

                ->get()

                ->getRowArray();

        }



        $severity = $newStatus === 'alarm'

            ? 'warning'

            : ($newStatus === 'offline' ? 'critical' : 'info');



        $logger->tpms(

            $deviceId,

            'status_changed',

            ['status' => $oldStatus],

            ['status' => $newStatus],

            [

                'slot_id' => $slotId,

                'machine_id' => isset($slot['machine_id']) && $slot['machine_id'] !== null

                    ? (int) $slot['machine_id']

                    : null,

                'source' => $source,

                'actor_type' => 'device',

                'severity' => $severity,

                'message' => $message

                    ?? sprintf('Status TPMS berubah dari %s menjadi %s.', $oldStatus, $newStatus),

                'metadata' => [

                    'old_status' => $oldStatus,

                    'new_status' => $newStatus,

                ],

            ]

        );



        if ($slot && ! empty($slot['machine_id'])) {

            $logger->machine(

                (int) $slot['machine_id'],

                'operational_status_changed',

                ['status' => $oldStatus],

                ['status' => $newStatus],

                [

                    'slot_id' => $slotId,

                    'tpms_device_id' => $deviceId,

                    'source' => $source,

                    'actor_type' => 'device',

                    'severity' => $severity,

                    'previous_status' => $oldStatus,

                    'new_status' => $newStatus,

                    'message' => $message

                        ?? sprintf(

                            'Status operasional machine berubah dari %s menjadi %s berdasarkan TPMS.',

                            $oldStatus,

                            $newStatus

                        ),

                    'metadata' => [

                        'mac_address' => $this->device['mac_address'] ?? null,

                        'old_status' => $oldStatus,

                        'new_status' => $newStatus,

                    ],

                ]

            );

        }

    }



    /**

     * Ambil state production berdasarkan device.

     */

    private function stateForDevice(

        array $input

    ): array {

        if (isset($input['production_id'])) {

            return $this->state(

                ProductionRules::integer(

                    $input['production_id'],

                    'production_id',

                    1

                ),
                null,
                null,
                false

            );

        }



        /*
         * Runtime state device harus mengikuti shift detail yang benar-benar aktif.
         * Header production yang belum mencapai target tetapi shift terakhir sudah
         * completed tidak dianggap sebagai production yang sedang berjalan.
         */
        $detail = $this->db

            ->table('production_shift_details d')

            ->select('d.*')

            ->join(

                'productions p',

                'p.id = d.production_id'

            )

            ->where(

                'p.device_id',

                $this->device['id']

            )

            ->whereIn(

                'p.status',

                ['running', 'active']

            )

            ->whereIn(

                'd.status',

                [

                    'running',

                    'paused',

                    'service_required',

                    'awaiting_defects',
                    'operator_change_required',
                    'setting',

                ]

            )

            ->orderBy(

                'd.id',

                'DESC'

            )

            ->get()

            ->getRowArray();



        if (! $detail) {

            // Phase 3: /state is read-only for operational status.




            return [

                'production_id' => null,

                'should_stop' => true,

                'state' => 'idle',

                'execution_state' => 'idle',

                'command' => 'stop',

                'can_resume' => false,

                'requires_service' => false,

                'server_time' => $this->now(),

            ];

        }



        $production = $this->session((int) $detail['production_id']);

        return $this->state((int) $production['id'], $production, $detail, false);

    }



    /**

     * Current production state.

     */

    private function state(
        int $id,
        ?array $preloadedProduction = null,
        ?array $preloadedDetail = null,
        bool $persistDeviceStatus = true
    ): array

    {

        $production = $preloadedProduction ?? $this->session($id);
        if (($production['mode']??'production')==='setting') return $this->settingState($production, $persistDeviceStatus);

        $detail = $preloadedDetail ?? ($this->activeShiftDetail($id) ?: $this->latestShiftDetail($id));



        if (! $detail) {

            throw new RuntimeException('Detail production tidak ditemukan.');

        }



        $alarms = $this->db

            ->table('production_alarms')

            ->where('production_shift_detail_id', $detail['id'])

            ->where('resolved_at', null)

            ->orderBy('id', 'DESC')

            ->get()

            ->getResultArray();



        $tools = $this->db

            ->table('production_tool_usages u')

            ->select('u.*, t.code, t.name, t.cutting_edge, t.current_edge, t.actual_lifetime, t.status')

            ->join('tools t', 't.id = u.tool_id')

            ->where('u.production_shift_detail_id', $detail['id'])

            ->orderBy('u.tool_id')

            ->get()

            ->getResultArray();



        $productionCompleted =

            $production['status'] === 'completed'

            || $production['session_state'] === 'completed';



        $requiresService = false;

        $hasPauseAlarm = false;
        $requiresOperatorReplacement = $detail['status'] === 'operator_change_required';
        $operatorSickResolved = false;
        $planningChangeRequired = false;
        $operatorReplacementReady = false;

        if ($requiresOperatorReplacement) {
            $latestOperatorSick = $this->db->table('production_alarms')
                ->where('production_shift_detail_id', (int) $detail['id'])
                ->where('kind', 'operator_sick')
                ->where('effect', 'operator_stop')
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();
            $operatorSickResolved = (bool) $latestOperatorSick && ! empty($latestOperatorSick['resolved_at']);

            if ($operatorSickResolved) {
                $plannedOperator = $this->db->table('employee_shift_assignments')
                    ->select('employee_id')
                    ->where('machine_id', (int) $production['machine_id'])
                    ->where('assignment_date', (string) $detail['work_date'])
                    ->where('shift_id', (int) $detail['shift_id'])
                    ->where('assignment_role', 'operator')
                    ->where('is_active', 1)
                    ->orderBy('id', 'DESC')
                    ->get()
                    ->getRowArray();
                $plannedEmployeeId = (int) ($plannedOperator['employee_id'] ?? 0);
                $operatorReplacementReady = $plannedEmployeeId > 0
                    && $plannedEmployeeId !== (int) ($detail['operator_employee_id'] ?? 0);
                $planningChangeRequired = ! $operatorReplacementReady;
            }
        }

        foreach ($alarms as $alarm) {

            if (($alarm['effect'] ?? null) === 'service_stop') {

                $requiresService = true;

            }

            if (($alarm['effect'] ?? null) === 'pause') {

                $hasPauseAlarm = true;

            }
            if (($alarm['effect'] ?? null) === 'operator_stop') {
                $requiresOperatorReplacement = true;
            }

        }



        foreach ($tools as &$tool) {

            $tool['remaining'] =

                (int) $tool['set_lifetime_snapshot'] - (int) $tool['actual_lifetime'];



            if (

                in_array($detail['status'], ['running', 'paused', 'service_required'], true)

                && (

                    $tool['remaining'] <= 0

                    || ! in_array($tool['status'], ['ready', 'warning'], true)

                )

            ) {

                $requiresService = true;

            }

        }

        unset($tool);



        if ($productionCompleted) {

            $executionState = 'completed';

        } elseif ($detail['status'] === 'completed') {

            $executionState = 'awaiting_next_shift';

        } elseif ($requiresOperatorReplacement) {

            $executionState = 'operator_change_required';

        } elseif ($requiresService) {

            $executionState = 'service_required';

        } elseif ($detail['status'] === 'paused' || $hasPauseAlarm) {

            $executionState = 'paused';

        } else {

            $executionState = $detail['status'];

        }



        try {

            $shift = ProductionRules::shift(

                $this->db->table('shifts')->get()->getResultArray(),

                $this->clock()

            );



            $detailWorkDate = $detail['work_date'] ?: $production['production_date'];

            $shiftEnded =

                (int) $shift['id'] !== (int) $detail['shift_id']

                || $shift['work_date'] !== $detailWorkDate;

        } catch (DomainException $e) {

            $shiftEnded = true;

        }



        $slotChanged =

            (int) $this->device['current_slot_id'] !== (int) $production['slot_id'];

        $targetReached = ProductionQuantity::reached(
            (int) $production['target_qty'],
            (int) ($production['good_qty'] ?? 0)
        );

        $targetDurationDays = (int) ($production['target_duration_days'] ?? 1);
        $shiftsPerDay = (int) ($production['shifts_per_day'] ?? 1);
        $plannedShiftCount = ProductionTargetPlanner::totalPlannedShifts(
            $targetDurationDays,
            $shiftsPerDay
        );
        $usedShiftCount = $this->db
            ->table('production_shift_details')
            ->where('production_id', $id)
            ->countAllResults();
        $remainingPlannedShiftCount = ProductionTargetPlanner::remainingPlannedShifts(
            $targetDurationDays,
            $shiftsPerDay,
            $usedShiftCount
        );
        if (($production['plan_basis']??'legacy')==='cycle_7h') {
            $plannedShiftCount=1; $remainingPlannedShiftCount=0;
        }
        $shiftTargetReached = ProductionQuantity::reached(
            (int) $detail['target_qty'],
            (int) $detail['good_qty']
        );



        $command = 'stop';

        if (

            $executionState === 'running'

            && ! $shiftEnded

            && ! $slotChanged

            && ! $targetReached

            && ! $requiresService

        ) {

            $command = 'run';

        } elseif ($executionState === 'paused') {

            $command = 'pause';

        }



        $canResume = $executionState === 'paused' && ! $requiresService;

        $shouldStop = $command !== 'run';

        $nextAction = ProductionFlowPolicy::nextAction(
            $executionState,
            $productionCompleted,
            $slotChanged,
            $shiftEnded,
            $targetReached,
            $requiresService
        );



        if ($productionCompleted || $executionState === 'awaiting_next_shift' || $executionState === 'awaiting_defects') {

            $deviceStatus = 'idle';

        } elseif ($executionState === 'paused') {

            $deviceStatus = 'paused';

        } elseif ($executionState === 'setting') {

            $deviceStatus = 'setting';

        } elseif (in_array($executionState, ['service_required', 'operator_change_required'], true)) {

            $deviceStatus = 'alarm';

        } else {

            $deviceStatus = $command === 'run' ? 'run' : 'idle';

        }



        if ($persistDeviceStatus) {
            $this->syncDeviceOperationalStatus(

                $deviceStatus,

                'production_state',

                sprintf(

                    'Status operasional mengikuti production %s (%s).',

                    $production['production_code'],

                    $executionState

                )

            );
        }

        /*
         * START already snapshots process timing into productions. Normal polling
         * should use that snapshot instead of re-reading part_processes forever.
         * Query the master only for legacy rows with missing snapshots.
         */
        $hasTimingSnapshot =
            array_key_exists('standard_machine_time_ms', $production)
            && $production['standard_machine_time_ms'] !== null
            && array_key_exists('standard_loading_time_ms', $production)
            && $production['standard_loading_time_ms'] !== null;
        $machineTimeTargetMs = (int) ($production['standard_machine_time_ms'] ?? 0);
        $loadingTimeTargetMs = (int) ($production['standard_loading_time_ms'] ?? 0);
        $processConfig = null;
        if (! $hasTimingSnapshot && ! empty($production['part_process_id'])) {
            $processConfig = $this->db->table('part_processes')
                ->where('id', (int) $production['part_process_id'])
                ->get()
                ->getRowArray();
            $machineTimeTargetMs = (int) ($processConfig['machine_time_target_ms'] ?? 0);
            $loadingTimeTargetMs = (int) ($processConfig['loading_time_target_ms'] ?? 0);
        }

        $openCycle = $this->db->tableExists('production_cycles')
            ? $this->db->table('production_cycles')
                ->where('production_shift_detail_id', (int) $detail['id'])
                ->where('status', 'started')
                ->where('machine_stopped_at', null)
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray()
            : null;



        return [

            'production_id' => $id,
            'mode' => $production['mode']??'production',
            'plan_qty' => (int)$production['target_qty'],
            'plan_per_shift' => (int)$detail['target_qty'],
            'daily_plan' => (int)$detail['target_qty']*(int)$production['shifts_per_day'],
            'flow_contract' => 'part_position_machine_tools_v2',

            'production_code' => $production['production_code'],
            'production_batch_id' => isset($production['production_batch_id']) ? (int) $production['production_batch_id'] : null,
            'part_process_id' => isset($production['part_process_id']) ? (int) $production['part_process_id'] : null,
            'process_no' => (int) ($production['process_no'] ?? 1),
            'process_name' => $production['process_name_snapshot'] ?? 'Single Process',
            'process_mode' => $production['process_mode_snapshot'] ?? 'auto',

            'production_state' => $productionCompleted ? 'completed' : 'active',

            'production_status' => $production['status'],

            'production_shift_detail_id' => (int) $detail['id'],

            'state' => $executionState,

            'execution_state' => $executionState,

            'detail_status' => $detail['status'],
            'setting_active' => $executionState === 'setting',
            'setting_configured' => $executionState === 'setting' ? ! empty($detail['setting_configured_at']) : false,
            'setting_scope' => $executionState === 'setting' ? 'production_interrupt' : null,
            'reused_shift_detail' => $executionState === 'setting',

            'command' => $command,

            'can_resume' => $canResume,

            'requires_service' => $requiresService,

            // actual_qty dipertahankan sebagai alias kompatibilitas untuk gross_qty.
            'actual_qty' => (int) $production['actual_qty'],
            'gross_qty' => (int) $production['actual_qty'],
            'good_qty' => (int) ($production['good_qty'] ?? 0),
            'reject_qty' => (int) ($production['reject_qty'] ?? 0),
            'target_qty' => (int) $production['target_qty'],
            'target_duration_days' => $targetDurationDays,
            'shifts_per_day' => $shiftsPerDay,
            'planned_shift_count' => $plannedShiftCount,
            'used_shift_count' => $usedShiftCount,
            'remaining_planned_shift_count' => $remainingPlannedShiftCount,
            'plan_overdue' => ($production['plan_basis']??'legacy')==='legacy' && ! $targetReached && $remainingPlannedShiftCount === 0,
            'remaining_good_qty' => ProductionQuantity::remaining(
                (int) $production['target_qty'],
                (int) ($production['good_qty'] ?? 0)
            ),

            'shift_actual_qty' => (int) $detail['actual_qty'],
            'shift_gross_qty' => (int) $detail['actual_qty'],
            'shift_good_qty' => (int) $detail['good_qty'],
            'shift_reject_qty' => (int) $detail['reject_qty'],
            'shift_target_qty' => (int) $detail['target_qty'],
            'shift_target_reached' => $shiftTargetReached,
            'shift_remaining_good_qty' => ProductionQuantity::remaining(
                (int) $detail['target_qty'],
                (int) $detail['good_qty']
            ),

            'quality_final' => $detail['status'] === 'completed',

            'should_stop' => $shouldStop,
            'next_action' => $nextAction,

            'target_reached' => $targetReached,
            'counter_contract' => 'reset_per_shift_v2',
            'cycle_contract' => 'two_trigger_v1',
            'machine_time_target_ms' => $machineTimeTargetMs,
            'loading_time_target_ms' => $loadingTimeTargetMs,
            'open_cycle_id' => $openCycle ? (int) $openCycle['id'] : null,
            'open_cycle_sequence_no' => $openCycle ? (int) $openCycle['sequence_no'] : null,
            'counter_epoch' => $detail['counter_epoch'] ?? null,
            'counter_expected_total' => (int) $detail['actual_qty'],

            'shift_ended' => $shiftEnded,

            'slot_changed' => $slotChanged,
            'operator' => $this->employeeSummary((int) ($detail['operator_employee_id'] ?? 0)),
            'operator_replacement_required' => $requiresOperatorReplacement,
            'operator_sick_resolved' => $operatorSickResolved,
            'planning_change_required' => $planningChangeRequired,
            'operator_replacement_ready' => $operatorReplacementReady,

            'tools' => $tools,

            'alarms' => $alarms,

            'server_time' => $this->now(),

        ];

    }



}


