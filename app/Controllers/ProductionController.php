<?php

namespace App\Controllers;

use App\Services\ProductionRules;
use DomainException;

class ProductionController extends BaseController
{
    private const PER_PAGE_OPTIONS = [25, 50, 100];
    private const DEFAULT_PER_PAGE = 50;

    public function index()
    {
        try {
            $data = $this->data();
        } catch (DomainException $e) {
            return redirect()->to(site_url('production'))->with('error', $e->getMessage());
        }

        return view('production/index', $data + ['title' => 'Production']);
    }

    /**
     * Lazy detail endpoint untuk modal Production.
     * Detail cycle dipusatkan di Cycle Time Report; modal ini hanya membaca
     * runtime, alarm, tools, defect, batch flow, dan histori operator.
     */
    public function detail(int $detailId)
    {
        if ($detailId <= 0) {
            return $this->response->setStatusCode(404)->setBody('Production detail tidak ditemukan.');
        }

        $db = db_connect();
        $record = $this->detailRecord($db, $detailId);
        if ($record === null) {
            return $this->response->setStatusCode(404)->setBody('Production detail tidak ditemukan.');
        }

        $runtime = [
            $detailId => $db->table('production_runtime_intervals')
                ->where('production_shift_detail_id', $detailId)
                ->orderBy('started_at')
                ->orderBy('id')
                ->get()
                ->getResultArray(),
        ];

        $alarms = [
            $detailId => $db->table('production_alarms')
                ->where('production_shift_detail_id', $detailId)
                ->orderBy('id', 'DESC')
                ->get()
                ->getResultArray(),
        ];

        $tools = [
            $detailId => $db->table('production_tool_usages u')
                ->select('u.*, COALESCE(u.tool_code_snapshot,t.code) code, COALESCE(u.tool_name_snapshot,t.name) name, COALESCE(u.cutting_edge_snapshot,t.cutting_edge) cutting_edge, COALESCE(u.current_edge_snapshot,t.current_edge) current_edge, t.holder')
                ->join('tools t', 't.id = u.tool_id')
                ->where('u.production_shift_detail_id', $detailId)
                ->orderBy('u.id')
                ->get()
                ->getResultArray(),
        ];

        $defects = [
            $detailId => $db->table('production_nc_details')
                ->where('production_shift_detail_id', $detailId)
                ->orderBy('id')
                ->get()
                ->getResultArray(),
        ];

        $processFlow = [
            (int) $record['part_id'] => $db->table('part_processes')
                ->where('part_id', (int) $record['part_id'])
                ->where('status', 'active')
                ->orderBy('process_no')
                ->get()
                ->getResultArray(),
        ];

        $batchOverview = [];
        if (!empty($record['production_batch_id']) && $db->tableExists('production_batches')) {
            $batchOverview = $this->buildBatchOverview($db, [(int) $record['production_batch_id']]);
        }

        $operatorHistory = [];
        if ($db->tableExists('production_operator_histories')) {
            $operatorHistory = $db->table('production_operator_histories h')
                ->select('h.*, e.nik, e.name operator_name')->join('employees e', 'e.id = h.employee_id')
                ->where('h.production_shift_detail_id', $detailId)->orderBy('h.started_at')->orderBy('h.id')->get()->getResultArray();
        }

        return view('production/_detail', [
            'r' => $record,
            'runtime' => $runtime,
            'alarms' => $alarms,
            'tools' => $tools,
            'defects' => $defects,
            'processFlow' => $processFlow,
            'batchOverview' => $batchOverview,
            'operatorHistory' => $operatorHistory,
        ]);
    }

    // Endpoint UI lama sengaja ditutup. Writer production hanya dari ESP32.
    public function store()
    {
        return $this->response
            ->setStatusCode(405)
            ->setBody('Produksi hanya dapat dimulai melalui ESP32.');
    }

    public function saveShift(int $id)
    {
        return $this->response
            ->setStatusCode(405)
            ->setBody('Data produksi hanya dapat dikirim melalui ESP32.');
    }

    private function data(): array
    {
        $query = $this->request->getGet();
        $period = ProductionRules::period($query);
        $filters = $this->filters($query);
        $db = db_connect();

        $perPage = (int) ($query['per_page'] ?? self::DEFAULT_PER_PAGE);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        $page = max(1, (int) ($query['page'] ?? 1));

        // Count ringan: tidak membawa payload detail dan tidak memuat history.
        $totalRows = $this->reportBuilder($db, $period, $filters, false)
            ->countAllResults();
        $pages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $records = $this->reportBuilder($db, $period, $filters, true)
            ->orderBy('d.work_date', 'DESC')
            ->orderBy('p.id', 'DESC')
            ->orderBy('d.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        // Summary selalu untuk SELURUH periode/filter, bukan hanya halaman aktif.
        $summary = $this->reportSummary($db, $period, $filters);

        return [
            'records' => $records,
            'period' => $period,
            'filters' => $filters,
            'summary' => $summary,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalRows,
                'pages' => $pages,
                'options' => self::PER_PAGE_OPTIONS,
            ],
            'machines' => $db->table('machines')
                ->select('id, name')
                ->where('disposed_at', null)
                ->orderBy('name')
                ->get()
                ->getResultArray(),
            'shifts' => $db->table('shifts')
                ->select('id, name, code')
                ->orderBy('code')
                ->get()
                ->getResultArray(),
            'customers' => $db->table('customers')
                ->select('id, name')
                ->orderBy('name')
                ->get()
                ->getResultArray(),
        ];
    }

    private function filters(array $query): array
    {
        $filters = [];
        foreach (['machine_id', 'shift_id', 'customer_id'] as $key) {
            $filters[$key] = ProductionRules::integer(
                ($query[$key] ?? '') === '' ? 0 : ($query[$key] ?? 0),
                $key
            );
        }
        return $filters;
    }

    /**
     * Report dimulai dari production_shift_details agar filter work_date dapat
     * memakai index secara langsung. Halaman Production memang berorientasi
     * pada satu row per shift detail.
     */
    private function reportBuilder($db, array $period, array $filters, bool $withSelect)
    {
        $builder = $db->table('production_shift_details d')
            ->join('productions p', 'p.id = d.production_id')
            ->where('d.work_date >=', $period['start'])
            ->where('d.work_date <=', $period['end']);

        if ($withSelect || $filters['customer_id'] > 0) {
            $builder->join('parts part', 'part.id = p.part_id');
        }

        if ($withSelect) {
            $builder
                ->join('machines m', 'm.id = p.machine_id')
                ->join('production_slots s', 's.id = p.slot_id')
                ->join('customers c', 'c.id = part.customer_id')
                ->join('shifts sh', 'sh.id = d.shift_id', 'left')
                ->join('employees e', 'e.id = d.operator_employee_id', 'left')
                ->join('employees pic', 'pic.id = d.pic_employee_id', 'left');
        }

        if ($filters['machine_id'] > 0) {
            $builder->where('p.machine_id', $filters['machine_id']);
        }
        if ($filters['shift_id'] > 0) {
            $builder->where('d.shift_id', $filters['shift_id']);
        }
        if ($filters['customer_id'] > 0) {
            $builder->where('part.customer_id', $filters['customer_id']);
        }

        if ($withSelect) {
            $builder->select(
                'p.id,
                 p.production_code,
                 p.production_date,
                 p.machine_id,
                 p.part_id,
                 p.status,
                 p.mode,
                 p.plan_basis,
                 p.target_qty production_target_qty,
                 p.target_duration_days,
                 p.shifts_per_day,
                 p.actual_qty production_gross_qty,
                 p.good_qty production_good_qty,
                 p.reject_qty production_reject_qty,
                 p.production_batch_id,
                 p.part_process_id,
                 p.process_no,
                 p.process_name_snapshot,
                 p.process_mode_snapshot,
                 m.name machine_name,
                 s.slot_no,
                 part.part_number,
                 part.name part_name,
                 part.process_count part_process_count,
                 c.name customer_name,
                 d.id detail_id,
                 d.work_date detail_work_date,
                 d.target_qty detail_target_qty,
                 d.actual_qty shift_actual_qty,
                 d.good_qty,
                 d.reject_qty,
                 d.status detail_status,
                 d.started_at detail_started_at,
                 d.ended_at detail_ended_at,
                 d.shift_id,
                 d.operator_employee_id,
                 sh.name shift_name,
                 e.name operator_name,
                 pic.name pic_name'
            );
        }

        return $builder;
    }

    private function reportSummary($db, array $period, array $filters): array
    {
        $builder = $db->table('production_shift_details d')
            ->select(
                "COALESCE(SUM(CASE WHEN COALESCE(p.mode, 'production') <> 'setting' THEN d.actual_qty ELSE 0 END), 0) gross,
                 COALESCE(SUM(CASE WHEN COALESCE(p.mode, 'production') <> 'setting'
                     AND (COALESCE(p.plan_basis, 'legacy') = 'cycle_7h' OR COALESCE(p.process_no, 1) = COALESCE(part.process_count, 1))
                     THEN d.good_qty ELSE 0 END), 0) good,
                 COALESCE(SUM(CASE WHEN COALESCE(p.mode, 'production') <> 'setting' THEN d.reject_qty ELSE 0 END), 0) reject,
                 COALESCE(SUM(CASE WHEN COALESCE(p.mode, 'production') <> 'setting' THEN d.target_qty ELSE 0 END), 0) target,
                 COUNT(DISTINCT CASE WHEN COALESCE(p.mode, 'production') <> 'setting' AND p.status IN ('running','active') THEN p.id END) open",
                false
            )
            ->join('productions p', 'p.id = d.production_id')
            ->join('parts part', 'part.id = p.part_id')
            ->where('d.work_date >=', $period['start'])
            ->where('d.work_date <=', $period['end']);

        if ($filters['machine_id'] > 0) {
            $builder->where('p.machine_id', $filters['machine_id']);
        }
        if ($filters['shift_id'] > 0) {
            $builder->where('d.shift_id', $filters['shift_id']);
        }
        if ($filters['customer_id'] > 0) {
            $builder->where('part.customer_id', $filters['customer_id']);
        }

        $row = $builder->get()->getRowArray() ?? [];
        return [
            'gross' => (int) ($row['gross'] ?? 0),
            'good' => (int) ($row['good'] ?? 0),
            'reject' => (int) ($row['reject'] ?? 0),
            'target' => (int) ($row['target'] ?? 0),
            'open' => (int) ($row['open'] ?? 0),
        ];
    }

    private function detailRecord($db, int $detailId): ?array
    {
        $row = $db->table('production_shift_details d')
            ->select(
                'p.*,
                 p.target_qty production_target_qty,
                 p.actual_qty production_gross_qty,
                 p.good_qty production_good_qty,
                 p.reject_qty production_reject_qty,
                 COALESCE(p.standard_machine_time_ms,pp.machine_time_target_ms) machine_time_target_ms,
                 COALESCE(p.standard_loading_time_ms,pp.loading_time_target_ms) loading_time_target_ms,
                 m.name machine_name,
                 s.slot_no,
                 part.part_number,
                 part.name part_name,
                 part.process_count part_process_count,
                 c.name customer_name,
                 d.id detail_id,
                 d.work_date detail_work_date,
                 d.target_qty detail_target_qty,
                 d.actual_qty shift_actual_qty,
                 d.good_qty,
                 d.reject_qty,
                 d.status detail_status,
                 d.started_at detail_started_at,
                 d.ended_at detail_ended_at,
                 d.shift_id,
                 d.operator_employee_id,
                 sh.name shift_name,
                 e.name operator_name,
                 pic.name pic_name'
            )
            ->join('productions p', 'p.id = d.production_id')
            ->join('machines m', 'm.id = p.machine_id')
            ->join('production_slots s', 's.id = p.slot_id')
            ->join('parts part', 'part.id = p.part_id')
            ->join('part_processes pp', 'pp.id = p.part_process_id', 'left')
            ->join('customers c', 'c.id = part.customer_id')
            ->join('shifts sh', 'sh.id = d.shift_id', 'left')
            ->join('employees e', 'e.id = d.operator_employee_id', 'left')
            ->join('employees pic', 'pic.id = d.pic_employee_id', 'left')
            ->where('d.id', $detailId)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    private function buildBatchOverview($db, array $batchIds): array
    {
        $batchIds = array_values(array_unique(array_filter(array_map('intval', $batchIds))));
        if (!$batchIds || !$db->tableExists('production_batches')) {
            return [];
        }

        $batchRows = $db->table('production_batches b')
            ->select('b.*, part.part_number, part.name part_name, part.process_count part_process_count, rt.code rack_code')
            ->join('parts part', 'part.id = b.part_id')
            ->join('rack_tools rt', 'rt.id = b.rack_tool_id', 'left')
            ->whereIn('b.id', $batchIds)
            ->orderBy('b.id', 'DESC')
            ->get()
            ->getResultArray();

        if (!$batchRows) {
            return [];
        }

        $partIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) $row['part_id'],
            $batchRows
        )));

        $processMaster = [];
        if ($partIds) {
            foreach ($db->table('part_processes')
                ->whereIn('part_id', $partIds)
                ->where('status', 'active')
                ->orderBy('part_id')
                ->orderBy('process_no')
                ->get()
                ->getResultArray() as $processRow) {
                $processMaster[(int) $processRow['part_id']][(int) $processRow['process_no']] = $processRow;
            }
        }

        $batchProductions = [];
        foreach ($db->table('productions p')
            ->select('p.id, p.production_batch_id, p.production_code, p.process_no, p.process_name_snapshot, p.process_mode_snapshot, p.target_qty, p.actual_qty, p.good_qty, p.reject_qty, p.status, p.session_state, p.started_at, p.completed_at, m.code machine_code, m.name machine_name')
            ->join('machines m', 'm.id = p.machine_id', 'left')
            ->whereIn('p.production_batch_id', $batchIds)
            ->orderBy('p.production_batch_id')
            ->orderBy('p.process_no')
            ->orderBy('p.id')
            ->get()
            ->getResultArray() as $productionRow) {
            $batchProductions[(int) $productionRow['production_batch_id']][(int) $productionRow['process_no']] = $productionRow;
        }

        $batchOverview = [];
        foreach ($batchRows as $batch) {
            $batchId = (int) $batch['id'];
            $partId = (int) $batch['part_id'];
            $processCount = max(1, (int) ($batch['process_count'] ?? $batch['part_process_count'] ?? 1));
            $currentProcessNo = max(1, (int) ($batch['current_process_no'] ?? 1));
            $processes = [];
            $liveFinalGood = 0;

            for ($processNo = 1; $processNo <= $processCount; $processNo++) {
                $master = $processMaster[$partId][$processNo] ?? [];
                $productionRow = $batchProductions[$batchId][$processNo] ?? null;

                if ($productionRow) {
                    $stageStatus = ($productionRow['status'] ?? '') === 'completed' ? 'completed' : 'running';
                } elseif (($batch['status'] ?? '') === 'completed' || $processNo < $currentProcessNo) {
                    $stageStatus = 'completed';
                } elseif ($processNo === $currentProcessNo) {
                    $stageStatus = 'ready';
                } else {
                    $stageStatus = 'waiting';
                }

                if ($processNo === $processCount && $productionRow) {
                    $liveFinalGood = max(0, (int) ($productionRow['good_qty'] ?? 0));
                }

                $processes[] = [
                    'process_no' => $processNo,
                    'process_name' => $productionRow['process_name_snapshot'] ?? $master['process_name'] ?? ('Process ' . $processNo),
                    'process_mode' => $productionRow['process_mode_snapshot'] ?? $master['process_mode'] ?? 'auto',
                    'is_next_grinding' => (int) ($master['is_next_grinding'] ?? 0) === 1,
                    'status' => $stageStatus,
                    'production_id' => $productionRow ? (int) $productionRow['id'] : null,
                    'production_code' => $productionRow['production_code'] ?? null,
                    'target_qty' => $productionRow ? (int) $productionRow['target_qty'] : 0,
                    'gross_qty' => $productionRow ? (int) $productionRow['actual_qty'] : 0,
                    'good_qty' => $productionRow ? (int) $productionRow['good_qty'] : 0,
                    'reject_qty' => $productionRow ? (int) $productionRow['reject_qty'] : 0,
                    'machine_code' => $productionRow['machine_code'] ?? null,
                    'machine_name' => $productionRow['machine_name'] ?? null,
                ];
            }

            $finalGood = ($batch['status'] ?? '') === 'completed'
                ? max(0, (int) ($batch['final_good_qty'] ?? 0))
                : $liveFinalGood;
            $targetQty = max(0, (int) ($batch['target_qty'] ?? 0));

            $batchOverview[$batchId] = [
                'id' => $batchId,
                'batch_code' => $batch['batch_code'],
                'part_number' => $batch['part_number'],
                'part_name' => $batch['part_name'],
                'rack_code' => $batch['rack_code'] ?? null,
                'target_qty' => $targetQty,
                'final_good_qty' => $finalGood,
                'achievement' => $targetQty > 0 ? min(100, round(($finalGood / $targetQty) * 100, 2)) : 0,
                'process_count' => $processCount,
                'current_process_no' => $currentProcessNo,
                'status' => $batch['status'],
                'started_at' => $batch['started_at'] ?? null,
                'completed_at' => $batch['completed_at'] ?? null,
                'processes' => $processes,
            ];
        }

        return $batchOverview;
    }

    public function export()
    {
        try {
            $query = $this->request->getGet();
            $period = ProductionRules::period($query);
            $filters = $this->filters($query);
        } catch (DomainException $e) {
            return redirect()->to(site_url('production'))->with('error', $e->getMessage());
        }

        $db = db_connect();
        $result = $this->reportBuilder($db, $period, $filters, true)
            ->orderBy('d.work_date', 'DESC')
            ->orderBy('p.id', 'DESC')
            ->orderBy('d.id', 'DESC')
            ->get();

        // php://temp pindah ke temporary file setelah 2 MB sehingga export besar
        // tidak menahan seluruh proses PHP di RAM selama CSV dibangun.
        $handle = fopen('php://temp/maxmemory:2097152', 'r+');
        fputcsv($handle, [
            'Kode', 'Tanggal kerja', 'Mesin', 'Part', 'Process', 'Mode Process',
            'Shift', 'Operator/PIC', 'Gross shift', 'Good', 'Reject', 'Target shift',
            'Target production', 'Durasi target (hari)', 'Shift per hari',
            'Status shift', 'Status production',
        ], ',', '"', '');

        while ($r = $result->getUnbufferedRow('array')) {
            $values = [
                $r['production_code'],
                $r['detail_work_date'] ?: $r['production_date'],
                $r['machine_name'],
                $r['part_number'],
                $r['process_name_snapshot'] ?? 'Single Process',
                strtolower((string) ($r['process_mode_snapshot'] ?? '')) === 'none'
                    ? '' : strtoupper((string) ($r['process_mode_snapshot'] ?? 'auto')),
                $r['shift_name'],
                ($r['mode'] ?? 'production') === 'setting' ? $r['pic_name'] : $r['operator_name'],
                (int) ($r['shift_actual_qty'] ?? 0),
                (int) ($r['good_qty'] ?? 0),
                (int) ($r['reject_qty'] ?? 0),
                (int) ($r['detail_target_qty'] ?? 0),
                (int) $r['production_target_qty'],
                (int) ($r['target_duration_days'] ?? 1),
                (int) ($r['shifts_per_day'] ?? 1),
                $r['detail_status'] ?? '-',
                $r['status'],
            ];

            $values = array_map(static function ($value) {
                $value = (string) $value;
                return preg_match('/^[\s]*[=+@-]/', $value) ? "'" . $value : $value;
            }, $values);
            fputcsv($handle, $values, ',', '"', '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="production-' . $period['start'] . '-' . $period['end'] . '.csv"')
            ->setBody($csv);
    }
}
