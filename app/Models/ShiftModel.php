<?php
namespace App\Models;
use CodeIgniter\Model;
class ShiftModel extends Model { protected $table='shifts'; protected $primaryKey='id'; protected $returnType='array'; protected $allowedFields=['code','name','start_time','end_time']; protected $useTimestamps=true; }
