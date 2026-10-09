<?php

namespace App\Controllers;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

class LogController extends BaseController
{
    protected $db;

    public function __construct()
    {
        $this->db = db_connect();
    }

    private function render(string $view, string $title, array $data = [])
    {
        return view(
            "logs/{$view}",
            array_merge(['title' => $title], $data)
        );
    }

    private function validDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value
            ? $value
            : null;
    }

    private function applyDateFilter($builder, string $column, ?string $date): void
    {
        if (! $date) {
            return;
        }

        $start = $date . ' 00:00:00';
        $end = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d 00:00:00');
        $builder->where($column . ' >=', $start)->where($column . ' <', $end);
    }

    /**
     * InnoDB COUNT(*) over multi-million-row log tables is unnecessary for an
     * unfiltered dashboard headline. TABLE_ROWS is approximate but constant-cost.
     */
    private function approximateRows(string $table): int
    {
        $row = $this->db->query(
            'SELECT TABLE_ROWS AS total FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        )->getRowArray();

        return max(0, (int) ($row['total'] ?? 0));
    }

    private function todayRange($builder, string $column = 'occurred_at'): void
    {
        $start = date('Y-m-d 00:00:00');
        $end = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $builder->where($column . ' >=', $start)->where($column . ' <', $end);
    }

    private function applyScopeFilter($builder, ?string $scope, string $slotColumn, string $machineColumn): void
    {
        $scope = trim((string) $scope);

        if ($scope === '') {
            return;
        }

        if (str_starts_with($scope, 'slot:')) {
            $builder->where($slotColumn, (int) substr($scope, 5));
            return;
        }

        if (str_starts_with($scope, 'machine:')) {
            $builder->where($machineColumn, (int) substr($scope, 8));
        }
    }

    private function sevenDaySeries(array $rows): array
    {
        $start = (new DateTimeImmutable('today'))->sub(new DateInterval('P6D'));
        $end = new DateTimeImmutable('today');

        $map = [];

        foreach ($rows as $row) {
            $map[$row['day']] = (int) $row['total'];
        }

        $period = new DatePeriod(
            $start,
            new DateInterval('P1D'),
            $end->modify('+1 day')
        );

        $series = [];

        foreach ($period as $date) {
            $key = $date->format('Y-m-d');

            $series[] = [
                'label' => $date->format('d M'),
                'value' => $map[$key] ?? 0,
            ];
        }

        return $series;
    }

    private function scopeOptions(): array
    {
        $slotOptions = $this->db->table('production_slots s')
            ->select('s.id, s.slot_no, m.name machine_name')
            ->join('machines m', 'm.id = s.machine_id', 'left')
            ->orderBy('s.slot_no', 'ASC')
            ->get()
            ->getResultArray();

        $machineOptions = $this->db->table('machines')
            ->select('id, name')
            ->where('disposed_at', null)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResultArray();

        $options = [
            [
                'value' => '',
                'label' => 'All',
            ],
        ];

        foreach ($slotOptions as $row) {
            $options[] = [
                'value' => 'slot:' . $row['id'],
                'label' => 'Slot ' . $row['slot_no']
                    . ($row['machine_name'] ? ' / ' . $row['machine_name'] : ''),
            ];
        }

        foreach ($machineOptions as $row) {
            $options[] = [
                'value' => 'machine:' . $row['id'],
                'label' => 'Machine / ' . $row['name'],
            ];
        }

        return $options;
    }

    public function tpms()
    {
        $filters = [
            'scope'      => trim((string) $this->request->getGet('scope')),
            'event_type' => trim((string) $this->request->getGet('event_type')),
            'date'       => $this->validDate($this->request->getGet('date')),
        ];

        $scopeOptions = $this->scopeOptions();

        $eventOptions = $this->db->table('tpms_logs')
            ->select('event_type')
            ->groupBy('event_type')
            ->orderBy('event_type', 'ASC')
            ->get()
            ->getResultArray();

        $base = $this->db->table('tpms_logs l')
            ->select(
                'l.*, s.slot_no, m.name machine_name, d.ip_address, ' .
                    'COALESCE(l.mac_address, d.mac_address) AS display_mac, ' .
                    'u.username AS actor_username'
            )
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->join('tpms_devices d', 'd.id = l.tpms_device_id', 'left')
            ->join('users u', 'u.id = l.actor_user_id', 'left');

        $this->applyScopeFilter($base, $filters['scope'], 'l.slot_id', 'l.machine_id');
        $this->applyDateFilter($base, 'l.occurred_at', $filters['date']);

        if ($filters['event_type'] !== '') {
            $base->where('l.event_type', $filters['event_type']);
        }

        $tpmsFiltered = $filters['scope'] !== '' || $filters['event_type'] !== '' || $filters['date'] !== null;
        $recordCount = $tpmsFiltered ? (clone $base)->countAllResults() : $this->approximateRows('tpms_logs');

        $logs = (clone $base)
            ->orderBy('l.occurred_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $totalLogs = $this->approximateRows('tpms_logs');

        $todayBuilder = $this->db->table('tpms_logs');
        $this->todayRange($todayBuilder);
        $todayLogs = $todayBuilder->countAllResults();

        $registeredDevices = $this->db->table('tpms_devices')->countAllResults();

        $mostActiveSlot30 = $this->db->table('tpms_logs l')
            ->select('s.slot_no, m.name machine_name, COUNT(*) total', false)
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->where('l.slot_id IS NOT NULL', null, false)
            ->groupBy('l.slot_id, s.slot_no, m.name')
            ->orderBy('total', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $trendRows = $this->db->table('tpms_logs l')
            ->select('DATE(l.occurred_at) AS day, COUNT(*) AS total', false)
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->groupBy('DATE(l.occurred_at)')
            ->orderBy('DATE(l.occurred_at)', 'ASC')
            ->get()
            ->getResultArray();

        $dailyTrend = $this->sevenDaySeries($trendRows);

        $eventRows = $this->db->table('tpms_logs l')
            ->select('l.event_type, COUNT(*) total', false)
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->groupBy('l.event_type')
            ->orderBy('total', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $slotRows = $this->db->table('tpms_logs l')
            ->select('s.slot_no, m.name machine_name, COUNT(*) total', false)
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->where('l.slot_id IS NOT NULL', null, false)
            ->groupBy('l.slot_id, s.slot_no, m.name')
            ->orderBy('total', 'DESC')
            ->limit(7)
            ->get()
            ->getResultArray();

        return $this->render('tpms', 'TPMS Logs', [
            'filters'          => $filters,
            'scopeOptions'     => $scopeOptions,
            'eventOptions'     => $eventOptions,
            'recordCount'      => $recordCount,
            'logs'             => $logs,
            'totalLogs'        => $totalLogs,
            'todayLogs'        => $todayLogs,
            'registeredDevices' => $registeredDevices,
            'mostActiveSlot30' => $mostActiveSlot30,
            'dailyTrend'       => $dailyTrend,
            'eventRows'        => $eventRows,
            'slotRows'         => $slotRows,
        ]);
    }

    public function tools()
    {
        $filters = [
            'scope'   => trim((string) $this->request->getGet('scope')),
            'tool_id' => (int) ($this->request->getGet('tool_id') ?: 0),
            'date'    => $this->validDate($this->request->getGet('date')),
        ];

        $scopeOptions = $this->scopeOptions();

        $toolOptions = $this->db->table('tools')
            ->select('id, code, name')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResultArray();

        $base = $this->db->table('tool_logs l')
            ->select(
                'l.*, t.code AS tool_code_name, t.name AS tool_name, t.default_lifetime, ' .
                    's.slot_no, m.name machine_name, part.part_number, mat.name AS material_name, ' .
                    'u.username AS actor_username'
            )
            ->join('tools t', 't.id = l.tool_id', 'left')
            ->join('productions prod', 'prod.id = l.production_id', 'left')
            ->join('production_slots s', 's.id = prod.slot_id', 'left')
            ->join('machines m', 'm.id = prod.machine_id', 'left')
            ->join('parts part', 'part.id = prod.part_id', 'left')
            ->join('materials mat', 'mat.id = part.material_id', 'left')
            ->join('users u', 'u.id = l.actor_user_id', 'left');

        $this->applyScopeFilter($base, $filters['scope'], 'prod.slot_id', 'prod.machine_id');
        $this->applyDateFilter($base, 'l.occurred_at', $filters['date']);

        if ($filters['tool_id'] > 0) {
            $base->where('l.tool_id', $filters['tool_id']);
        }

        $toolFiltered = $filters['scope'] !== '' || $filters['tool_id'] > 0 || $filters['date'] !== null;
        $recordCount = $toolFiltered ? (clone $base)->countAllResults() : $this->approximateRows('tool_logs');

        $logs = (clone $base)
            ->orderBy('l.occurred_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $totalLogs = $this->approximateRows('tool_logs');

        $loggedTools = $this->db->table('tool_logs')
            ->select('COUNT(DISTINCT tool_id) AS total', false)
            ->get()
            ->getRowArray();

        $warningCritical = $this->db->table('tool_logs')
            ->whereIn('event_type', ['lifetime_warning', 'lifetime_critical'])
            ->countAllResults();

        $reset30d = $this->db->table('tool_logs')
            ->where('event_type', 'lifetime_reset')
            ->where('occurred_at >=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->countAllResults();

        $sideChange30d = $this->db->table('tool_logs')
            ->where('event_type', 'side_change')
            ->where('occurred_at >=', date('Y-m-d 00:00:00', strtotime('-29 days')))
            ->countAllResults();

        $eventRows = $this->db->table('tool_logs l')
            ->select('l.event_type, COUNT(*) total', false)
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->groupBy('l.event_type')
            ->orderBy('total', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $topToolRows = $this->db->table('tool_logs l')
            ->select('t.code, t.name, COUNT(*) total', false)
            ->join('tools t', 't.id = l.tool_id', 'left')
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->groupBy('l.tool_id, t.code, t.name')
            ->orderBy('total', 'DESC')
            ->limit(7)
            ->get()
            ->getResultArray();

        return $this->render('tools', 'Tools Logs', [
            'filters'         => $filters,
            'scopeOptions'    => $scopeOptions,
            'toolOptions'     => $toolOptions,
            'recordCount'     => $recordCount,
            'logs'            => $logs,
            'totalLogs'       => $totalLogs,
            'loggedTools'     => (int) ($loggedTools['total'] ?? 0),
            'warningCritical' => $warningCritical,
            'reset30d'        => $reset30d,
            'sideChange30d'   => $sideChange30d,
            'eventRows'       => $eventRows,
            'topToolRows'     => $topToolRows,
        ]);
    }

    public function machines()
    {
        $filters = [
            'scope'  => trim((string) $this->request->getGet('scope')),
            'status' => trim((string) $this->request->getGet('status')),
            'date'   => $this->validDate($this->request->getGet('date')),
        ];

        $scopeOptions = $this->scopeOptions();

        $statusOptions = $this->db->table('machine_logs')
            ->select('new_status')
            ->where('new_status IS NOT NULL', null, false)
            ->groupBy('new_status')
            ->orderBy('new_status', 'ASC')
            ->get()
            ->getResultArray();

        $base = $this->db->table('machine_logs l')
            ->select(
                'l.*, s.slot_no, m.name machine_name, d.ip_address, d.mac_address, ' .
                    'u.username AS actor_username'
            )
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->join('tpms_devices d', 'd.id = l.tpms_device_id', 'left')
            ->join('users u', 'u.id = l.actor_user_id', 'left');

        $this->applyScopeFilter($base, $filters['scope'], 'l.slot_id', 'l.machine_id');
        $this->applyDateFilter($base, 'l.occurred_at', $filters['date']);

        if ($filters['status'] !== '') {
            $base->where('l.new_status', $filters['status']);
        }

        $machineFiltered = $filters['scope'] !== '' || $filters['status'] !== '' || $filters['date'] !== null;
        $recordCount = $machineFiltered ? (clone $base)->countAllResults() : $this->approximateRows('machine_logs');

        $logs = (clone $base)
            ->orderBy('l.occurred_at', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        $totalLogs = $this->approximateRows('machine_logs');

        $todayBuilder = $this->db->table('machine_logs');
        $this->todayRange($todayBuilder);
        $todayLogs = $todayBuilder->countAllResults();

        $alarmBuilder = $this->db->table('machine_logs')->where('new_status', 'alarm');
        $this->todayRange($alarmBuilder);
        $alarmToday = $alarmBuilder->countAllResults();

        $mostActiveSlot7 = $this->db->table('machine_logs l')
            ->select('s.slot_no, m.name machine_name, COUNT(*) total', false)
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->where('l.slot_id IS NOT NULL', null, false)
            ->groupBy('l.slot_id, s.slot_no, m.name')
            ->orderBy('total', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        $statusRows = $this->db->table('machine_logs l')
            ->select('l.new_status, COUNT(*) total', false)
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->where('l.new_status IS NOT NULL', null, false)
            ->groupBy('l.new_status')
            ->orderBy('total', 'DESC')
            ->get()
            ->getResultArray();

        $slotRows = $this->db->table('machine_logs l')
            ->select('s.slot_no, m.name machine_name, COUNT(*) total', false)
            ->join('production_slots s', 's.id = l.slot_id', 'left')
            ->join('machines m', 'm.id = l.machine_id', 'left')
            ->where('l.occurred_at >=', date('Y-m-d 00:00:00', strtotime('-6 days')))
            ->where('l.slot_id IS NOT NULL', null, false)
            ->groupBy('l.slot_id, s.slot_no, m.name')
            ->orderBy('total', 'DESC')
            ->limit(7)
            ->get()
            ->getResultArray();

        return $this->render('machines', 'Machine Logs', [
            'filters'        => $filters,
            'scopeOptions'   => $scopeOptions,
            'statusOptions'  => $statusOptions,
            'recordCount'    => $recordCount,
            'logs'           => $logs,
            'totalLogs'      => $totalLogs,
            'todayLogs'      => $todayLogs,
            'alarmToday'     => $alarmToday,
            'mostActiveSlot7' => $mostActiveSlot7,
            'statusRows'     => $statusRows,
            'slotRows'       => $slotRows,
        ]);
    }
}
