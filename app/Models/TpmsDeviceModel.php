<?php

namespace App\Models;

use CodeIgniter\Model;

class TpmsDeviceModel extends Model
{
    protected $table = 'tpms_devices';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['mac_address', 'ip_address', 'firmware_version', 'hmi_version', 'token', 'current_slot_id', 'device_status', 'connection_status', 'registered_at', 'last_seen_at', 'last_connection_check_at'];
    protected $useTimestamps = true;
}
