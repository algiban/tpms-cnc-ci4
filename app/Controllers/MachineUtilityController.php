<?php

namespace App\Controllers;

use DateTimeImmutable;
use DomainException;

final class MachineUtilityController extends BaseController
{
    private const OFFLINE_STALE_SECONDS = 120;

    public function index()
    {
        try {
            $data = $this->buildData();
        } catch (DomainException $e) {
            return redirect()->to(site_url('machine-utility'))->with('error', $e->getMessage());
        }

        return view('machine-utility/index', $data + ['title' => 'Machine Utility']);
    }

    private function buildData(): array
    {
        $db = db_connect();
        $query = $this->request->getGet();

        $date = isset($query['date']) && $query['date'] !== ''
            ? $this->date((string) $query['date'])
            : date('Y-m-d');

        $machines = $db->table('machines')
            ->select('id, code, name, status')
            ->where('disposed_at', null)
            ->orderBy('code')
            ->get()
            ->getResultArray();

        $machineId = $this->positiveOrZero($query['machine_id'] ?? 0, 'machine_id');
        if ($machineId === 0 && $machines) {
            $machineId = (int) $machines[0]['id'];
        }

        $shifts = $db->table('shifts')
            ->select('id, code, name, start_time, end_time')
            ->orderBy('start_time')
            ->get()
            ->getResultArray();

        $machine = null;
        $slot = null;
        $device = null;
        $shiftTimelines = [];
        $dailyTotals = $this->emptyTotals();
        $currentRuntime=null;

        if ($machineId > 0) {
            $machine = $db->table('machines')
                ->where('id', $machineId)
                ->where('disposed_at', null)
                ->get()
                ->getRowArray();

            if (! $machine) {
                throw new DomainException('Machine tidak ditemukan.');
            }

            $slot = $db->table('production_slots')
                ->where('machine_id', $machineId)
                ->get()
                ->getRowArray();

            if ($slot) {
                $device = $db->table('tpms_devices')
                    ->where('current_slot_id', (int) $slot['id'])
                    ->orderBy('last_seen_at', 'DESC')
                    ->get()
                    ->getRowArray();
            }

            [$windowStart, $windowEnd] = $this->dayWindow($date, $shifts);
            $machineEvents = $this->machineStatusEvents(
                $machineId,
                $windowStart,
                $windowEnd,
                $device,
                $date
            );

            $runtimeIntervals = $this->runtimeIntervals(
                $machineId,
                $date,
                $windowEnd
            );

            $daySegments = $this->composeSegments(
                $windowStart,
                $windowEnd,
                $machineEvents,
                $runtimeIntervals
            );

            $clock=new DateTimeImmutable();
            if ($clock >= $windowStart && $clock < $windowEnd && $daySegments) {
                $last=$daySegments[array_key_last($daySegments)];
                $currentRuntime=['state'=>$last['state'],'duration_ms'=>$last['duration_ms'],'started_at'=>$last['start']->format('Y-m-d H:i:s')];
            }
            if ($device && (empty($device['last_seen_at']) || strtotime($device['last_seen_at']) < time()-self::OFFLINE_STALE_SECONDS)) $device['device_status']='offline';
            foreach ($shifts as $shift) {
                [$shiftStart, $shiftEnd] = $this->shiftWindow($date, $shift);
                $segments = $this->clipSegments($daySegments, $shiftStart, $shiftEnd);
                $totals = $this->totals($segments);
                $details = $this->shiftProductionDetails(
                    $machineId,
                    $date,
                    (int) $shift['id']
                );

                foreach ($totals as $key => $value) {
                    $dailyTotals[$key] += $value;
                }

                $cycle=$db->table('production_cycles pc')->select('COUNT(*) cycles, AVG(pc.machine_time_ms) machine_ms, AVG(CASE WHEN pc.sequence_no>1 THEN pc.loading_time_ms END) loading_ms, AVG(CASE WHEN pc.sequence_no>1 THEN pc.cycle_time_ms END) cycle_ms',false)->join('productions p','p.id=pc.production_id')->join('production_shift_details d','d.id=pc.production_shift_detail_id')->where('p.machine_id',$machineId)->where('p.mode','production')->where('d.work_date',$date)->where('d.shift_id',$shift['id'])->where('pc.status','completed')->get()->getRowArray();
                $shiftTimelines[] = [
                    'cycle'=>$cycle,
                    'shift' => $shift,
                    'start' => $shiftStart,
                    'end' => $shiftEnd,
                    'segments' => $segments,
                    'totals' => $totals,
                    'details' => $details,
                    'summary' => $this->productionSummary($details),
                    'ticks' => $this->hourTicks($shiftStart, $shiftEnd),
                ];
            }
        }

        return compact(
            'date',
            'machineId',
            'machines',
            'shifts',
            'machine',
            'slot',
            'device',
            'shiftTimelines',
            'dailyTotals',
            'currentRuntime'
        );
    }

    private function runtimeIntervals(int $machineId, string $date, DateTimeImmutable $windowEnd): array
    {
        $db = db_connect();
        if (! $db->tableExists('production_runtime_intervals')) {
            return [];
        }

        $rows = $db->table('production_runtime_intervals pri')
            ->select(
                'pri.id, pri.state, pri.started_at, pri.ended_at, pri.duration_ms, '
                . 'pri.production_id, pri.production_shift_detail_id, '
                . 'p.part_id, p.production_code, p.process_no, p.process_name_snapshot, '
                . 'part.part_number, part.name part_name, '
                . 'd.shift_id, d.work_date'
            )
            ->join('productions p', 'p.id = pri.production_id')
            ->join('production_shift_details d', 'd.id = pri.production_shift_detail_id')
            ->join('parts part', 'part.id = p.part_id', 'left')
            ->where('p.machine_id', $machineId)
            ->where('pri.started_at <',$windowEnd->format('Y-m-d H:i:s'))
            ->groupStart()->where('pri.ended_at >=',$date.' 00:00:00')->orWhere('pri.ended_at',null)->groupEnd()
            ->orderBy('pri.started_at')
            ->orderBy('pri.id')
            ->get()
            ->getResultArray();

        $now = new DateTimeImmutable();
        $result = [];
        foreach ($rows as $row) {
            $start = $this->dateTime((string) ($row['started_at'] ?? ''));
            if (! $start) {
                continue;
            }
            $end = ! empty($row['ended_at'])
                ? $this->dateTime((string) $row['ended_at'])
                : ($now < $windowEnd ? $now : $windowEnd);

            if (! $end || $end <= $start) {
                continue;
            }

            $row['_start'] = $start;
            $row['_end'] = $end;
            $result[] = $row;
        }

        return $result;
    }

    private function machineStatusEvents(
        int $machineId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        ?array $device,
        string $date
    ): array {
        $db = db_connect();
        if (! $db->tableExists('machine_logs')) {
            return [['at' => $windowStart, 'state' => 'offline', 'source' => 'fallback']];
        }

        $initial = $db->table('machine_logs')
            ->select('new_status, occurred_at')
            ->where('machine_id', $machineId)
            ->where('event_type', 'operational_status_changed')
            ->where('occurred_at <=', $windowStart->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $events = [[
            'at' => $windowStart,
            'state' => $this->normalizeMachineState($initial['new_status'] ?? 'offline'),
            'source' => $initial ? 'machine_log_before_window' : 'fallback',
        ]];

        $rows = $db->table('machine_logs')
            ->select('new_status, occurred_at')
            ->where('machine_id', $machineId)
            ->where('event_type', 'operational_status_changed')
            ->where('occurred_at >', $windowStart->format('Y-m-d H:i:s'))
            ->where('occurred_at <', $windowEnd->format('Y-m-d H:i:s'))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $at = $this->dateTime((string) ($row['occurred_at'] ?? ''));
            if (! $at) {
                continue;
            }
            $events[] = [
                'at' => $at,
                'state' => $this->normalizeMachineState($row['new_status'] ?? 'offline'),
                'source' => 'machine_log',
            ];
        }

        /*
         * Power-off mendadak tidak bisa mengirim heartbeat "offline".
         * Untuk hari berjalan, setelah last_seen melewati ambang stale kita
         * buat batas offline sintetis agar timeline tidak terus terlihat Run.
         */
        if ($date === date('Y-m-d') && $device && ! empty($device['last_seen_at'])) {
            $lastSeen = $this->dateTime((string) $device['last_seen_at']);
            if ($lastSeen) {
                $offlineAt = $lastSeen->modify('+' . self::OFFLINE_STALE_SECONDS . ' seconds');
                $now = new DateTimeImmutable();
                if ($offlineAt < $now && $offlineAt > $windowStart && $offlineAt < $windowEnd) {
                    $events[] = [
                        'at' => $offlineAt,
                        'state' => 'offline',
                        'source' => 'stale_heartbeat',
                    ];
                }
            }
        }

        usort($events, static fn(array $a, array $b): int => $a['at'] <=> $b['at']);
        return $events;
    }

    private function composeSegments(
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        array $machineEvents,
        array $runtimeIntervals
    ): array {
        $now=new DateTimeImmutable();
        if ($windowStart >= $now) return [];
        if ($windowEnd > $now) $windowEnd=$now;
        $points = [
            $windowStart->getTimestamp() => $windowStart,
            $windowEnd->getTimestamp() => $windowEnd,
        ];

        foreach ($machineEvents as $event) {
            $at = $event['at'];
            if ($at > $windowStart && $at < $windowEnd) {
                $points[$at->getTimestamp()] = $at;
            }
        }
        foreach ($runtimeIntervals as $interval) {
            foreach (['_start', '_end'] as $key) {
                $at = $interval[$key];
                if ($at > $windowStart && $at < $windowEnd) {
                    $points[$at->getTimestamp()] = $at;
                }
            }
        }

        ksort($points);
        $points = array_values($points);
        $segments = [];
        for ($i = 0, $max = count($points) - 1; $i < $max; $i++) {
            $start = $points[$i];
            $end = $points[$i + 1];
            if ($end <= $start) {
                continue;
            }

            $machineState = $this->stateAt($machineEvents, $start);
            $runtime = $this->runtimeAt($runtimeIntervals, $start);
            [$state, $meta] = $this->effectiveState($machineState, $runtime);

            $segment = [
                'state' => $state,
                'start' => $start,
                'end' => $end,
                'duration_ms' => ($end->getTimestamp() - $start->getTimestamp()) * 1000,
                'part_number' => $meta['part_number'] ?? null,
                'part_name' => $meta['part_name'] ?? null,
                'production_code' => $meta['production_code'] ?? null,
                'process_name' => $meta['process_name_snapshot'] ?? null,
            ];

            $lastIndex = count($segments) - 1;
            if ($lastIndex >= 0 && $this->sameSegment($segments[$lastIndex], $segment)) {
                $segments[$lastIndex]['end'] = $end;
                $segments[$lastIndex]['duration_ms'] += $segment['duration_ms'];
            } else {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private function effectiveState(string $machineState, ?array $runtime): array
    {
        // Kondisi machine yang benar-benar alarm/offline selalu menang dari
        // klasifikasi production/setting agar timeline mencerminkan kondisi fisik.
        if (in_array($machineState, ['alarm', 'offline'], true)) {
            return [$machineState, $runtime ?? []];
        }

        if ($runtime !== null) {
            $state = match ((string) ($runtime['state'] ?? 'idle')) {
                'production' => 'run',
                'setting' => 'setting',
                'break' => 'break',
                default => 'idle',
            };
            return [$state, $runtime];
        }

        return [$machineState, []];
    }

    private function stateAt(array $events, DateTimeImmutable $at): string
    {
        $state = 'offline';
        foreach ($events as $event) {
            if ($event['at'] > $at) {
                break;
            }
            $state = (string) $event['state'];
        }
        return $state;
    }

    private function runtimeAt(array $intervals, DateTimeImmutable $at): ?array
    {
        $match = null;
        foreach ($intervals as $interval) {
            if ($interval['_start'] <= $at && $at < $interval['_end']) {
                $match = $interval;
            }
        }
        return $match;
    }

    private function clipSegments(array $segments, DateTimeImmutable $shiftStart, DateTimeImmutable $shiftEnd): array
    {
        $duration = max(1, $shiftEnd->getTimestamp() - $shiftStart->getTimestamp());
        $result = [];
        foreach ($segments as $segment) {
            $start = $segment['start'] < $shiftStart ? $shiftStart : $segment['start'];
            $end = $segment['end'] > $shiftEnd ? $shiftEnd : $segment['end'];
            if ($end <= $start) {
                continue;
            }
            $row = $segment;
            $row['start'] = $start;
            $row['end'] = $end;
            $row['duration_ms'] = ($end->getTimestamp() - $start->getTimestamp()) * 1000;
            $row['left_pct'] = (($start->getTimestamp() - $shiftStart->getTimestamp()) / $duration) * 100;
            $row['width_pct'] = (($end->getTimestamp() - $start->getTimestamp()) / $duration) * 100;
            $result[] = $row;
        }
        return $result;
    }

    private function shiftProductionDetails(int $machineId, string $date, int $shiftId): array
    {
        $db = db_connect();
        $builder = $db->table('production_shift_details d')
            ->select(
                'd.id detail_id, d.target_qty, d.actual_qty, d.good_qty, d.reject_qty, d.status, '
                . 'd.started_at, d.ended_at, p.id production_id, p.production_code, p.process_no, '
                . 'p.mode, p.standard_machine_time_ms, p.standard_loading_time_ms, p.process_name_snapshot, part.part_number, part.name part_name, '
                . 'e.name operator_name, pic.name pic_name'
            )
            ->join('productions p', 'p.id = d.production_id')
            ->join('parts part', 'part.id = p.part_id')
            ->join('employees e', 'e.id = d.operator_employee_id', 'left')
            ->join('employees pic', 'pic.id = d.pic_employee_id', 'left')
            ->where('p.machine_id', $machineId)
            ->where('d.work_date', $date)
            ->where('d.shift_id', $shiftId)
            ->orderBy('d.started_at')
            ->orderBy('d.id');

        return $builder->get()->getResultArray();
    }

    private function productionSummary(array $details): array
    {
        $summary = ['gross' => 0, 'good' => 0, 'reject' => 0, 'target' => 0];
        foreach ($details as $row) {
            if (($row['mode']??'production')==='setting') continue;
            $summary['gross'] += (int) ($row['actual_qty'] ?? 0);
            $summary['good'] += (int) ($row['good_qty'] ?? 0);
            $summary['reject'] += (int) ($row['reject_qty'] ?? 0);
            $summary['target'] += (int) ($row['target_qty'] ?? 0);
        }
        return $summary;
    }

    private function totals(array $segments): array
    {
        $totals = $this->emptyTotals();
        foreach ($segments as $segment) {
            $state = (string) ($segment['state'] ?? 'offline');
            if (array_key_exists($state, $totals)) {
                $totals[$state] += max(0, (int) ($segment['duration_ms'] ?? 0));
            }
        }
        return $totals;
    }

    private function emptyTotals(): array
    {
        return [
            'run' => 0,
            'break' => 0,
            'idle' => 0,
            'alarm' => 0,
            'offline' => 0,
            'setting' => 0,
        ];
    }

    private function normalizeMachineState(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'run', 'running' => 'run',
            'paused', 'pause' => 'break',
            'alarm', 'service_required', 'operator_change_required' => 'alarm',
            'idle', 'online' => 'idle',
            'offline' => 'offline',
            'setting' => 'setting',
            default => 'offline',
        };
    }

    private function dayWindow(string $date, array $shifts): array
    {
        if (! $shifts) {
            $start = new DateTimeImmutable($date . ' 00:00:00');
            return [$start, $start->modify('+1 day')];
        }

        $windows = array_map(fn(array $shift): array => $this->shiftWindow($date, $shift), $shifts);
        usort($windows, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        return [$windows[0][0], $windows[count($windows) - 1][1]];
    }

    private function shiftWindow(string $date, array $shift): array
    {
        $start = new DateTimeImmutable($date . ' ' . $shift['start_time']);
        $end = new DateTimeImmutable($date . ' ' . $shift['end_time']);
        if ($end <= $start) {
            $end = $end->modify('+1 day');
        }
        return [$start, $end];
    }

    private function hourTicks(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $duration = max(1, $end->getTimestamp() - $start->getTimestamp());
        $ticks = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $ticks[] = [
                'label' => $cursor->format('H:i'),
                'left_pct' => (($cursor->getTimestamp() - $start->getTimestamp()) / $duration) * 100,
            ];
            $cursor = $cursor->modify('+1 hour');
        }
        return $ticks;
    }

    private function sameSegment(array $a, array $b): bool
    {
        foreach (['state', 'part_number', 'production_code', 'process_name'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) {
                return false;
            }
        }
        return true;
    }

    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainException('Tanggal Machine Utility tidak valid.');
        }
        return $value;
    }

    private function positiveOrZero(mixed $value, string $label): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^(0|[1-9][0-9]*)$/D', (string) $value)) {
            throw new DomainException("{$label} tidak valid.");
        }
        $number = (int) $value;
        if ($number < 0) {
            throw new DomainException("{$label} tidak valid.");
        }
        return $number;
    }

    private function dateTime(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
