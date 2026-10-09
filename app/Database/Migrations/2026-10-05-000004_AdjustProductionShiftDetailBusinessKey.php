<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AdjustProductionShiftDetailBusinessKey extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('production_shift_details')) {
            return;
        }

        $indexes = $this->db->getIndexData('production_shift_details');
        if (isset($indexes['uq_production_shift_work_date'])) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 DROP INDEX `uq_production_shift_work_date`"
            );
        }

        $indexes = $this->db->getIndexData('production_shift_details');
        if (! isset($indexes['uq_production_shift_identity'])) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 ADD UNIQUE KEY `uq_production_shift_identity`
                   (`production_id`, `work_date`, `shift_id`, `operator_employee_id`)"
            );
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('production_shift_details')) {
            return;
        }

        $indexes = $this->db->getIndexData('production_shift_details');
        if (isset($indexes['uq_production_shift_identity'])) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 DROP INDEX `uq_production_shift_identity`"
            );
        }

        $indexes = $this->db->getIndexData('production_shift_details');
        if (! isset($indexes['uq_production_shift_work_date'])) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 ADD UNIQUE KEY `uq_production_shift_work_date`
                   (`production_id`, `work_date`, `shift_id`)"
            );
        }
    }
}
