<?php

namespace App\Models;

use CodeIgniter\Model;

class ProductionNcDetailModel extends Model
{
    protected $table = 'production_nc_details';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = [
        'production_shift_detail_id',
        'defect_type',
        'quantity',
    ];
    protected $useTimestamps = true;
}
