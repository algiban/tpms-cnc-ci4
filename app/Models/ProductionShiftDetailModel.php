<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductionShiftDetailModel extends Model
{
    protected $table = 'production_shift_details';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_id',
        'shift_id',
        'work_date',
        'counter_epoch',
        'operator_employee_id',
        'pic_employee_id',
        'unit_head_employee_id',
        'target_qty',
        'actual_qty',
        'good_qty',
        'reject_qty',
        'status',
        'started_at',
        'ended_at',
        'notes',
    ];
    protected $useTimestamps = true;
}
