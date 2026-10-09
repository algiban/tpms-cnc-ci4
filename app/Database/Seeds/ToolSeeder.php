<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;

class ToolSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $types = $this->toolTypesByCode();

        $rows = [
            // Physical tools untuk kebutuhan PART-MCH-A001.
            ['code' => 'TL-A-01', 'name' => 'Center Drill Ø4 - A01', 'tool_type' => 'CENTER-DRILL-D4', 'cutting_edge' => 2, 'current_edge' => 1, 'holder' => 'BT40-ER16', 'actual_lifetime' => 0, 'default_lifetime' => 1500, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part A.'],
            ['code' => 'TL-A-02', 'name' => 'Carbide Drill Ø8 - A02', 'tool_type' => 'DRILL-D8', 'cutting_edge' => 2, 'current_edge' => 1, 'holder' => 'BT40-ER20', 'actual_lifetime' => 0, 'default_lifetime' => 1200, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part A.'],
            ['code' => 'TL-A-03', 'name' => 'Carbide End Mill Ø10 - A03', 'tool_type' => 'ENDMILL-D10', 'cutting_edge' => 4, 'current_edge' => 1, 'holder' => 'BT40-ER20', 'actual_lifetime' => 0, 'default_lifetime' => 1000, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part A.'],
            ['code' => 'TL-A-04', 'name' => 'Machine Reamer Ø8 H7 - A04', 'tool_type' => 'REAMER-D8-H7', 'cutting_edge' => 6, 'current_edge' => 1, 'holder' => 'BT40-ER20', 'actual_lifetime' => 0, 'default_lifetime' => 800, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part A.'],
            ['code' => 'TL-A-05', 'name' => 'Chamfer Cutter Ø12 90° - A05', 'tool_type' => 'CHAMFER-D12-90', 'cutting_edge' => 4, 'current_edge' => 1, 'holder' => 'BT40-ER20', 'actual_lifetime' => 0, 'default_lifetime' => 1800, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part A.'],

            // Physical tools untuk kebutuhan PART-MCH-B001.
            ['code' => 'TL-B-01', 'name' => 'Spot Drill Ø6 - B01', 'tool_type' => 'SPOT-DRILL-D6', 'cutting_edge' => 2, 'current_edge' => 1, 'holder' => 'BT40-ER16', 'actual_lifetime' => 0, 'default_lifetime' => 1600, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part B.'],
            ['code' => 'TL-B-02', 'name' => 'Carbide Drill Ø10 - B02', 'tool_type' => 'DRILL-D10', 'cutting_edge' => 2, 'current_edge' => 1, 'holder' => 'BT40-ER20', 'actual_lifetime' => 0, 'default_lifetime' => 1200, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part B.'],
            ['code' => 'TL-B-03', 'name' => 'Carbide End Mill Ø16 - B03', 'tool_type' => 'ENDMILL-D16', 'cutting_edge' => 4, 'current_edge' => 1, 'holder' => 'BT40-ER25', 'actual_lifetime' => 0, 'default_lifetime' => 900, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part B.'],
            ['code' => 'TL-B-04', 'name' => 'Spiral Tap M10 x 1.5 - B04', 'tool_type' => 'TAP-M10-P1.5', 'cutting_edge' => 3, 'current_edge' => 1, 'holder' => 'BT40-TAP-M10', 'actual_lifetime' => 0, 'default_lifetime' => 700, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part B.'],
            ['code' => 'TL-B-05', 'name' => 'Chamfer Cutter Ø16 90° - B05', 'tool_type' => 'CHAMFER-D16-90', 'cutting_edge' => 4, 'current_edge' => 1, 'holder' => 'BT40-ER25', 'actual_lifetime' => 0, 'default_lifetime' => 1700, 'status' => 'ready', 'notes' => 'Physical tool untuk proses Part B.'],
        ];

        foreach ($rows as $row) {
            $toolTypeCode = $row['tool_type'];
            unset($row['tool_type']);

            if (! isset($types[$toolTypeCode])) {
                throw new RuntimeException("Tool Type {$toolTypeCode} belum tersedia. Jalankan ToolTypeSeeder terlebih dahulu.");
            }

            $row['tool_type_id'] = (int) $types[$toolTypeCode]['id'];

            $existing = $this->db->table('tools')
                ->where('code', $row['code'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $row['updated_at'] = $now;
                $this->db->table('tools')->where('id', $existing['id'])->update($row);
                continue;
            }

            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('tools')->insert($row);
        }
    }

    private function toolTypesByCode(): array
    {
        $result = [];
        foreach ($this->db->table('tool_types')->get()->getResultArray() as $row) {
            $result[$row['code']] = $row;
        }

        return $result;
    }
}
