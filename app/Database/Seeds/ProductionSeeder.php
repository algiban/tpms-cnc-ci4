<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ProductionSeeder extends Seeder
{
    public function run()
    {
        $db = $this->db;
        $now = date('Y-m-d H:i:s');
        $date = date('Y-m-d');

        $slot = $db->table('production_slots s')
            ->select('s.id, s.machine_id')
            ->where('s.machine_id IS NOT NULL', null, false)
            ->orderBy('s.slot_no')
            ->get()
            ->getRowArray();

        $part = $db->table('parts')
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->getRowArray();

        if (! $slot || ! $part) {
            echo "Run MasterDataSeeder first.\n";
            return;
        }

        $existing = $db->table('productions')
            ->where('production_date', $date)
            ->where('slot_id', $slot['id'])
            ->where('part_id', $part['id'])
            ->get()
            ->getRowArray();

        if ($existing) {
            echo "Sample production already exists.\n";
            return;
        }

        $process=$db->table('part_processes')->where('part_id',$part['id'])->where('status','active')->get()->getRowArray();
        if (!$process) return;
        $plan=\App\Services\ProcessPlan::calculate((int)$process['machine_time_target_ms'],(int)$process['loading_time_target_ms'],(int)$part['shifts_per_day']);

        $db->table('productions')->insert([
            'production_code' => 'PRD-' . date('Ymd') . '-DEMO01',
            'production_date' => $date,
            'slot_id' => $slot['id'],
            'machine_id' => $slot['machine_id'],
            'part_id' => $part['id'],
            'mode'=>'production', 'plan_basis'=>'cycle_7h', 'part_process_id'=>$process['id'], 'process_no'=>$process['process_no'], 'process_name_snapshot'=>$process['process_name'], 'process_mode_snapshot'=>$process['process_mode'],
            'standard_machine_time_ms'=>$process['machine_time_target_ms'], 'standard_loading_time_ms'=>$process['loading_time_target_ms'], 'shifts_per_day'=>$part['shifts_per_day'],
            'target_qty' => $plan['plan_per_shift'],
            'status' => 'planned',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $productionId = (int) $db->insertID();
        $shifts = $db->table('shifts')->orderBy('start_time')->get()->getResultArray();
        foreach ($shifts as $shift) {
            $db->table('production_shift_details')->insert([
                'production_id' => $productionId,
                'shift_id' => $shift['id'],
                'work_date'=>$date, 'target_qty' => $plan['plan_per_shift'],
                'good_qty' => 0,
                'reject_qty' => 0,
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        echo "Sample production created for {$date}.\n";
    }
}
