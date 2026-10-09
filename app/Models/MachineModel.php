<?php

namespace App\Models;

use CodeIgniter\Model;

class MachineModel extends Model
{
    protected $table = 'machines';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['registration_code', 'code', 'name', 'maker', 'model', 'type', 'power', 'tonnage', 'screw_diameter', 'serial_number', 'year', 'status', 'disposed_at'];
    protected $useTimestamps = true;
}
