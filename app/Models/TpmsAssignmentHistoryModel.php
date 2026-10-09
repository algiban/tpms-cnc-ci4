<?php
namespace App\Models;
use CodeIgniter\Model;
class TpmsAssignmentHistoryModel extends Model { protected $table='tpms_assignment_histories'; protected $primaryKey='id'; protected $returnType='array'; protected $allowedFields=['tpms_device_id','from_slot_id','to_slot_id','event_type','ip_address','notes','happened_at']; protected $useTimestamps=true; }
