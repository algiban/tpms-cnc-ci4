<?php
namespace App\Models;
use CodeIgniter\Model;
final class PartProcessModel extends Model
{
    protected $table = 'part_processes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'part_id','process_no','process_name','process_mode','is_next_grinding',
        'machine_time_target_ms','loading_time_target_ms','status',
    ];
    protected $useTimestamps = true;
}
