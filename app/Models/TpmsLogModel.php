<?php

namespace App\Models;

use CodeIgniter\Model;

class TpmsLogModel extends Model
{
    protected $table = 'tpms_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'tpms_device_id',
        'slot_id',
        'machine_id',
        'mac_address',
        'event_type',
        'severity',
        'source',
        'actor_type',
        'actor_user_id',
        'message',
        'before_data',
        'after_data',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected $useTimestamps = false;
}
