<?php

namespace App\Models;

use CodeIgniter\Model;

class RackToolModel extends Model
{
    protected $table = 'rack_tools';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['code', 'name', 'uid', 'location', 'status'];
    protected $useTimestamps = true;
}
