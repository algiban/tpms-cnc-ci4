<?php

namespace App\Models;

use CodeIgniter\Model;

class MaterialModel extends Model
{
    protected $table = 'materials';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['code', 'name'];
    protected $useTimestamps = true;
}
