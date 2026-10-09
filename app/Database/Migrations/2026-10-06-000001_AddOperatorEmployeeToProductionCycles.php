<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddOperatorEmployeeToProductionCycles extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('production_cycles')) {
            return;
        }

        if (! $this->db->fieldExists('operator_employee_id', 'production_cycles')) {
            $this->db->query(
                "ALTER TABLE `production_cycles`
                 ADD COLUMN `operator_employee_id` BIGINT UNSIGNED NULL AFTER `production_shift_detail_id`,
                 ADD KEY `idx_production_cycle_operator` (`operator_employee_id`, `machine_stopped_at`, `id`),
                 ADD CONSTRAINT `fk_cycle_operator_employee`
                    FOREIGN KEY (`operator_employee_id`) REFERENCES `employees`(`id`)
                    ON UPDATE CASCADE ON DELETE SET NULL"
            );
        }

        // Backfill histori lama menggunakan interval operator jika tersedia.
        // Fallback ke operator_employee_id pada shift detail untuk instalasi lama
        // yang belum memiliki production_operator_histories.
        if ($this->db->tableExists('production_operator_histories')) {
            $this->db->query(
                "UPDATE `production_cycles` pc
                 JOIN `production_shift_details` d ON d.id = pc.production_shift_detail_id
                 SET pc.operator_employee_id = COALESCE(
                    (
                        SELECT h.employee_id
                        FROM production_operator_histories h
                        WHERE h.production_shift_detail_id = pc.production_shift_detail_id
                          AND COALESCE(pc.machine_stopped_at, pc.machine_started_at, pc.created_at)
                              >= h.started_at
                          AND (
                              h.ended_at IS NULL
                              OR COALESCE(pc.machine_stopped_at, pc.machine_started_at, pc.created_at) < h.ended_at
                          )
                        ORDER BY h.started_at DESC, h.id DESC
                        LIMIT 1
                    ),
                    d.operator_employee_id
                 )
                 WHERE pc.operator_employee_id IS NULL"
            );
        } else {
            $this->db->query(
                "UPDATE `production_cycles` pc
                 JOIN `production_shift_details` d ON d.id = pc.production_shift_detail_id
                 SET pc.operator_employee_id = d.operator_employee_id
                 WHERE pc.operator_employee_id IS NULL"
            );
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('production_cycles')
            || ! $this->db->fieldExists('operator_employee_id', 'production_cycles')) {
            return;
        }

        $this->db->query(
            "ALTER TABLE `production_cycles`
             DROP FOREIGN KEY `fk_cycle_operator_employee`,
             DROP INDEX `idx_production_cycle_operator`,
             DROP COLUMN `operator_employee_id`"
        );
    }
}
