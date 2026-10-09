<?php
namespace App\Models;
use CodeIgniter\Model;
final class RackToolRuntimeLockModel extends Model
{
    protected $table = 'rack_tool_runtime_locks';
    protected $primaryKey = 'rack_tool_id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'rack_tool_id','machine_id','device_id','production_id','production_batch_id',
        'acquired_at','heartbeat_at','updated_at',
    ];
    protected $useAutoIncrement = false;
    protected $useTimestamps = false;
}
