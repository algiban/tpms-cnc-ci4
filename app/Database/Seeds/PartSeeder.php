<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

class PartSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $customers = $this->mapByCode('customers');
        $materials = $this->mapByCode('materials');
        $toolTypes = $this->mapByCode('tool_types');

        $parts = [
            [
                'part_number' => 'PART-MCH-A001',
                'name' => 'Housing Shaft A',
                'customer_code' => 'CUST-MCH-001',
                'material_code' => 'MAT-S45C',
                'target_production' => 500,
                'process' => [
                    'process_no' => 1,
                    'process_name' => 'Single Process',
                    'process_mode' => 'auto',
                    'is_next_grinding' => 0,
                    'machine_time_target_ms' => 45000,
                    'loading_time_target_ms' => 15000,
                    'status' => 'active',
                ],
                'tools' => [
                    ['type' => 'CENTER-DRILL-D4', 'position' => 'T01', 'set_lifetime' => 1200],
                    ['type' => 'DRILL-D8', 'position' => 'T02', 'set_lifetime' => 1000],
                    ['type' => 'ENDMILL-D10', 'position' => 'T03', 'set_lifetime' => 800],
                    ['type' => 'REAMER-D8-H7', 'position' => 'T04', 'set_lifetime' => 650],
                    ['type' => 'CHAMFER-D12-90', 'position' => 'T05', 'set_lifetime' => 1500],
                ],
            ],
            [
                'part_number' => 'PART-MCH-B001',
                'name' => 'Flange Bracket B',
                'customer_code' => 'CUST-MCH-002',
                'material_code' => 'MAT-AL6061',
                'target_production' => 400,
                'process' => [
                    'process_no' => 1,
                    'process_name' => 'Single Process',
                    'process_mode' => 'auto',
                    'is_next_grinding' => 0,
                    'machine_time_target_ms' => 55000,
                    'loading_time_target_ms' => 20000,
                    'status' => 'active',
                ],
                'tools' => [
                    ['type' => 'SPOT-DRILL-D6', 'position' => 'T01', 'set_lifetime' => 1300],
                    ['type' => 'DRILL-D10', 'position' => 'T02', 'set_lifetime' => 1000],
                    ['type' => 'ENDMILL-D16', 'position' => 'T03', 'set_lifetime' => 750],
                    ['type' => 'TAP-M10-P1.5', 'position' => 'T04', 'set_lifetime' => 550],
                    ['type' => 'CHAMFER-D16-90', 'position' => 'T05', 'set_lifetime' => 1400],
                ],
            ],
        ];

        foreach ($parts as $item) {
            if (! isset($customers[$item['customer_code']])) {
                throw new RuntimeException("Customer {$item['customer_code']} belum tersedia. Jalankan CustomerSeeder terlebih dahulu.");
            }
            if (! isset($materials[$item['material_code']])) {
                throw new RuntimeException("Material {$item['material_code']} belum tersedia. Jalankan MaterialSeeder terlebih dahulu.");
            }

            foreach ($item['tools'] as $requirement) {
                if (! isset($toolTypes[$requirement['type']])) {
                    throw new RuntimeException("Tool Type {$requirement['type']} belum tersedia. Jalankan ToolTypeSeeder terlebih dahulu.");
                }
            }

            $part = $this->upsertPart($item, $customers, $materials, $now);
            $process = $this->upsertProcess((int) $part['id'], $item['process'], $now);
            $this->upsertToolRequirements((int) $process['id'], $item['tools'], $toolTypes);
        }
    }

    private function upsertPart(array $item, array $customers, array $materials, string $now): array
    {
        $data = [
            'part_number' => $item['part_number'],
            'name' => $item['name'],
            'customer_id' => (int) $customers[$item['customer_code']]['id'],
            'material_id' => (int) $materials[$item['material_code']]['id'],
            'target_production' => (int) $item['target_production'],
            'status' => 'active',
        ];

        $existing = $this->db->table('parts')
            ->where('part_number', $item['part_number'])
            ->get()
            ->getRowArray();

        if ($existing) {
            $data['updated_at'] = $now;
            $this->db->table('parts')->where('id', $existing['id'])->update($data);
            return $this->db->table('parts')->where('id', $existing['id'])->get()->getRowArray();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->db->table('parts')->insert($data);

        return $this->db->table('parts')->where('id', $this->db->insertID())->get()->getRowArray();
    }

    private function upsertProcess(int $partId, array $process, string $now): array
    {
        $existing = $this->db->table('part_processes')
            ->where('part_id', $partId)
            ->where('process_no', $process['process_no'])
            ->get()
            ->getRowArray();

        $data = [
            'part_id' => $partId,
            'process_no' => (int) $process['process_no'],
            'process_name' => $process['process_name'],
            'process_mode' => $process['process_mode'],
            'is_next_grinding' => (int) $process['is_next_grinding'],
            'machine_time_target_ms' => (int) $process['machine_time_target_ms'],
            'loading_time_target_ms' => (int) $process['loading_time_target_ms'],
            'status' => $process['status'],
        ];

        if ($existing) {
            $data['updated_at'] = $now;
            $this->db->table('part_processes')->where('id', $existing['id'])->update($data);
            return $this->db->table('part_processes')->where('id', $existing['id'])->get()->getRowArray();
        }

        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->db->table('part_processes')->insert($data);

        return $this->db->table('part_processes')->where('id', $this->db->insertID())->get()->getRowArray();
    }

    private function upsertToolRequirements(int $processId, array $requirements, array $toolTypes): void
    {
        foreach ($requirements as $requirement) {
            $toolTypeId = (int) $toolTypes[$requirement['type']]['id'];

            $data = [
                'part_process_id' => $processId,
                'tool_type_id' => $toolTypeId,
                'position' => $requirement['position'],
                'set_lifetime' => (int) $requirement['set_lifetime'],
            ];

            $existing = $this->db->table('process_tool_requirements')
                ->where('part_process_id', $processId)
                ->where('tool_type_id', $toolTypeId)
                ->get()
                ->getRowArray();

            if ($existing) {
                $this->db->table('process_tool_requirements')->where('id', $existing['id'])->update($data);
                continue;
            }

            $this->db->table('process_tool_requirements')->insert($data);
        }
    }

    private function mapByCode(string $table): array
    {
        $result = [];
        foreach ($this->db->table($table)->get()->getResultArray() as $row) {
            $result[$row['code']] = $row;
        }

        return $result;
    }
}
