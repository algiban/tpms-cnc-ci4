<?php
namespace App\Models;
use CodeIgniter\Model;
final class ProductionRuntimeIntervalModel extends Model
{
    protected $table = 'production_runtime_intervals';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_id','production_shift_detail_id','state','source',
        'started_at','ended_at','duration_ms',
    ];
    protected $useTimestamps = true;
}
