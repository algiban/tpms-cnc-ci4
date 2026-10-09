<?php

namespace App\Models;

use CodeIgniter\Model;

class PartToolModel extends Model
{
    protected $table = 'part_tools';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['part_id', 'tool_id', 'position', 'set_lifetime'];
    protected $useTimestamps = true;
}
