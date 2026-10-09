<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductionModel extends Model
{
    protected $table = 'productions';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_code',
        'mode', 'plan_basis', 'standard_machine_time_ms', 'standard_loading_time_ms',
        'production_date',
        'slot_id',
        'machine_id',
        'part_id',
        'production_batch_id',
        'part_process_id',
        'process_no',
        'process_name_snapshot',
        'process_mode_snapshot',
        'rack_tool_id',
        'target_qty',
        'input_good_qty_snapshot',
        'target_duration_days',
        'shifts_per_day',
        'status',
        'notes',
        'created_by',
        'started_at',
        'completed_at',
        'device_id',
        'actual_qty',
        'good_qty',
        'reject_qty',
        'session_state',
    ];
    protected $useTimestamps = true;
}
