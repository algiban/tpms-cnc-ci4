<?php
namespace App\Models;
use CodeIgniter\Model;
class ToolModel extends Model { protected $table='tools'; protected $primaryKey='id'; protected $returnType='array'; protected $allowedFields=['code','name','tool_type_id','cutting_edge','current_edge','holder','actual_lifetime','default_lifetime','status','notes']; protected $useTimestamps=true; }
