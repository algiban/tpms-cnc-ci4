<?php

namespace App\Models;

use CodeIgniter\Model;

class MachineLogModel extends Model
{
    protected $table = 'machine_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'machine_id',
        'slot_id',
        'tpms_device_id',
        'machine_code',
        'event_type',
        'severity',
        'source',
        'actor_type',
        'actor_user_id',
        'previous_status',
        'new_status',
        'message',
        'before_data',
        'after_data',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected $useTimestamps = false;
}
