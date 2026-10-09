<?php
namespace App\Models;
use CodeIgniter\Model;
final class ProductionCycleModel extends Model
{
    protected $table = 'production_cycles';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_id','production_shift_detail_id','part_process_id','sequence_no',
        'machine_started_at','machine_stopped_at','start_tick_ms','stop_tick_ms','machine_time_ms','loading_time_ms',
        'cycle_time_ms','counter_total_snapshot','status',
    ];
    protected $useTimestamps = true;
}
