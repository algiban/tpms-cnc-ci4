<?php
namespace App\Models;
use CodeIgniter\Model;
class PartMachineAssignmentModel extends Model { protected $table='part_machine_assignments'; protected $primaryKey='id'; protected $returnType='array'; protected $allowedFields=['part_id','machine_id','is_primary']; protected $useTimestamps=false; }
