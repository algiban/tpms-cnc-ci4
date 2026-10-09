<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductionAlarmModel extends Model
{
    protected $table = 'production_alarms';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_shift_detail_id',
        'tool_id',
        'kind',
        'severity',
        'effect',
        'status',
        'message',
        'resolution_notes',
        'resolved_at',
    ];
    protected $useTimestamps = true;
}
