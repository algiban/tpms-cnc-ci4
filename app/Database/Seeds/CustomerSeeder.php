<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $rows = [
            [
                'code' => 'CUST-MCH-001',
                'name' => 'PT Alpha Precision Manufacturing',
                'contact_person' => 'Procurement Alpha',
                'email' => 'procurement.alpha@example.com',
                'phone' => '021-555-0101',
                'address' => 'Kawasan Industri Bekasi, Jawa Barat',
                'status' => 'active',
            ],
            [
                'code' => 'CUST-MCH-002',
                'name' => 'PT Beta Automotive Components',
                'contact_person' => 'Procurement Beta',
                'email' => 'procurement.beta@example.com',
                'phone' => '021-555-0202',
                'address' => 'Kawasan Industri Karawang, Jawa Barat',
                'status' => 'active',
            ],
        ];

        foreach ($rows as $row) {
            $existing = $this->db->table('customers')
                ->where('code', $row['code'])
                ->get()
                ->getRowArray();

            if ($existing) {
                $row['updated_at'] = $now;
                $this->db->table('customers')->where('id', $existing['id'])->update($row);
                continue;
            }

            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $this->db->table('customers')->insert($row);
        }
    }
}
