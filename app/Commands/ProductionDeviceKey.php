<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProductionDeviceKey extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:device-key';
    protected $description = 'Buat/rotasi kunci API produksi per perangkat. Kunci hanya ditampilkan sekali.';
    protected $usage = 'tpms:device-key AA:BB:CC:DD:EE:FF';

    public function run(array $params)
    {
        $mac = strtoupper($params[0] ?? '');
        $db = db_connect();
        $device = $db->table('tpms_devices')->where('mac_address', $mac)->get()->getRowArray();
        if (!$device) { CLI::error('MAC belum terdaftar pada tpms_devices.'); return; }
        $key = bin2hex(random_bytes(32));
        if (!$db->table('tpms_devices')->where('id', $device['id'])->update(['production_api_key_hash' => hash('sha256', $key)])) {
            CLI::error('Gagal menyimpan kunci.'); return;
        }
        CLI::write('Simpan sebagai X-Device-Key di ESP32:');
        CLI::write($key);
    }
}
