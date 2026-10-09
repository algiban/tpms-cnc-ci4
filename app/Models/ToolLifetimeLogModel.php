<?php

namespace App\Models;

use CodeIgniter\Model;

class ToolLifetimeLogModel extends Model
{
    protected $table = 'tool_lifetime_logs';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'tool_id',
        'event_type',
        'previous_lifetime',
        'new_lifetime',
        'quantity',
        'reason',
        'actor_user_id',
        'occurred_at',
        'created_at',
    ];
    protected $useTimestamps = false;
}
