<?php

namespace App\Models;

use CodeIgniter\Model;

class EmployeeShiftAssignmentModel extends Model
{
    protected $table = 'employee_shift_assignments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'employee_id',
        'shift_id',
        'assignment_role',
        'slot_id',
        'machine_id',
        'assignment_date',
        'is_active',
        'notes',
    ];

    protected $useTimestamps = true;
}
