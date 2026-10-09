<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class AdminSeeder extends Seeder
{
    public function run()
    {
        $username = 'admin';

        $existing = $this->db
            ->table('users')
            ->where('username', $username)
            ->get()
            ->getRowArray();

        if ($existing) {
            return;
        }

        $this->db->table('users')->insert([
            'name'       => 'Administrator',
            'username'      => $username,
            'password_hash'   => password_hash('admin123', PASSWORD_DEFAULT),
            'role'       => 'admin',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
