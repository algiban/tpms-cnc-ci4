<?php

namespace App\Models;

use CodeIgniter\Model;

class PartModel extends Model
{
    protected $table = 'parts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['part_number', 'name', 'customer_id', 'material_id', 'target_production', 'target_duration_days', 'shifts_per_day', 'process_type', 'process_count', 'process_mode', 'next_grinding', 'status'];
    protected $useTimestamps = true;
}
