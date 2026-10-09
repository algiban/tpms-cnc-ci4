<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class MaterialSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'code' => 'MAT-S45C',
                'name' => 'Carbon Steel S45C',
                'description' => 'Baja karbon menengah untuk komponen machining seperti shaft, pin, dan housing mekanis.',
            ],
            [
                'code' => 'MAT-AL6061',
                'name' => 'Aluminium 6061-T6',
                'description' => 'Aluminium alloy untuk komponen presisi dengan machinability dan ketahanan korosi yang baik.',
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('materials')
                ->where('code', $row['code'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $row['updated_at'] = $now;
                $this->db->table('materials')->where('id', $existing['id'])->update($row);
                continue;
            }

            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('materials')->insert($row);
        }
    }
}
