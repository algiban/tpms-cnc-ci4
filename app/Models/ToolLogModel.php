<?php

namespace App\Models;

use CodeIgniter\Model;

class ToolLogModel extends Model
{
    protected $table = 'tool_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'tool_id',
        'production_id',
        'production_shift_detail_id',
        'tool_code',
        'event_type',
        'severity',
        'source',
        'actor_type',
        'actor_user_id',
        'previous_lifetime',
        'new_lifetime',
        'quantity',
        'message',
        'before_data',
        'after_data',
        'metadata',
        'occurred_at',
        'created_at',
    ];

    protected $useTimestamps = false;
}
