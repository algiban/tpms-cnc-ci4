<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPicEmployeeToProductionShiftDetails extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('pic_employee_id', 'production_shift_details')) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 ADD COLUMN `pic_employee_id` BIGINT UNSIGNED NULL AFTER `operator_employee_id`,
                 ADD KEY `idx_prod_shift_pic` (`shift_id`, `pic_employee_id`),
                 ADD CONSTRAINT `fk_prod_shift_pic`
                   FOREIGN KEY (`pic_employee_id`) REFERENCES `employees`(`id`)
                   ON UPDATE CASCADE ON DELETE RESTRICT"
            );
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('pic_employee_id', 'production_shift_details')) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 DROP FOREIGN KEY `fk_prod_shift_pic`,
                 DROP INDEX `idx_prod_shift_pic`,
                 DROP COLUMN `pic_employee_id`"
            );
        }
    }
}
