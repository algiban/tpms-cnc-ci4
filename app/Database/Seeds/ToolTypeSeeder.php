<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ToolTypeSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        // Dibuat spesifik per ukuran supaya resolver Part -> Tool Type -> Physical Tool
        // tidak salah memilih tool hanya karena kategorinya sama.
        $rows = [
            ['code' => 'CENTER-DRILL-D4', 'name' => 'Center Drill Ø4 mm', 'status' => 'active'],
            ['code' => 'DRILL-D8', 'name' => 'Carbide Drill Ø8 mm', 'status' => 'active'],
            ['code' => 'ENDMILL-D10', 'name' => 'Carbide End Mill Ø10 mm', 'status' => 'active'],
            ['code' => 'REAMER-D8-H7', 'name' => 'Machine Reamer Ø8 H7', 'status' => 'active'],
            ['code' => 'CHAMFER-D12-90', 'name' => 'Chamfer Cutter Ø12 90°', 'status' => 'active'],

            ['code' => 'SPOT-DRILL-D6', 'name' => 'Spot Drill Ø6 mm', 'status' => 'active'],
            ['code' => 'DRILL-D10', 'name' => 'Carbide Drill Ø10 mm', 'status' => 'active'],
            ['code' => 'ENDMILL-D16', 'name' => 'Carbide End Mill Ø16 mm', 'status' => 'active'],
            ['code' => 'TAP-M10-P1.5', 'name' => 'Spiral Tap M10 x 1.5', 'status' => 'active'],
            ['code' => 'CHAMFER-D16-90', 'name' => 'Chamfer Cutter Ø16 90°', 'status' => 'active'],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('tool_types')
                ->where('code', $row['code'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $row['updated_at'] = $now;
                $this->db->table('tool_types')->where('id', $existing['id'])->update($row);
                continue;
            }

            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('tool_types')->insert($row);
        }
    }
}
