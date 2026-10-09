<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateProductionOperatorHistories extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('production_operator_histories')) return;
        $this->db->query("CREATE TABLE `production_operator_histories` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `production_shift_detail_id` BIGINT UNSIGNED NOT NULL,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `started_at` DATETIME NOT NULL,
            `ended_at` DATETIME NULL,
            `change_reason` VARCHAR(120) NULL,
            `source` VARCHAR(30) NOT NULL DEFAULT 'tpms',
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_operator_history_detail_time` (`production_shift_detail_id`,`started_at`,`id`),
            KEY `idx_operator_history_employee` (`employee_id`,`started_at`),
            CONSTRAINT `fk_operator_history_detail` FOREIGN KEY (`production_shift_detail_id`) REFERENCES `production_shift_details`(`id`) ON UPDATE CASCADE ON DELETE CASCADE,
            CONSTRAINT `fk_operator_history_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("INSERT INTO `production_operator_histories`
            (`production_shift_detail_id`,`employee_id`,`started_at`,`ended_at`,`change_reason`,`source`,`created_at`,`updated_at`)
            SELECT d.id,d.operator_employee_id,COALESCE(d.started_at,d.created_at,NOW()),CASE WHEN d.status='completed' THEN d.ended_at ELSE NULL END,
                   'migration_backfill','system',COALESCE(d.started_at,d.created_at,NOW()),NOW()
            FROM production_shift_details d JOIN productions p ON p.id=d.production_id
            WHERE d.operator_employee_id IS NOT NULL AND COALESCE(p.mode,'production')='production'");
    }

    public function down()
    {
        $this->forge->dropTable('production_operator_histories', true);
    }
}
