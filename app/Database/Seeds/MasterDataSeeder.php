<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $this->insertIgnore('shifts', [
            ['code' => 'SHIFT-1', 'name' => 'Shift 1', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SHIFT-2', 'name' => 'Shift 2', 'start_time' => '15:00:00', 'end_time' => '23:00:00', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SHIFT-3', 'name' => 'Shift 3', 'start_time' => '23:00:00', 'end_time' => '07:00:00', 'created_at' => $now, 'updated_at' => $now],
        ], 'code');

        $this->insertIgnore('customers', [
            ['code' => 'DENSO', 'name' => 'DENSO', 'contact_person' => 'Procurement Team', 'email' => 'purchasing@denso.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CLI', 'name' => 'CLI', 'contact_person' => 'Andi', 'email' => 'andi@cli.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PSI', 'name' => 'PSI', 'contact_person' => 'Sinta', 'email' => 'sinta@psi.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ], 'code');

        $this->insertIgnore('materials', [
            ['code' => 'PSI-13-010', 'name' => 'ABS Toyolac 500-322 SJA4339T Black', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CKU-01-090', 'name' => 'ABS TOYOLAC 440Y-MH1 B1 BLACK', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CKU-01-047', 'name' => 'PS STYRON 438 NATURAL', 'created_at' => $now, 'updated_at' => $now],
        ], 'code');

        $this->insertIgnore('machines', [
            ['registration_code' => 'REG-M-PS40-02', 'code' => 'M-PS40-02', 'name' => 'PS40e2ASE', 'maker' => 'Nissei', 'model' => 'PS40e2ASE', 'type' => 'A', 'tonnage' => 40, 'screw_diameter' => 20, 'power' => '380V', 'serial_number' => 'E04M071', 'year' => 1992, 'status' => 'assigned', 'created_at' => $now, 'updated_at' => $now],
            ['registration_code' => 'REG-M-PS40-03', 'code' => 'M-PS40-03', 'name' => 'PS40e2ASE', 'maker' => 'Nissei', 'model' => 'PS40e2ASE', 'type' => 'M', 'tonnage' => 40, 'screw_diameter' => 22, 'power' => '380V', 'serial_number' => 'E04M068', 'year' => 1992, 'status' => 'assigned', 'created_at' => $now, 'updated_at' => $now],
            ['registration_code' => 'REG-M-JSW-08', 'code' => 'M-JSW-08', 'name' => 'JT20RAD55V', 'maker' => 'JSW', 'model' => 'JT20RAD55V', 'type' => 'M', 'tonnage' => 55, 'screw_diameter' => 26, 'power' => '380V', 'serial_number' => 'JSW55008', 'year' => 2000, 'status' => 'assigned', 'created_at' => $now, 'updated_at' => $now],
            ['registration_code' => 'REG-M-SUM-80', 'code' => 'M-SUM-80', 'name' => 'Sumitomo', 'maker' => 'Sumitomo', 'model' => null, 'type' => 'A', 'tonnage' => 80, 'screw_diameter' => 26, 'power' => null, 'serial_number' => 'SUM80-A001', 'year' => null, 'status' => 'available', 'created_at' => $now, 'updated_at' => $now],
        ], 'code');

        for ($i = 1; $i <= 12; $i++) {
            if (!$this->exists('production_slots', 'slot_no', $i)) $this->db->table('production_slots')->insert(['slot_no' => $i, 'name' => "Slot {$i}", 'area' => 'Injection Floor', 'status' => 'offline', 'created_at' => $now, 'updated_at' => $now]);
        }

        $machines = $this->mapBy('machines', 'code');
        $slots = $this->mapBy('production_slots', 'slot_no');
        foreach ([2 => 'M-PS40-02', 3 => 'M-PS40-03', 8 => 'M-JSW-08'] as $slotNo => $machineCode) {
            $this->db->table('production_slots')->where('id', $slots[$slotNo]['id'])->update(['machine_id' => $machines[$machineCode]['id'], 'updated_at' => $now]);
        }

        $customers = $this->mapBy('customers', 'code');
        $materials = $this->mapBy('materials', 'code');
        foreach (
            [
                ['11101-BBP0-001', 'UPPER CASE BBP0', 'DENSO', 'PSI-13-010', 160],
                ['11202-B3F0-001', 'KEY PAD COVER B3F', 'DENSO', 'CKU-01-090', 60],
                ['JK980202-1880', 'Case Adapter', 'DENSO', 'CKU-01-047', 80],
            ] as [$no, $name, $cust, $mat, $ton]
        ) {
            if (!$this->exists('parts', 'part_number', $no)) $this->db->table('parts')->insert(['part_number' => $no, 'name' => $name, 'customer_id' => $customers[$cust]['id'], 'material_id' => $materials[$mat]['id'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->insertIgnore('tool_types', [
            ['code'=>'DRILL','name'=>'Drill','status'=>'active','created_at'=>$now,'updated_at'=>$now],
            ['code'=>'END-MILL','name'=>'End Mill','status'=>'active','created_at'=>$now,'updated_at'=>$now],
        ], 'code');
        $types=$this->mapBy('tool_types','code');
        $this->insertIgnore('tools', [
            ['code'=>'T-01','name'=>'Drill Ø2.0','tool_type_id'=>$types['DRILL']['id'],'cutting_edge'=>2,'current_edge'=>1,'actual_lifetime'=>0,'default_lifetime'=>1000,'status'=>'ready','created_at'=>$now,'updated_at'=>$now],
            ['code'=>'T-02','name'=>'End Mill Ø4.0','tool_type_id'=>$types['END-MILL']['id'],'cutting_edge'=>4,'current_edge'=>1,'actual_lifetime'=>0,'default_lifetime'=>1000,'status'=>'ready','created_at'=>$now,'updated_at'=>$now],
            ['code'=>'T-11','name'=>'Drill Ø1.5','tool_type_id'=>$types['DRILL']['id'],'cutting_edge'=>2,'current_edge'=>1,'actual_lifetime'=>0,'default_lifetime'=>2000,'status'=>'ready','created_at'=>$now,'updated_at'=>$now],
        ], 'code');
        $tools=$this->mapBy('tools','code');
        foreach ([['M-JSW-08','T-01'],['M-JSW-08','T-02'],['M-PS40-03','T-11']] as [$m,$t]) {
            if (!$this->exists('machine_tools','tool_id',$tools[$t]['id'])) $this->pivot('machine_tools',['machine_id'=>$machines[$m]['id'],'tool_id'=>$tools[$t]['id'],'assigned_at'=>$now],['tool_id']);
        }
        foreach ($this->mapBy('parts','part_number') as $part) {
            $this->pivot('part_processes',['part_id'=>$part['id'],'process_no'=>1,'process_name'=>'Single Process','process_mode'=>'auto','machine_time_target_ms'=>30000,'loading_time_target_ms'=>10000,'status'=>'active','created_at'=>$now,'updated_at'=>$now],['part_id','process_no']);
            $process=$this->db->table('part_processes')->where('part_id',$part['id'])->where('process_no',1)->get()->getRowArray();
            $this->pivot('process_tool_requirements',['part_process_id'=>$process['id'],'tool_type_id'=>$types['DRILL']['id'],'position'=>'P01','set_lifetime'=>1000],['part_process_id','tool_type_id']);
        }

        $this->insertIgnore('employees', [
            ['nik' => 'EMP-001', 'name' => 'Budi Santoso', 'department' => 'Production', 'role' => 'Operator', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['nik' => 'EMP-002', 'name' => 'Andi Wijaya', 'department' => 'Production', 'role' => 'Operator', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
            ['rfid_uid'=>'A1B2C3D4', 'nik' => 'EMP-003', 'name' => 'Siti Rahma', 'department' => 'Production', 'role' => 'PIC', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now],
        ], 'nik');

        $employees = $this->mapBy('employees', 'nik');
        $shifts = $this->mapBy('shifts', 'code');
        if ($this->db->table('employee_shift_assignments')->where('employee_id', $employees['EMP-001']['id'])->where('is_active', 1)->countAllResults() === 0) {
            $this->db->table('employee_shift_assignments')->insert(['employee_id' => $employees['EMP-001']['id'], 'shift_id' => $shifts['SHIFT-1']['id'], 'machine_id'=>$machines['M-JSW-08']['id'], 'assignment_role'=>'operator', 'slot_id' => $slots[8]['id'], 'assignment_date' => date('Y-m-d'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        }
        if ($this->db->table('employee_shift_assignments')->where('employee_id', $employees['EMP-003']['id'])->where('is_active', 1)->countAllResults() === 0) {
        }
    }

    private function exists(string $table, string $column, $value): bool
    {
        return $this->db->table($table)->where($column, $value)->countAllResults() > 0;
    }
    private function insertIgnore(string $table, array $rows, string $uniqueColumn): void
    {
        foreach ($rows as $row) if (!$this->exists($table, $uniqueColumn, $row[$uniqueColumn])) $this->db->table($table)->insert($row);
    }
    private function mapBy(string $table, string $column): array
    {
        $map = [];
        foreach ($this->db->table($table)->get()->getResultArray() as $row) $map[$row[$column]] = $row;
        return $map;
    }
    private function pivot(string $table, array $data, array $keys): void
    {
        $b = $this->db->table($table);
        foreach ($keys as $k) $b->where($k, $data[$k]);
        if ($b->countAllResults() === 0) $this->db->table($table)->insert($data);
    }
}
