<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductionToolUsageModel extends Model
{
    protected $table = 'production_tool_usages';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_shift_detail_id',
        'tool_id',
        'set_lifetime_snapshot',
        'start_lifetime',
        'end_lifetime',
        'quantity_increment',
        'position_snapshot',
        'tool_code_snapshot', 'tool_name_snapshot', 'tool_type_code_snapshot', 'cutting_edge_snapshot', 'current_edge_snapshot',
    ];
    protected $useTimestamps = true;
}
