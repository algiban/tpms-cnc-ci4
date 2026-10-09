<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class ShiftSeeder extends Seeder
{
    public function run()
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                "code" => "shift1",
                "name" => "Shift 1",
                "start_time" => "07:00:00",
                "end_time" => "15:00:00"
            ],
            [
                "code" => "shift2",
                "name" => "Shift 2",
                "start_time" => "15:00:00",
                "end_time" => "23:00:00"
            ],
            [
                "code" => "shift3",
                "name" => "Shift 3",
                "start_time" => "23:00:00",
                "end_time" => "07:00:00"
            ]
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('shifts')
                ->where('code', $row['code'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $row['updated_at'] = $now;
                $this->db->table('shifts')->where('id', $existing['id'])->update($row);
                continue;
            }

            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('shifts')->insert($row);
        }
    }
}
