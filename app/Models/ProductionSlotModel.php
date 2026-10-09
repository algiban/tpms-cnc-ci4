<?php
namespace App\Models;
use CodeIgniter\Model;
class ProductionSlotModel extends Model { protected $table='production_slots'; protected $primaryKey='id'; protected $returnType='array'; protected $allowedFields=['slot_no','name','area','machine_id','status']; protected $useTimestamps=true; }
