<?php

namespace App\Models;

use CodeIgniter\Model;

class RackToolPartModel extends Model
{
    protected $table = 'rack_tool_parts';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['rack_tool_id', 'part_id'];
    protected $useTimestamps = false;
}
