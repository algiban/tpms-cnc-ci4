<?php

namespace App\Models;

use CodeIgniter\Model;

class EmployeeModel extends Model
{
    protected $table = 'employees';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'nik', 'name', 'department', 'role', 'status', 'rfid_uid',
    ];
    protected $useTimestamps = true;
}
