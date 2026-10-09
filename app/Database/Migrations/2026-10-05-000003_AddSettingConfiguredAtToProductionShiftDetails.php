<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSettingConfiguredAtToProductionShiftDetails extends Migration
{
    public function up()
    {
        if (! $this->db->fieldExists('setting_configured_at', 'production_shift_details')) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 ADD COLUMN `setting_configured_at` DATETIME NULL AFTER `pic_employee_id`"
            );
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('setting_configured_at', 'production_shift_details')) {
            $this->db->query(
                "ALTER TABLE `production_shift_details`
                 DROP COLUMN `setting_configured_at`"
            );
        }
    }
}
