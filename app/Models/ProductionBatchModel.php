<?php
namespace App\Models;
use CodeIgniter\Model;
final class ProductionBatchModel extends Model
{
    protected $table = 'production_batches';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'batch_code','part_id','rack_tool_id','target_qty','process_count',
        'current_process_no','final_good_qty','status','started_at','completed_at',
    ];
    protected $useTimestamps = true;
}
