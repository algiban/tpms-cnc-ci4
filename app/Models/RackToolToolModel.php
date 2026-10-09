<?php

namespace App\Models;

use CodeIgniter\Model;

class RackToolToolModel extends Model
{
    protected $table = 'rack_tool_tools';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['rack_tool_id', 'tool_id', 'position'];
    protected $useTimestamps = false;
}
