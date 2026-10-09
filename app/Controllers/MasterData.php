<?php

namespace App\Controllers;

use App\Models\CustomerModel;
use App\Models\EmployeeModel;
use App\Models\MaterialModel;
use App\Models\PartModel;
use App\Models\ProductionSlotModel;
use App\Models\RackToolModel;
use App\Models\ShiftModel;
use App\Models\ToolModel;

class MasterData extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
    }

    private function render(string $view, string $title, array $data = [])
    {
        return view(
            "master-data/{$view}",
            array_merge(['title' => $title], $data)
        );
    }

    public function machines()
    {
        $rows = $this->db->table('production_slots s')
            ->select('s.*, m.name machine, m.serial_number')
            ->join('machines m', 'm.id = s.machine_id', 'left')
            ->orderBy('s.slot_no')
            ->get()
            ->getResultArray();

        $slots = [];

        foreach ($rows as $row) {
            $tpms = $this->db->table('tpms_devices')
                ->where('current_slot_id', $row['id'])
                ->orderBy('last_seen_at', 'DESC')
                ->get()
                ->getRowArray();

            $slots[] = [
                'id'         => (int) $row['slot_no'],
                'db_id'      => (int) $row['id'],
                'machine_id' => $row['machine_id'],
                'machine'    => $row['machine'],
                'serial'     => $row['serial_number'] ?? '-',
                'status'     => $row['machine_id'] ? $row['status'] : 'empty',
                'tpms'       => $tpms['ip_address'] ?? null,
                'production' => null,
            ];
        }

        $machineRows = $this->db->table('machines m')
            ->select('m.*, s.id slot_id, s.slot_no')
            ->join('production_slots s', 's.machine_id = m.id', 'left')
            ->where('m.disposed_at', null)
            ->orderBy('m.name')
            ->get()
            ->getResultArray();

        $machineToolRows = $this->db->table('machine_tools mt')
            ->select('mt.machine_id, t.id, t.code, t.name, t.actual_lifetime, t.default_lifetime, t.status, tt.code tool_type_code')
            ->join('tools t', 't.id = mt.tool_id')
            ->join('tool_types tt', 'tt.id = t.tool_type_id')
            ->orderBy('t.code')
            ->get()
            ->getResultArray();
        $toolsByMachine = [];
        foreach ($machineToolRows as $toolRow) {
            $toolsByMachine[(int) $toolRow['machine_id']][] = $toolRow;
        }

        $machines = array_map(static fn($machine) => [
            'id'         => (int) $machine['id'],
            'code'       => $machine['code'],
            'registration_code'=>$machine['registration_code'],
            'name'       => $machine['name'],
            'serial'     => $machine['serial_number'] ?: '-',
            'serial_raw' => $machine['serial_number'] ?? '',
            'year'       => $machine['year'] ?: '-',
            'slot'       => $machine['slot_no'] !== null
                ? (int) $machine['slot_no']
                : null,
            'slot_id'    => $machine['slot_id'] !== null
                ? (int) $machine['slot_id']
                : null,
            'tools'      => $toolsByMachine[(int) $machine['id']] ?? [],
        ], $machineRows);

        $toolPool = $this->db->table('tools t')
            ->select('t.id, t.code, t.name, t.actual_lifetime, t.default_lifetime, t.status, tt.code tool_type_code, mt.machine_id, m.code assigned_machine_code')
            ->join('tool_types tt', 'tt.id = t.tool_type_id')
            ->join('machine_tools mt', 'mt.tool_id = t.id', 'left')
            ->join('machines m', 'm.id = mt.machine_id', 'left')
            ->orderBy('tt.code')
            ->orderBy('t.code')
            ->get()
            ->getResultArray();

        return $this->render(
            'machines',
            'Machines',
            compact('slots', 'machines', 'toolPool')
        );
    }

    public function tpms()
    {
        $window = max(1, (int) env('TPMS_ONLINE_WINDOW_SECONDS', 90));
        $onlineExpr = "(d.last_seen_at IS NOT NULL AND d.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$window} SECOND))";
        $statusExpr = "(CASE WHEN {$onlineExpr} THEN COALESCE(NULLIF(d.device_status, ''), 'online') ELSE 'offline' END)";
        $credentialExpr = "(CASE WHEN d.token IS NULL OR d.token = '' THEN 0 ELSE 1 END)";
        $builder = $this->db->table('tpms_devices d')
            ->select('d.*, s.slot_no, m.name machine')
            ->join('production_slots s', 's.id = d.current_slot_id', 'left')
            ->join('machines m', 'm.id = s.machine_id', 'left');
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('d.mac_address', $q)->orLike('d.ip_address', $q)
                ->orLike('d.firmware_version', $q)->orLike('d.hmi_version', $q)
                ->orLike('m.name', $q)->orLike('s.slot_no', $q)->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'machine' => 'm.name', 'firmware' => 'd.firmware_version',
            'network' => 'd.ip_address', 'status' => $statusExpr,
            'connection' => 'd.connection_status', 'last_seen' => 'd.last_seen_at',
            'credential' => $credentialExpr,
        ], 'last_seen', 'd.id', '', 'desc');
        $devices = [];
        foreach ($pagination['rows'] as $device) {
            $seen = !empty($device['last_seen_at']) ? strtotime($device['last_seen_at']) : false;
            $online = $seen !== false && $seen >= time() - $window;
            $devices[] = [
                'id' => (int) $device['id'], 'slot' => $device['slot_no'] ?: '-',
                'machine' => $device['machine'] ?: '-', 'fw' => $device['firmware_version'] ?: '-',
                'hmi' => $device['hmi_version'] ?: '-', 'ip' => $device['ip_address'] ?: '-',
                'mac' => $device['mac_address'],
                'status' => $online ? ($device['device_status'] ?: 'online') : 'offline',
                'connection' => $device['connection_status'] ?: 'unknown',
                'updated' => $device['last_seen_at'] ?: '-',
                'credential' => empty($device['token']) ? 'Not provisioned' : 'Provisioned',
                'last_connection_check_at' => $device['last_connection_check_at'],
            ];
        }
        $tpmsStats = $this->db->table('tpms_devices d')
            ->select("COUNT(*) total, SUM({$onlineExpr}) online, "
                . "SUM(COALESCE(d.firmware_version, '') <> '' OR COALESCE(d.hmi_version, '') <> '') versioned", false)
            ->get()->getRowArray();
        return $this->render('tpms', 'TPMS Management', compact('devices', 'pagination', 'tpmsStats'));
    }

    public function deviceAssignments()
    {
        $moveSummary = "(SELECT tpms_device_id, COUNT(*) total "
            . "FROM tpms_assignment_histories WHERE event_type = 'move' GROUP BY tpms_device_id) mc";
        $builder = $this->db->table('tpms_devices d')
            ->select('d.*, s.slot_no, m.name machine, COALESCE(mc.total, 0) move_count', false)
            ->join('production_slots s', 's.id = d.current_slot_id', 'left')
            ->join('machines m', 'm.id = s.machine_id', 'left')
            ->join($moveSummary, 'mc.tpms_device_id = d.id', 'left', false);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('d.mac_address', $q)->orLike('d.ip_address', $q)
                ->orLike('m.name', $q)->orLike('s.slot_no', $q)->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'mac' => 'd.mac_address',
            'credential' => "(CASE WHEN d.token IS NULL OR d.token = '' THEN 0 ELSE 1 END)",
            'installed' => 's.slot_no', 'status' => 'd.device_status',
            'ip' => 'd.ip_address', 'moves' => 'COALESCE(mc.total, 0)',
            'last' => 'd.last_seen_at',
        ], 'mac', 'd.id');
        $devices = array_map(static fn (array $d) => [
            'id' => (int) $d['id'], 'mac' => $d['mac_address'],
            'credential' => empty($d['token']) ? 'Not provisioned' : 'Provisioned',
            'slot' => $d['slot_no'] ?: '-', 'machine' => $d['machine'] ?: '-',
            'status' => $d['device_status'] ?: 'offline', 'ip' => $d['ip_address'] ?: '-',
            'moves' => (int) $d['move_count'], 'last' => $d['last_seen_at'] ?: '—',
        ], $pagination['rows']);
        // Transfer Log punya pagination kedua, agar tidak saling mengganti halaman.
        $logsBuilder = $this->db->table('tpms_assignment_histories h')
            ->select('h.*, d.mac_address, fs.slot_no from_slot, fm.name from_machine, '
                . 'ts.slot_no to_slot, tm.name to_machine')
            ->join('tpms_devices d', 'd.id = h.tpms_device_id')
            ->join('production_slots fs', 'fs.id = h.from_slot_id', 'left')
            ->join('machines fm', 'fm.id = fs.machine_id', 'left')
            ->join('production_slots ts', 'ts.id = h.to_slot_id', 'left')
            ->join('machines tm', 'tm.id = ts.machine_id', 'left');
        $date = (string) $this->request->getGet('log_date');
        $parsedLogDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date !== '' && $parsedLogDate instanceof \DateTimeImmutable && $parsedLogDate->format('Y-m-d') === $date) {
            $logsBuilder->where('DATE(h.happened_at) = ' . $this->db->escape($date), null, false);
        }
        $logsPagination = \App\Libraries\TableListing::paginate($logsBuilder, $this->request, [
            'time' => 'h.happened_at', 'mac' => 'd.mac_address',
            'type' => 'h.event_type', 'credential' => 'd.mac_address',
            'movement' => 'fs.slot_no', 'ip' => 'h.ip_address', 'notes' => 'h.notes',
        ], 'time', 'h.id', 'log_', 'desc');
        $logs = array_map(static fn (array $h) => [
            'time' => $h['happened_at'], 'mac' => $h['mac_address'],
            'type' => ucfirst($h['event_type']), 'credential' => 'Hidden',
            'from' => ($h['from_slot'] ?: '-') . ' / ' . ($h['from_machine'] ?: '-'),
            'to' => ($h['to_slot'] ?: '-') . ' / ' . ($h['to_machine'] ?: '-'),
            'ip' => $h['ip_address'] ?: '-', 'notes' => $h['notes'] ?: '-',
        ], $logsPagination['rows']);
        return $this->render('device-assignments', 'Device Assignments', compact(
            'devices', 'logs', 'pagination', 'logsPagination'
        ));
    }

    public function customers()
    {
        $partSummary = '(SELECT customer_id, COUNT(*) total_parts, '
            . "SUM(status = 'active') active_parts FROM parts GROUP BY customer_id) pc";
        $builder = $this->db->table('customers c')
            ->select('c.*, COALESCE(pc.total_parts, 0) total_parts, COALESCE(pc.active_parts, 0) active_parts', false)
            ->join($partSummary, 'pc.customer_id = c.id', 'left', false);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('c.name', $q)->orLike('c.code', $q)->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'customer' => 'c.name', 'code' => 'c.code',
            'parts' => 'COALESCE(pc.total_parts, 0)',
            'active' => 'COALESCE(pc.active_parts, 0)',
        ], 'customer', 'c.id');
        // Hanya ambil detail modal untuk customer pada page ini.
        $ids = array_map('intval', array_column($pagination['rows'], 'id'));
        $partsByCustomer = [];
        if ($ids !== []) {
            $partRows = $this->db->table('parts p')
                ->select('p.customer_id, p.part_number, p.name, p.status, m.code material_code, m.name material_name')
                ->join('materials m', 'm.id = p.material_id', 'left')
                ->whereIn('p.customer_id', $ids)->orderBy('p.part_number')->get()->getResultArray();
            foreach ($partRows as $part) {
                $partsByCustomer[(int) $part['customer_id']][] = $part;
            }
        }
        $customers = [];
        foreach ($pagination['rows'] as $c) {
            $customers[] = [
                'id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'],
                'parts' => (int) $c['total_parts'], 'active' => (int) $c['active_parts'],
                'production' => 0, 'status' => $c['status'],
                'parts_list' => $partsByCustomer[(int) $c['id']] ?? [],
            ];
        }
        $customerStats = $this->db->table('customers')->select('COUNT(*) total', false)->get()->getRowArray();
        $partStats = $this->db->table('parts')
            ->select("COUNT(*) total, SUM(status = 'active') active", false)->get()->getRowArray();
        return $this->render('customers', 'Customer Management', compact(
            'customers', 'pagination', 'customerStats', 'partStats'
        ));
    }

    public function materials()
    {
        $partSummary = '(SELECT material_id, COUNT(*) total_parts FROM parts '
            . 'WHERE material_id IS NOT NULL GROUP BY material_id) pc';
        $builder = $this->db->table('materials m')
            ->select('m.*, COALESCE(pc.total_parts, 0) total_parts', false)
            ->join($partSummary, 'pc.material_id = m.id', 'left', false);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('m.code', $q)->orLike('m.name', $q)->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'code' => 'm.code', 'name' => 'm.name',
            'parts' => 'COALESCE(pc.total_parts, 0)', 'created' => 'm.created_at',
        ], 'code', 'm.id');
        $ids = array_map('intval', array_column($pagination['rows'], 'id'));
        $partsByMaterial = [];
        if ($ids !== []) {
            $partRows = $this->db->table('parts p')
                ->select('p.material_id, p.part_number, p.name, c.name customer_name')
                ->join('customers c', 'c.id = p.customer_id', 'left')
                ->whereIn('p.material_id', $ids)->orderBy('p.part_number')->get()->getResultArray();
            foreach ($partRows as $part) {
                $partsByMaterial[(int) $part['material_id']][] = $part;
            }
        }
        $materials = [];
        foreach ($pagination['rows'] as $m) {
            $created = !empty($m['created_at']) ? strtotime($m['created_at']) : false;
            $materials[] = [
                'id' => (int) $m['id'], 'code' => $m['code'], 'name' => $m['name'],
                'parts' => (int) $m['total_parts'],
                'created' => $created !== false ? date('d M Y', $created) : '-',
                'parts_list' => $partsByMaterial[(int) $m['id']] ?? [],
            ];
        }
        $materialStats = $this->db->table('materials m')
            ->select('COUNT(*) total, SUM(CASE WHEN pc.total_parts > 0 THEN 1 ELSE 0 END) used', false)
            ->join($partSummary, 'pc.material_id = m.id', 'left', false)
            ->get()->getRowArray();
        return $this->render('materials', 'Material Management', compact(
            'materials', 'pagination', 'materialStats'
        ));
    }

    public function parts()
    {
        $processStatsSql = "(SELECT part_id, COUNT(*) active_count, "
            . "MIN(process_name) sort_process_name, "
            . "MIN(CASE WHEN machine_time_target_ms + loading_time_target_ms > 0 "
            . "THEN FLOOR(25200000 / (machine_time_target_ms + loading_time_target_ms)) ELSE NULL END) min_plan, "
            . "MAX(CASE WHEN machine_time_target_ms + loading_time_target_ms BETWEEN 1 AND 25200000 "
            . "THEN 1 ELSE 0 END) has_plan "
            . "FROM part_processes WHERE status = 'active' GROUP BY part_id) ps";
        $builder = $this->db->table('parts p')
            ->select('p.*, c.name customer_name, m.name material_name, '
                . 'COALESCE(ps.active_count, 0) active_process_count, ps.sort_process_name, ps.min_plan, ps.has_plan', false)
            ->join('customers c', 'c.id = p.customer_id')
            ->join('materials m', 'm.id = p.material_id', 'left')
            ->join($processStatsSql, 'ps.part_id = p.id', 'left', false);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('p.part_number', $q)->orLike('p.name', $q)
                ->orLike('c.name', $q)
                ->orWhere('EXISTS (SELECT 1 FROM part_processes x WHERE x.part_id = p.id AND x.status = \'active\' AND x.process_name LIKE ' . $this->db->escape('%' . $q . '%') . ')', null, false)
                ->groupEnd();
        }
        $process = strtolower(trim((string) $this->request->getGet('process')));
        if (in_array($process, ['auto', 'manual', 'grinding'], true)) {
            $condition = $process === 'grinding'
                ? 'x.is_next_grinding = 1'
                : 'x.process_mode = ' . $this->db->escape($process) . ' AND x.is_next_grinding = 0';
            $builder->where("EXISTS (SELECT 1 FROM part_processes x WHERE x.part_id = p.id AND x.status = 'active' AND {$condition})", null, false);
        }
        $plan = strtolower(trim((string) $this->request->getGet('plan')));
        if ($plan === 'configured') {
            $builder->where('ps.has_plan', 1);
        } elseif ($plan === 'unconfigured') {
            $builder->groupStart()->where('ps.has_plan IS NULL', null, false)
                ->orWhere('ps.has_plan', 0)->groupEnd();
        }
        // Sort processes berdasarkan nama proses aktif secara alfabetis (MIN nama);
        // Sort plan berdasarkan nilai plan terkecil di antara proses aktif.
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'part' => 'p.part_number', 'customer' => 'c.name',
            'process' => 'ps.sort_process_name', 'plan' => 'ps.min_plan',
        ], 'part', 'p.id');
        $rows = $pagination['rows'];
        $ids = array_map('intval', array_column($rows, 'id'));
        $processesByPart = [];
        $totalsByProcess = [];
        if ($ids !== []) {
            $processRows = $this->db->table('part_processes')
                ->whereIn('part_id', $ids)->where('status', 'active')
                ->orderBy('part_id')->orderBy('process_no')->get()->getResultArray();
            $processIds = array_map('intval', array_column($processRows, 'id'));
            $reqByProcess = [];
            if ($processIds !== []) {
                $requirements = $this->db->table('process_tool_requirements r')
                    ->select('r.*, tt.code, tt.name tool_type_name')
                    ->join('tool_types tt', 'tt.id = r.tool_type_id')
                    ->whereIn('r.part_process_id', $processIds)
                    ->orderBy('r.position')->orderBy('tt.code')->get()->getResultArray();
                foreach ($requirements as $req) {
                    $reqByProcess[(int) $req['part_process_id']][] = $req;
                }
                $qtyRows = $this->db->table('productions')
                    ->select('part_process_id, SUM(good_qty) total_good, SUM(reject_qty) total_nc', false)
                    ->where('mode', 'production')->whereIn('part_process_id', $processIds)
                    ->groupBy('part_process_id')->get()->getResultArray();
                foreach ($qtyRows as $qty) {
                    $totalsByProcess[(int) $qty['part_process_id']] = [
                        'good' => (int) $qty['total_good'], 'nc' => (int) $qty['total_nc'],
                    ];
                }
            }
            foreach ($processRows as $pr) {
                $processId = (int) $pr['id'];
                $partId = (int) $pr['part_id'];
                $pr['requirements'] = $reqByProcess[$processId] ?? [];
                $qty = $totalsByProcess[$processId] ?? ['good' => 0, 'nc' => 0];
                $pr['production_summary'] = [
                    'good' => $qty['good'], 'nc' => $qty['nc'],
                    'total' => $qty['good'] + $qty['nc'],
                ];
                $processesByPart[$partId][] = $pr;
            }
        }
        foreach ($rows as &$part) {
            $part['processes'] = $processesByPart[(int) $part['id']] ?? [];
            foreach ($part['processes'] as &$pr) {
                try {
                    $pr['plan'] = \App\Services\ProcessPlan::calculate(
                        (int) $pr['machine_time_target_ms'],
                        (int) $pr['loading_time_target_ms'],
                        (int) $part['shifts_per_day']
                    );
                } catch (\DomainException $e) {
                    $pr['plan'] = null;
                }
            }
            unset($pr);
        }
        unset($part);
        $partStats = $this->db->table('parts')->select('COUNT(*) total', false)->get()->getRowArray();
        $processStats = $this->db->table('part_processes')
            ->select("COUNT(*) total, "
                . "SUM(is_next_grinding = 1) grinding, "
                . "SUM(is_next_grinding = 0 AND process_mode = 'auto') auto, "
                . "SUM(is_next_grinding = 0 AND process_mode = 'manual') manual, "
                . "SUM(machine_time_target_ms > 0 AND loading_time_target_ms >= 0 "
                . "AND machine_time_target_ms + loading_time_target_ms <= 25200000) configured", false)
            ->where('status', 'active')->get()->getRowArray();
        return $this->render('parts', 'Parts Management', [
            'parts' => $rows, 'pagination' => $pagination,
            'partStats' => $partStats, 'processStats' => $processStats,
            'customers' => (new \App\Models\CustomerModel())->orderBy('name')->findAll(),
            'materials' => (new \App\Models\MaterialModel())->orderBy('name')->findAll(),
            'toolTypes' => $this->db->table('tool_types')->where('status', 'active')
                ->orderBy('code')->get()->getResultArray(),
        ]);
    }

    public function rackTools()
    {
        $partStats = '(SELECT rack_tool_id, COUNT(*) part_count FROM rack_tool_parts GROUP BY rack_tool_id) rp';
        $toolStats = '(SELECT rack_tool_id, COUNT(*) tool_count FROM rack_tool_tools GROUP BY rack_tool_id) rt';
        $builder = $this->db->table('rack_tools r')
            ->select('r.*, COALESCE(rp.part_count, 0) part_count, COALESCE(rt.tool_count, 0) tool_count', false)
            ->join($partStats, 'rp.rack_tool_id = r.id', 'left', false)
            ->join($toolStats, 'rt.rack_tool_id = r.id', 'left', false);
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $like = $this->db->escape('%' . $q . '%');
            $builder->groupStart()->like('r.code', $q)->orLike('r.name', $q)
                ->orLike('r.uid', $q)->orLike('r.location', $q)
                ->orWhere("EXISTS (SELECT 1 FROM rack_tool_parts rp2 JOIN parts p2 ON p2.id = rp2.part_id "
                    . "WHERE rp2.rack_tool_id = r.id AND (p2.part_number LIKE {$like} OR p2.name LIKE {$like}))", null, false)
                ->orWhere("EXISTS (SELECT 1 FROM rack_tool_tools rt2 JOIN tools t2 ON t2.id = rt2.tool_id "
                    . "WHERE rt2.rack_tool_id = r.id AND (t2.code LIKE {$like} OR t2.name LIKE {$like}))", null, false)
                ->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'rack' => 'r.code', 'parts' => 'COALESCE(rp.part_count, 0)',
            'tools' => 'COALESCE(rt.tool_count, 0)',
            'location' => 'r.location', 'status' => 'r.status',
        ], 'rack', 'r.id');
        $racks = [];
        foreach ($pagination['rows'] as $rack) {
            $rackParts = $this->db->table('rack_tool_parts rp')
                ->select('p.id, p.part_number, p.name')->join('parts p', 'p.id = rp.part_id')
                ->where('rp.rack_tool_id', $rack['id'])->get()->getResultArray();
            $rackTools = $this->db->table('rack_tool_tools rt')
                ->select('t.id, t.code, t.name, t.actual_lifetime, t.default_lifetime, t.cutting_edge, t.current_edge, rt.position')
                ->join('tools t', 't.id = rt.tool_id')->where('rt.rack_tool_id', $rack['id'])
                ->orderBy('rt.position')->get()->getResultArray();
            $racks[] = [
                'id' => (int) $rack['id'], 'code' => $rack['code'],
                'name' => $rack['name'], 'uid' => $rack['uid'],
                'parts' => array_column($rackParts, 'part_number'), 'part_rows' => $rackParts,
                'tools' => array_column($rackTools, 'code'), 'tool_rows' => $rackTools,
                'location' => $rack['location'], 'status' => $rack['status'],
            ];
        }
        // Dipakai oleh dropdown/penugasan di modal; sengaja tetap berupa lookup lengkap.
        $parts = (new \App\Models\PartModel())->orderBy('part_number')->findAll();
        $tools = (new \App\Models\ToolModel())->orderBy('code')->findAll();
        $rackStats = $this->db->table('rack_tools')->select('COUNT(*) total', false)->get()->getRowArray();
        return $this->render('rack-tools', 'Rack Tools Management', compact(
            'racks', 'parts', 'tools', 'pagination', 'rackStats'
        ));
    }

    public function tools()
    {
        $q = trim((string) $this->request->getGet('q'));
        $builder = $this->db->table('tools t')
            ->select('t.*, tt.code tool_type_code, mt.machine_id, m.code machine_code, m.name machine_name')
            ->join('tool_types tt', 'tt.id = t.tool_type_id')
            ->join('machine_tools mt', 'mt.tool_id = t.id', 'left')
            ->join('machines m', 'm.id = mt.machine_id', 'left');
        if ($q !== '') {
            $builder->groupStart()->like('t.code', $q)->orLike('t.name', $q)
                ->orLike('t.holder', $q)->orLike('tt.code', $q)
                ->orLike('m.code', $q)->orLike('m.name', $q)->groupEnd();
        }
        $status = strtolower(trim((string) $this->request->getGet('status')));
        if (in_array($status, ['ready', 'warning', 'broken', 'maintenance', 'inactive'], true)) {
            $builder->where('t.status', $status);
        }
        $assignment = strtolower(trim((string) $this->request->getGet('assignment')));
        if ($assignment === 'assigned') {
            $builder->where('mt.machine_id IS NOT NULL', null, false);
        } elseif ($assignment === 'unassigned') {
            $builder->where('mt.machine_id IS NULL', null, false);
        }
        $type = trim((string) $this->request->getGet('type'));
        if ($type !== '') {
            $builder->where('tt.code', $type);
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'tool' => 't.code', 'type' => 'tt.code', 'edge' => 't.current_edge',
            'lifetime' => 't.actual_lifetime', 'machine' => 'm.code',
            'status' => 't.status',
        ], 'tool', 't.id');
        $tools = $pagination['rows'];
        // Seluruh inventory (bukan hanya 10 baris di halaman saat ini).
        $toolStats = $this->db->table('tools t')
            ->select("COUNT(*) total, SUM(t.status = 'ready') ready, "
                . "SUM(t.status = 'warning') warning, "
                . "SUM(t.status IN ('broken','maintenance','inactive')) problem, "
                . "SUM(EXISTS (SELECT 1 FROM machine_tools mt2 WHERE mt2.tool_id = t.id)) assigned", false)
            ->get()->getRowArray();
        return $this->render('tools', 'Physical Tools', [
            'tools' => $tools, 'pagination' => $pagination, 'toolStats' => $toolStats,
            'toolTypes' => $this->db->table('tool_types')->where('status', 'active')->orderBy('code')->get()->getResultArray(),
            'issues' => $this->db->table('tpms_refactor_issues')->orderBy('id')->get()->getResultArray(),
        ]);
    }

    public function employees()
    {
        $builder = $this->db->table('employees e')->select('e.*');
        $q = trim((string) $this->request->getGet('q'));
        if ($q !== '') {
            $builder->groupStart()->like('e.name', $q)->orLike('e.nik', $q)
                ->orLike('e.rfid_uid', $q)->orLike('e.department', $q)
                ->orLike('e.role', $q)->groupEnd();
        }
        $pagination = \App\Libraries\TableListing::paginate($builder, $this->request, [
            'employee' => 'e.name', 'nik' => 'e.nik',
            'department' => 'e.department', 'role' => 'e.role',
            'rfid' => 'e.rfid_uid', 'status' => 'e.status',
        ], 'employee', 'e.id');
        $employees = $pagination['rows'];
        $employeeIdsOnPage = array_map('intval', array_column($employees, 'id'));
        $employeeStats = $this->db->table('employees')
            ->select("COUNT(*) total, SUM(status = 'active') active, SUM(rfid_uid IS NOT NULL AND rfid_uid != '') rfid", false)
            ->get()->getRowArray();

        $employeeId = (int) ($this->request->getGet('employee_id') ?: 0);

        try {
            $start = \App\Services\ProductionRules::date(
                $this->request->getGet('start') ?: date('Y-m-01')
            );

            $end = \App\Services\ProductionRules::date(
                $this->request->getGet('end') ?: date('Y-m-t')
            );

            if ($start > $end) {
                throw new \DomainException('Tanggal awal melebihi akhir.');
            }
        } catch (\DomainException $e) {
            return redirect()
                ->to(site_url('master-data/employees'))
                ->with('error', $e->getMessage());
        }

        /*
         * ==============================================================
         * EMPLOYEE SCHEDULE
         * ==============================================================
         * Tetap dipakai oleh tabel Employee Schedule di bawah halaman.
         */
        $query = $this->db
            ->table('employee_shift_assignments a')
            ->select(
                'a.assignment_date, a.employee_id, e.name employee_name, e.nik, '
                    . 's.id shift_id, s.name shift_name, s.start_time, s.end_time, '
                    . 'm.id machine_id, m.name machine_name'
            )
            ->join('employees e', 'e.id = a.employee_id')
            ->join('shifts s', 's.id = a.shift_id')
            ->join('machines m', 'm.id = a.machine_id', 'left')
            ->where('a.is_active', 1)
            ->where('a.assignment_date >=', $start)
            ->where('a.assignment_date <=', $end);

        $query->whereIn('a.employee_id', $employeeIdsOnPage ?: [-1]);

        if ($employeeId > 0) {
            $query->where('a.employee_id', $employeeId);
        }

        $schedules = $query
            ->orderBy('a.assignment_date')
            ->orderBy('s.code')
            ->get()
            ->getResultArray();

        /*
         * ==============================================================
         * ATTENDANCE / ABSENSI PER HARI, STATUS PER SHIFT
         * ==============================================================
         *
         * Aturan:
         * 1. Planning = employee_shift_assignments.
         * 2. Aktual   = production_shift_details.operator_employee_id.
         * 3. Status "Masuk" dicek per employee + tanggal + shift_id.
         *
         * Contoh:
         * 28 Sep 2026
         * - Shift 1 direncanakan + ada production detail employee yang sama
         *   pada tanggal dan shift yang sama => Masuk.
         * - Shift 2 direncanakan tetapi tidak ada production detail =>
         *   Tidak Masuk / Belum Masuk / Terjadwal tergantung tanggal.
         *
         * Tampilan tetap dikelompokkan per hari supaya satu tanggal hanya
         * muncul satu kali, tetapi di dalamnya terdapat status tiap shift.
         */

        $planningRows = $this->db
            ->table('employee_shift_assignments a')
            ->select(
                'a.employee_id, a.assignment_date, a.shift_id, a.machine_id, a.assignment_role, '
                    . 's.code shift_code, s.name shift_name, '
                    . 's.start_time, s.end_time, '
                    . 'm.code machine_code, m.name machine_name'
            )
            ->join('shifts s', 's.id = a.shift_id')
            ->join('machines m', 'm.id = a.machine_id', 'left')
            ->where('a.is_active', 1)
            ->where('a.assignment_date >=', $start)
            ->where('a.assignment_date <=', $end)
            ->whereIn('a.employee_id', $employeeIdsOnPage ?: [-1])
            ->orderBy('a.assignment_date', 'DESC')
            ->orderBy('s.code', 'ASC')
            ->get()
            ->getResultArray();

        /*
         * work_date adalah tanggal aktual production shift.
         * Fallback ke production_date dipakai untuk data lama.
         */
        $productionRows = $this->db
            ->table('production_shift_details d')
            ->select(
                'd.id detail_id, d.operator_employee_id, d.unit_head_employee_id, '
                    . 'COALESCE(d.work_date, p.production_date) attendance_date, '
                    . 'd.shift_id, d.started_at, d.ended_at, d.status detail_status, '
                    . 'd.actual_qty, d.good_qty, d.reject_qty, '
                    . 'p.id production_id, p.production_code, '
                    . 'p.machine_id, m.code machine_code, m.name machine_name, '
                    . 's.code shift_code, s.name shift_name',
                false
            )
            ->join('productions p', 'p.id = d.production_id')
            ->join('shifts s', 's.id = d.shift_id', 'left')
            ->join('machines m', 'm.id = p.machine_id', 'left')
            ->where(
                'COALESCE(d.work_date, p.production_date) >= ' . $this->db->escape($start),
                null,
                false
            )
            ->where(
                'COALESCE(d.work_date, p.production_date) <= ' . $this->db->escape($end),
                null,
                false
            )
            ->groupStart()
                ->whereIn('d.operator_employee_id', $employeeIdsOnPage ?: [-1])
                ->orWhereIn('d.unit_head_employee_id', $employeeIdsOnPage ?: [-1])
            ->groupEnd()
            ->orderBy('attendance_date', 'DESC')
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getResultArray();

        /*
         * Aktual production dikelompokkan PER:
         * employee + tanggal + shift.
         *
         * Ini perbedaan utamanya dari implementasi sebelumnya yang hanya
         * employee + tanggal sehingga cukup hadir di satu shift membuat satu
         * hari dianggap masuk seluruhnya.
         */
        $actualByEmployeeDateShift = [];

        foreach ($productionRows as $row) {
            $dateKey = (string) ($row['attendance_date'] ?? '');
            $shiftId = (int) ($row['shift_id'] ?? 0);

            if ($dateKey === '' || $shiftId <= 0) {
                continue;
            }

            $roleEmployees = [
                'operator' => (int) ($row['operator_employee_id'] ?? 0),
                'unit_head' => (int) ($row['unit_head_employee_id'] ?? 0),
            ];

            foreach ($roleEmployees as $attendanceRole => $employeeKey) {
                if ($employeeKey <= 0) {
                    continue;
                }

                $key = $employeeKey . '|' . $dateKey . '|' . $shiftId . '|' . $attendanceRole;

                if (! isset($actualByEmployeeDateShift[$key])) {
                    $actualByEmployeeDateShift[$key] = [
                        'details' => [],
                        'shift_id' => $shiftId,
                        'role' => $attendanceRole,
                        'shift_code' => $row['shift_code'] ?? null,
                        'shift_name' => $row['shift_name'] ?? null,
                        'machine_names' => [],
                        'production_codes' => [],
                        'actual_qty' => 0,
                        'good_qty' => 0,
                        'reject_qty' => 0,
                        'first_started_at' => null,
                        'last_ended_at' => null,
                    ];
                }

                $actualByEmployeeDateShift[$key]['details'][] = $row;

                if (! empty($row['machine_name'])) {
                    $actualByEmployeeDateShift[$key]['machine_names'][$row['machine_name']] = true;
                }

                if (! empty($row['production_code'])) {
                    $actualByEmployeeDateShift[$key]['production_codes'][$row['production_code']] = true;
                }

                $actualByEmployeeDateShift[$key]['actual_qty'] += (int) ($row['actual_qty'] ?? 0);
                $actualByEmployeeDateShift[$key]['good_qty'] += (int) ($row['good_qty'] ?? 0);
                $actualByEmployeeDateShift[$key]['reject_qty'] += (int) ($row['reject_qty'] ?? 0);

                $startedAt = $row['started_at'] ?? null;
                if ($startedAt && (
                    $actualByEmployeeDateShift[$key]['first_started_at'] === null
                    || $startedAt < $actualByEmployeeDateShift[$key]['first_started_at']
                )) {
                    $actualByEmployeeDateShift[$key]['first_started_at'] = $startedAt;
                }

                $endedAt = $row['ended_at'] ?? null;
                if ($endedAt && (
                    $actualByEmployeeDateShift[$key]['last_ended_at'] === null
                    || $endedAt > $actualByEmployeeDateShift[$key]['last_ended_at']
                )) {
                    $actualByEmployeeDateShift[$key]['last_ended_at'] = $endedAt;
                }
            }
        }

        /*
         * Planning dikelompokkan per employee + tanggal.
         * Di dalam setiap tanggal terdapat daftar shift terencana.
         */
        $plannedByEmployeeDate = [];

        foreach ($planningRows as $row) {
            $employeeKey = (int) ($row['employee_id'] ?? 0);
            $dateKey = (string) ($row['assignment_date'] ?? '');
            $shiftId = (int) ($row['shift_id'] ?? 0);

            if ($employeeKey <= 0 || $dateKey === '' || $shiftId <= 0) {
                continue;
            }

            $dayKey = $employeeKey . '|' . $dateKey;

            if (! isset($plannedByEmployeeDate[$dayKey])) {
                $plannedByEmployeeDate[$dayKey] = [
                    'employee_id' => $employeeKey,
                    'date' => $dateKey,
                    'shifts' => [],
                ];
            }

            $assignmentRole = strtolower(trim((string) ($row['assignment_role'] ?? 'operator')));
            if ($assignmentRole === 'kanit') {
                $assignmentRole = 'unit_head';
            }

            if (! isset($plannedByEmployeeDate[$dayKey]['shifts'][$shiftId])) {
                $plannedByEmployeeDate[$dayKey]['shifts'][$shiftId] = [
                    'shift_id' => $shiftId,
                    'shift_code' => $row['shift_code'] ?? null,
                    'shift_name' => $row['shift_name'] ?? ('Shift ' . $shiftId),
                    'assignment_role' => $assignmentRole,
                    'machine_names' => [],
                    'hours' => [],
                ];
            }

            if (! empty($row['machine_name'])) {
                $plannedByEmployeeDate[$dayKey]['shifts'][$shiftId]['machine_names'][$row['machine_name']] = true;
            }

            if (! empty($row['start_time']) || ! empty($row['end_time'])) {
                $hourLabel = ($row['start_time'] ?: '-') . '–' . ($row['end_time'] ?: '-');
                $plannedByEmployeeDate[$dayKey]['shifts'][$shiftId]['hours'][$hourLabel] = true;
            }
        }

        $attendanceByEmployee = [];
        $attendanceStatsByEmployee = [];
        $today = date('Y-m-d');

        foreach ($plannedByEmployeeDate as $planning) {
            $employeeKey = (int) $planning['employee_id'];
            $dateKey = (string) $planning['date'];
            $shiftRows = [];

            foreach ($planning['shifts'] as $shiftId => $plannedShift) {
                $attendanceRole = (string) ($plannedShift['assignment_role'] ?? 'operator');
                $actualKey = $employeeKey . '|' . $dateKey . '|' . (int) $shiftId . '|' . $attendanceRole;
                $actual = $actualByEmployeeDateShift[$actualKey] ?? null;
                $isPresent = $actual !== null;

                if ($isPresent) {
                    $statusCode = 'present';
                    $statusLabel = 'Masuk';
                } elseif ($dateKey > $today) {
                    $statusCode = 'scheduled';
                    $statusLabel = 'Terjadwal';
                } elseif ($dateKey === $today) {
                    $statusCode = 'pending';
                    $statusLabel = 'Belum Masuk';
                } else {
                    $statusCode = 'absent';
                    $statusLabel = 'Tidak Masuk';
                }

                $shiftRows[] = [
                    'shift_id' => (int) $shiftId,
                    'shift_code' => $plannedShift['shift_code'],
                    'shift_name' => $plannedShift['shift_name'] ?: ('Shift ' . $shiftId),
                    'assignment_role' => $attendanceRole,
                    'status_code' => $statusCode,
                    'status_label' => $statusLabel,

                    'planned_machines' => array_keys($plannedShift['machine_names']),
                    'planned_hours' => array_keys($plannedShift['hours']),

                    'actual_machines' => $actual ? array_keys($actual['machine_names']) : [],
                    'production_codes' => $actual ? array_keys($actual['production_codes']) : [],
                    'production_count' => $actual ? count($actual['production_codes']) : 0,
                    'shift_detail_count' => $actual ? count($actual['details']) : 0,
                    'actual_qty' => (int) ($actual['actual_qty'] ?? 0),
                    'good_qty' => (int) ($actual['good_qty'] ?? 0),
                    'reject_qty' => (int) ($actual['reject_qty'] ?? 0),
                    'first_started_at' => $actual['first_started_at'] ?? null,
                    'last_ended_at' => $actual['last_ended_at'] ?? null,
                ];
            }

            usort($shiftRows, static function (array $a, array $b): int {
                $aCode = (string) ($a['shift_code'] ?? '');
                $bCode = (string) ($b['shift_code'] ?? '');

                if ($aCode !== $bCode) {
                    return strnatcasecmp($aCode, $bCode);
                }

                return $a['shift_id'] <=> $b['shift_id'];
            });

            $attendanceByEmployee[$employeeKey][] = [
                'date' => $dateKey,
                'shifts' => $shiftRows,
            ];
        }

        /*
 * Urutkan hari terbaru -> terlama.
 * Attendance detail maksimal 15 hari terbaru per employee.
 */
        foreach ($attendanceByEmployee as &$attendanceRows) {
            usort($attendanceRows, static function (array $a, array $b): int {
                return strcmp($b['date'], $a['date']);
            });
        }
        unset($attendanceRows);

        /* Simpan seluruh data sebelum detail dipotong 15 hari. */
        $attendanceAllByEmployee = $attendanceByEmployee;

        /*
        * Data yang dikirim ke tabel detail hanya 15 hari terbaru.
        */
        foreach ($attendanceByEmployee as &$attendanceRows) {
            $attendanceRows = array_slice(
                $attendanceRows,
                0,
                15
            );
        }
        unset($attendanceRows);
        /*
         * Summary dihitung per SHIFT, sedangkan jumlah hari tetap disediakan.
         * Future schedule dan shift hari ini yang belum dimulai tidak dimasukkan
         * ke denominator attendance rate.
         */
        foreach ($employees as $employee) {
            $id = (int) $employee['id'];
            $rows = $attendanceAllByEmployee[$id] ?? [];

            $plannedDays = count($rows);
            $plannedShifts = 0;
            $presentShifts = 0;
            $absentShifts = 0;
            $scheduledShifts = 0;
            $pendingShifts = 0;

            foreach ($rows as $day) {
                foreach ($day['shifts'] as $shift) {
                    $plannedShifts++;

                    match ($shift['status_code']) {
                        'present' => $presentShifts++,
                        'absent' => $absentShifts++,
                        'scheduled' => $scheduledShifts++,
                        'pending' => $pendingShifts++,
                        default => null,
                    };
                }
            }

            $closedShifts = $presentShifts + $absentShifts;
            $attendanceRate = $closedShifts > 0
                ? round(($presentShifts / $closedShifts) * 100, 1)
                : 0.0;

            $attendanceStatsByEmployee[$id] = [
                'planned_days' => $plannedDays,
                'planned_shifts' => $plannedShifts,
                'present_shifts' => $presentShifts,
                'absent_shifts' => $absentShifts,
                'scheduled_shifts' => $scheduledShifts,
                'pending_shifts' => $pendingShifts,
                'attendance_rate' => $attendanceRate,
            ];
        }

        return $this->render(
            'employees',
            'Employees',
            compact(
                'employees',
                'employeeId',
                'start',
                'end',
                'schedules',
                'attendanceByEmployee',
                'attendanceStatsByEmployee',
                'pagination',
                'employeeStats'
            )
        );
    }
}
