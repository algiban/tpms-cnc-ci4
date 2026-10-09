<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

final class CreateTpmsUnifiedSchema extends Migration
{
    /**
     * Urutan tabel mengikuti ketergantungan foreign key.
     */
    private const TABLES = [
        'users',
        'machines',
        'production_slots',
        'tpms_devices',
        'tpms_assignment_histories',
        'customers',
        'materials',
        'rack_tools',
        'tool_types',
        'tools',
        'parts',
        'rack_tool_parts',
        'rack_tool_tools',
        'part_tools',
        'part_machine_assignments',
        'employees',
        'shifts',
        'employee_shift_assignments',
        'part_processes',
        'production_batches',
        'productions',
        'production_shift_details',
        'production_tool_usages',
        'production_nc_details',
        'tool_lifetime_logs',
        'production_api_events',
        'production_alarms',
        'rack_tool_runtime_locks',
        'production_cycles',
        'production_runtime_intervals',
        'rfid_scan_events',
        'machine_tools',
        'process_tool_requirements',
        'tpms_refactor_issues',
        'tpms_device_runtime_locks',
        'tpms_logs',
        'tool_logs',
        'machine_logs',
        'tpms_runtime_lock',
    ];

    public function up()
    {
        /*
         * Service ESP32 sebelumnya menggunakan beberapa raw query
         * dengan nama tabel tanpa prefix.
         */
        if ($this->db->DBPrefix !== '') {
            throw new RuntimeException(
                'Gunakan DBPrefix kosong untuk skema TPMS ini.'
            );
        }

        /*
         * Periksa seluruh tabel sebelum membuat apa pun.
         * Migration ini bukan migration upgrade database lama.
         */
        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                throw new RuntimeException(
                    "Tabel {$table} sudah tersedia. "
                        . 'Migration ini hanya untuk database baru.'
                );
            }
        }

        $this->createUsers();
        $this->createMachines();
        $this->createProductionSlots();
        $this->createDevices();
        $this->createDeviceHistories();

        $this->createCustomers();
        $this->createMaterials();
        $this->createRackTools();
        $this->createTools();
        $this->createParts();
        $this->createRackToolParts();
        $this->createRackToolTools();
        $this->createPartTools();
        $this->createPartMachineAssignments();

        $this->createEmployees();
        $this->createShifts();
        $this->createEmployeeShiftAssignments();

        $this->createProductions();
        $this->createProductionShiftDetails();
        $this->createProductionToolUsages();
        $this->createProductionNcDetails();
        $this->createToolLifetimeLogs();
        $this->createProductionApiEvents();
        $this->createProductionAlarms();
        $this->createRuntimeLock();

        /*
         * Semua perubahan migration 2026-09-25 s.d. 2026-10-03 digabung
         * di migration bootstrap ini. Migration ini hanya untuk database BARU.
         */
        $this->normalizeBaseCollations();
        $this->applyFinalCoreSchema();
        $this->createFinalProcessDomainTables();
        $this->createFinalOperationalLogTables();
        $this->createFinalCycleTables();
        $this->createFinalRfidTables();
        $this->createFinalMachineToolDomain();
        $this->createFinalDeviceRuntimeLocks();
        $this->addFinalIndexes();
    }

    public function down()
    {
        /*
         * Bootstrap migration: rollback menghapus seluruh schema hasil file ini.
         * FOREIGN_KEY_CHECKS dimatikan agar urutan dependency tidak membuat
         * rollback gagal.
         */
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (array_reverse(self::TABLES) as $table) {
                $this->forge->dropTable($table, true);
            }
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function createUsers(): void
    {
        $this->createTable(
            'users',
            [
                'id' => [
                    'type'           => 'INT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 50,
                    'default'    => '',
                ],
                'username'      => $this->varchar(50),
                'password_hash' => $this->varchar(255),
                'role' => [
                    'type'       => 'ENUM',
                    'constraint' => ['admin', 'user'],
                    'default'    => 'user',
                ],
            ],
            unique: [
                ['username'],
            ]
        );
    }

    private function createMachines(): void
    {
        $this->createTable(
            'machines',
            [
                'code'          => $this->varchar(50),
                'name'          => $this->varchar(100),
                'maker'         => $this->varchar(100, true),
                'model'         => $this->varchar(100, true),
                'type'          => $this->varchar(50, true),
                'power'         => $this->varchar(50, true),
                'serial_number' => $this->varchar(100, true),
                'year' => [
                    'type'     => 'SMALLINT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'status'      => $this->status('available'),
                'disposed_at' => $this->datetime(),
            ],
            unique: [
                ['code'],
                ['serial_number'],
            ],
            indexes: [
                ['status', 'disposed_at'],
            ]
        );
    }

    private function createProductionSlots(): void
    {
        $this->createTable(
            'production_slots',
            [
                'slot_no'    => $this->unsignedInteger(),
                'name'       => $this->varchar(100, true),
                'area'       => $this->varchar(100, true),
                'machine_id' => $this->foreignId(true),
                'status'     => $this->status('offline'),
            ],
            unique: [
                ['slot_no'],
                ['machine_id'],
            ],
            foreignKeys: [
                ['machine_id', 'machines', 'SET NULL'],
            ]
        );
    }

    private function createDevices(): void
    {
        $this->createTable(
            'tpms_devices',
            [
                'mac_address'             => $this->varchar(17),
                'ip_address'              => $this->varchar(45, true),
                'firmware_version'        => $this->varchar(150, true),
                'hmi_version'             => $this->varchar(150, true),
                'token'                   => $this->varchar(64, true),
                'production_api_key_hash' => [
                    'type'       => 'CHAR',
                    'constraint' => 64,
                    'null'       => true,
                ],
                'current_slot_id' => $this->foreignId(true),
                'device_status'   => $this->status('offline'),
                'connection_status' => $this->status('unknown'),
                'registered_at'   => $this->datetime(),
                'last_seen_at'    => $this->datetime(),
                'last_connection_check_at' => $this->datetime(),
            ],
            unique: [
                ['mac_address'],
                ['token'],
            ],
            indexes: [
                ['current_slot_id'],
                ['last_seen_at'],
                ['ip_address'],
            ],
            foreignKeys: [
                ['current_slot_id', 'production_slots', 'SET NULL'],
            ]
        );
    }

    private function createDeviceHistories(): void
    {
        $this->createTable(
            'tpms_assignment_histories',
            [
                'tpms_device_id' => $this->foreignId(),
                'from_slot_id'   => $this->foreignId(true),
                'to_slot_id'     => $this->foreignId(true),
                'event_type'     => $this->varchar(20),
                'token'          => $this->varchar(64, true),
                'ip_address'     => $this->varchar(45, true),
                'notes'          => $this->text(),
                'happened_at'    => $this->datetime(false),
            ],
            indexes: [
                ['tpms_device_id', 'happened_at'],
            ],
            foreignKeys: [
                ['tpms_device_id', 'tpms_devices', 'RESTRICT'],
                ['from_slot_id', 'production_slots', 'SET NULL'],
                ['to_slot_id', 'production_slots', 'SET NULL'],
            ]
        );
    }

    private function createCustomers(): void
    {
        $this->createTable(
            'customers',
            [
                'code'           => $this->varchar(50),
                'name'           => $this->varchar(150),
                'contact_person' => $this->varchar(100, true),
                'email'          => $this->varchar(150, true),
                'phone'          => $this->varchar(50, true),
                'address'        => $this->text(),
                'status'         => $this->status('active'),
            ],
            unique: [
                ['code'],
            ]
        );
    }

    private function createMaterials(): void
    {
        $this->createTable(
            'materials',
            [
                'code'        => $this->varchar(50),
                'name'        => $this->varchar(180),
                'description' => $this->text(),
            ],
            unique: [
                ['code'],
            ]
        );
    }

    private function createRackTools(): void
    {
        $this->createTable(
            'rack_tools',
            [
                'code'     => $this->varchar(80),
                'name'     => $this->varchar(150),
                'uid'      => $this->varchar(100, true),
                'location' => $this->varchar(180, true),
                'status'   => $this->status('available'),
            ],
            unique: [
                ['code'],
                ['uid'],
            ]
        );
    }

    private function createTools(): void
    {
        $this->createTable(
            'tools',
            [
                'code'            => $this->varchar(80),
                'name'            => $this->varchar(150),
                'type'            => $this->varchar(100, true),
                'actual_lifetime' => $this->quantity(),
                'default_lifetime' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'status' => $this->status('ready'),
                'notes'  => $this->text(),
            ],
            unique: [
                ['code'],
            ],
            indexes: [
                ['status'],
            ]
        );
    }

    private function createParts(): void
    {
        $this->createTable(
            'parts',
            [
                'part_number'       => $this->varchar(100),
                'name'              => $this->varchar(180),
                'customer_id'       => $this->foreignId(),
                'material_id'       => $this->foreignId(true),
                'target_production' => $this->quantity(),
                'status'            => $this->status('active'),
            ],
            unique: [
                ['part_number'],
            ],
            foreignKeys: [
                ['customer_id', 'customers', 'RESTRICT'],
                ['material_id', 'materials', 'SET NULL'],
            ]
        );
    }

    private function createRackToolParts(): void
    {
        $this->createTable(
            'rack_tool_parts',
            [
                'rack_tool_id' => $this->foreignId(),
                'part_id'      => $this->foreignId(),
            ],
            unique: [
                ['rack_tool_id', 'part_id'],
            ],
            foreignKeys: [
                ['rack_tool_id', 'rack_tools', 'CASCADE'],
                ['part_id', 'parts', 'CASCADE'],
            ]
        );
    }

    private function createRackToolTools(): void
    {
        $this->createTable(
            'rack_tool_tools',
            [
                'rack_tool_id' => $this->foreignId(),
                'tool_id'      => $this->foreignId(),
                'position'     => $this->varchar(30, true),
            ],
            unique: [
                ['rack_tool_id', 'tool_id'],
            ],
            foreignKeys: [
                ['rack_tool_id', 'rack_tools', 'CASCADE'],
                ['tool_id', 'tools', 'RESTRICT'],
            ]
        );
    }

    private function createPartTools(): void
    {
        $this->createTable(
            'part_tools',
            [
                'part_id'      => $this->foreignId(),
                'tool_id'      => $this->foreignId(),
                'position'     => $this->varchar(30, true),
                'set_lifetime' => $this->unsignedInteger(),
            ],
            unique: [
                ['part_id', 'tool_id'],
                ['part_id', 'position'],
            ],
            foreignKeys: [
                ['part_id', 'parts', 'CASCADE'],
                ['tool_id', 'tools', 'RESTRICT'],
            ]
        );
    }

    private function createPartMachineAssignments(): void
    {
        $this->createTable(
            'part_machine_assignments',
            [
                'part_id'    => $this->foreignId(),
                'machine_id' => $this->foreignId(),
                'is_primary' => $this->boolean(false),
            ],
            unique: [
                ['part_id', 'machine_id'],
            ],
            foreignKeys: [
                ['part_id', 'parts', 'CASCADE'],
                ['machine_id', 'machines', 'CASCADE'],
            ]
        );
    }

    private function createEmployees(): void
    {
        $this->createTable(
            'employees',
            [
                'nik'        => $this->varchar(50),
                'name'       => $this->varchar(150),
                'department' => $this->varchar(100),
                'role'       => $this->varchar(100),
                'rfid_uid'   => $this->varchar(20, true),
                'status'     => $this->status('active'),
            ],
            unique: [
                ['nik'],
                ['rfid_uid'],
            ],
            indexes: [
                ['status', 'name'],
            ]
        );
    }

    private function createShifts(): void
    {
        $this->createTable(
            'shifts',
            [
                'code'       => $this->varchar(30),
                'name'       => $this->varchar(100),
                'start_time' => ['type' => 'TIME'],
                'end_time'   => ['type' => 'TIME'],
            ],
            unique: [
                ['code'],
            ]
        );
    }

    private function createEmployeeShiftAssignments(): void
    {
        $this->createTable(
            'employee_shift_assignments',
            [
                'employee_id'     => $this->foreignId(),
                'shift_id'        => $this->foreignId(),
                'slot_id'         => $this->foreignId(true),
                'machine_id'      => $this->foreignId(),
                'assignment_date' => ['type' => 'DATE'],
                'is_active'       => $this->boolean(true),
                'notes'           => $this->text(),
            ],
            unique: [
                // Satu operator untuk satu mesin, tanggal, dan shift.
                ['machine_id', 'assignment_date', 'shift_id'],

                // Employee tidak bisa berada di dua mesin pada shift sama.
                ['employee_id', 'assignment_date', 'shift_id'],
            ],
            indexes: [
                ['assignment_date', 'is_active'],
            ],
            foreignKeys: [
                ['employee_id', 'employees', 'RESTRICT'],
                ['shift_id', 'shifts', 'RESTRICT'],
                ['slot_id', 'production_slots', 'SET NULL'],
                ['machine_id', 'machines', 'RESTRICT'],
            ]
        );
    }

    private function createProductions(): void
    {
        $this->createTable(
            'productions',
            [
                'production_code' => $this->varchar(60),
                'production_date' => ['type' => 'DATE'],
                'slot_id'         => $this->foreignId(),
                'machine_id'      => $this->foreignId(),
                'part_id'         => $this->foreignId(),
                'rack_tool_id'    => $this->foreignId(true),
                'device_id'       => $this->foreignId(true),
                'target_qty'      => $this->quantity(),
                'actual_qty'      => $this->quantity(),
                'status'          => $this->status('planned'),

                /*
                 * running / paused / awaiting_defects / completed.
                 * Nullable untuk kompatibilitas pembacaan data lama.
                 */
                'session_state' => $this->varchar(30, true),

                'notes'      => $this->text(),
                'created_by' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'started_at'   => $this->datetime(),
                'completed_at' => $this->datetime(),
            ],
            unique: [
                ['production_code'],
            ],
            indexes: [
                ['production_date', 'machine_id'],
                ['production_date', 'part_id'],
                ['machine_id', 'session_state'],
                ['rack_tool_id', 'session_state'],
                ['device_id', 'session_state'],
            ],
            foreignKeys: [
                ['slot_id', 'production_slots', 'RESTRICT'],
                ['machine_id', 'machines', 'RESTRICT'],
                ['part_id', 'parts', 'RESTRICT'],
                ['rack_tool_id', 'rack_tools', 'RESTRICT'],
                ['device_id', 'tpms_devices', 'RESTRICT'],
                ['created_by', 'users', 'SET NULL'],
            ]
        );
    }

    private function createProductionShiftDetails(): void
    {
        $this->createTable(
            'production_shift_details',
            [
                'production_id'        => $this->foreignId(),
                'shift_id'             => $this->foreignId(),
                'operator_employee_id' => $this->foreignId(true),
                'target_qty'           => $this->quantity(),
                'good_qty'             => $this->quantity(),
                'reject_qty'           => $this->quantity(),
                'status'               => $this->status('planned'),
                'started_at'           => $this->datetime(),
                'ended_at'             => $this->datetime(),
                'notes'                => $this->text(),
            ],
            unique: [
                ['production_id', 'shift_id'],
            ],
            indexes: [
                ['shift_id', 'operator_employee_id'],
            ],
            foreignKeys: [
                ['production_id', 'productions', 'RESTRICT'],
                ['shift_id', 'shifts', 'RESTRICT'],
                ['operator_employee_id', 'employees', 'RESTRICT'],
            ]
        );
    }

    private function createProductionToolUsages(): void
    {
        $this->createTable(
            'production_tool_usages',
            [
                'production_shift_detail_id' => $this->foreignId(),
                'tool_id'                    => $this->foreignId(),
                'position_snapshot'          => $this->varchar(30, true),
                'set_lifetime_snapshot'      => $this->unsignedInteger(),
                'start_lifetime'             => $this->quantity(),
                'end_lifetime'               => $this->quantity(),
                'quantity_increment' => [
                    'type'    => 'INT',
                    'default' => 0,
                ],
            ],
            unique: [
                ['production_shift_detail_id', 'tool_id'],
            ],
            foreignKeys: [
                [
                    'production_shift_detail_id',
                    'production_shift_details',
                    'RESTRICT',
                ],
                ['tool_id', 'tools', 'RESTRICT'],
            ]
        );
    }

    private function createProductionNcDetails(): void
    {
        $this->createTable(
            'production_nc_details',
            [
                'production_shift_detail_id' => $this->foreignId(),
                'defect_type'                => $this->varchar(80),
                'quantity'                   => $this->quantity(),
            ],
            unique: [
                ['production_shift_detail_id', 'defect_type'],
            ],
            foreignKeys: [
                [
                    'production_shift_detail_id',
                    'production_shift_details',
                    'RESTRICT',
                ],
            ]
        );
    }

    private function createToolLifetimeLogs(): void
    {
        $this->createTable(
            'tool_lifetime_logs',
            [
                'tool_id'           => $this->foreignId(),
                'event_type'        => $this->varchar(30),
                'previous_lifetime' => $this->unsignedInteger(),
                'new_lifetime'      => $this->unsignedInteger(),
                'quantity' => [
                    'type' => 'INT',
                    'null' => true,
                ],
                'reason' => $this->text(),
                'actor_user_id' => [
                    'type'     => 'INT',
                    'unsigned' => true,
                    'null'     => true,
                ],
                'occurred_at' => $this->datetime(false),
            ],
            indexes: [
                ['tool_id', 'occurred_at'],
            ],
            foreignKeys: [
                ['tool_id', 'tools', 'RESTRICT'],
                ['actor_user_id', 'users', 'SET NULL'],
            ]
        );
    }

    private function createProductionApiEvents(): void
    {
        $this->createTable(
            'production_api_events',
            [
                'device_id' => $this->foreignId(),

                /*
                 * VARBINARY membuat event_id case-sensitive.
                 * "event-A" dan "event-a" merupakan dua ID berbeda.
                 */
                'event_id' => [
                    'type'       => 'VARBINARY',
                    'constraint' => 80,
                ],
                'request_hash' => [
                    'type'       => 'CHAR',
                    'constraint' => 64,
                ],
                'response_json' => [
                    'type' => 'LONGTEXT',
                ],
                'created_at' => $this->datetime(false),
            ],
            unique: [
                ['device_id', 'event_id'],
            ],
            indexes: [
                ['created_at'],
            ],
            foreignKeys: [
                ['device_id', 'tpms_devices', 'RESTRICT'],
            ],
            timestamps: false
        );
    }

    private function createProductionAlarms(): void
    {
        $this->createTable(
            'production_alarms',
            [
                'production_id' => $this->foreignId(),
                'tool_id'       => $this->foreignId(true),
                'kind'          => $this->varchar(40),
                'severity'      => $this->varchar(20),
                'message'       => $this->varchar(500),
                'created_at'    => $this->datetime(false),
                'resolved_at'   => $this->datetime(),
            ],
            indexes: [
                ['production_id', 'resolved_at'],
                ['severity', 'resolved_at'],
            ],
            foreignKeys: [
                ['production_id', 'productions', 'RESTRICT'],
                ['tool_id', 'tools', 'RESTRICT'],
            ],
            timestamps: false
        );
    }

    private function createRuntimeLock(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'INT',
            ],
        ]);

        $this->forge->addKey('id', true);

        $this->forge->createTable(
            'tpms_runtime_lock',
            false,
            ['ENGINE' => 'InnoDB']
        );

        /*
         * Digunakan service dengan:
         * SELECT id FROM tpms_runtime_lock WHERE id = 1 FOR UPDATE
         */
        if (!$this->db->table('tpms_runtime_lock')->insert(['id' => 1])) {
            throw new RuntimeException(
                'Gagal membuat baris lock transaksi TPMS.'
            );
        }
    }

    /**
     * Membuat tabel dengan primary key, index, dan foreign key.
     *
     * Format foreignKeys:
     * [nama_kolom, tabel_referensi, aturan_on_delete]
     */
    private function createTable(
        string $table,
        array $fields,
        array $unique = [],
        array $indexes = [],
        array $foreignKeys = [],
        bool $timestamps = true
    ): void {
        $columns = array_merge(
            [
                'id' => [
                    'type'           => 'BIGINT',
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
            ],
            $fields
        );

        if ($timestamps) {
            $columns += [
                'created_at' => $this->datetime(),
                'updated_at' => $this->datetime(),
            ];
        }

        $this->forge->addField($columns);
        $this->forge->addKey('id', true);

        foreach ($unique as $index => $columnNames) {
            $this->forge->addUniqueKey(
                $columnNames,
                'uq_' . $table . '_' . ($index + 1)
            );
        }

        foreach ($indexes as $index => $columnNames) {
            $this->forge->addKey(
                $columnNames,
                false,
                false,
                'idx_' . $table . '_' . ($index + 1)
            );
        }

        foreach ($foreignKeys as $index => $foreignKey) {
            [$column, $referenceTable, $onDelete] = $foreignKey;

            /*
             * Urutan parameter CI4:
             * field, table, reference, ON UPDATE, ON DELETE, name.
             */
            $this->forge->addForeignKey(
                $column,
                $referenceTable,
                'id',
                'RESTRICT',
                $onDelete,
                'fk_' . $table . '_' . ($index + 1)
            );
        }

        $this->forge->createTable(
            $table,
            false,
            ['ENGINE' => 'InnoDB']
        );
    }


    /**
     * CI4/MySQL dapat memakai DBCollat berbeda dari default database.
     * Semua tabel bootstrap dinormalisasi sebelum ada JOIN berbasis VARCHAR.
     * Ini mencegah utf8mb4_unicode_ci vs utf8mb4_general_ci.
     */
    private function normalizeBaseCollations(): void
    {
        $this->db->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

        $baseTables = [
            'users',
            'machines',
            'production_slots',
            'tpms_devices',
            'tpms_assignment_histories',
            'customers',
            'materials',
            'rack_tools',
            'tools',
            'parts',
            'rack_tool_parts',
            'rack_tool_tools',
            'part_tools',
            'part_machine_assignments',
            'employees',
            'shifts',
            'employee_shift_assignments',
            'productions',
            'production_shift_details',
            'production_tool_usages',
            'production_nc_details',
            'tool_lifetime_logs',
            'production_api_events',
            'production_alarms',
        ];

        foreach ($baseTables as $table) {
            $this->db->query(
                "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
            );
        }
    }

    /**
     * Final state dari migration alarm flow, planning, quantity contract,
     * target plan, process master, dan reporting columns.
     *
     * Database bootstrap diasumsikan kosong, jadi backfill migration lama
     * sengaja tidak dibawa.
     */
    private function applyFinalCoreSchema(): void
    {
        // Users / RFID admin access.
        $this->db->query(
            "ALTER TABLE `users`
             ADD COLUMN `rfid_uid` VARCHAR(100) NULL AFTER `role`,
             ADD UNIQUE KEY `uq_users_rfid_uid` (`rfid_uid`)"
        );

        // Machine master final state.
        $this->db->query(
            "ALTER TABLE `machines`
             ADD COLUMN `tonnage` INT UNSIGNED NULL AFTER `year`,
             ADD COLUMN `screw_diameter` DECIMAL(10,3) UNSIGNED NULL AFTER `tonnage`,
             ADD COLUMN `registration_code` VARCHAR(50) NOT NULL AFTER `code`,
             ADD UNIQUE KEY `uq_machine_registration` (`registration_code`)"
        );

        // Planning employee role support.
        $this->db->query(
            "ALTER TABLE `employee_shift_assignments`
             ADD COLUMN `assignment_role` VARCHAR(60) NOT NULL DEFAULT 'operator' AFTER `shift_id`,
             ADD UNIQUE KEY `uq_employee_shift_assignments_machine_role`
               (`machine_id`, `assignment_date`, `shift_id`, `assignment_role`),
             ADD KEY `idx_employee_shift_assignments_role_date`
               (`assignment_role`, `assignment_date`, `is_active`)"
        );
        $this->db->query(
            "ALTER TABLE `employee_shift_assignments`
             DROP INDEX `uq_employee_shift_assignments_1`"
        );

        // Parts final process + production planning configuration.
        $this->db->query(
            "ALTER TABLE `parts`
             ADD COLUMN `target_duration_days` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `target_production`,
             ADD COLUMN `shifts_per_day` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `target_duration_days`,
             ADD COLUMN `process_type` VARCHAR(40) NOT NULL DEFAULT 'single_auto' AFTER `shifts_per_day`,
             ADD COLUMN `process_count` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `process_type`,
             ADD COLUMN `process_mode` VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER `process_count`,
             ADD COLUMN `next_grinding` TINYINT(1) NOT NULL DEFAULT 0 AFTER `process_mode`"
        );

        // Shift detail alarm-flow + daily rollover + counter contract.
        $this->db->query(
            "ALTER TABLE `production_shift_details`
             ADD COLUMN `work_date` DATE NULL AFTER `shift_id`,
             ADD COLUMN `counter_epoch` VARCHAR(64) NOT NULL AFTER `work_date`,
             ADD COLUMN `actual_qty` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `target_qty`,
             ADD COLUMN `unit_head_employee_id` BIGINT UNSIGNED NULL AFTER `operator_employee_id`,
             MODIFY COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'running',
             ADD KEY `idx_prod_shift_counter_epoch` (`counter_epoch`),
             ADD CONSTRAINT `fk_prod_shift_unit_head`
               FOREIGN KEY (`unit_head_employee_id`) REFERENCES `employees`(`id`)
               ON UPDATE CASCADE ON DELETE RESTRICT"
        );
        $this->db->query(
            "ALTER TABLE `production_shift_details`
             ADD UNIQUE KEY `uq_production_shift_work_date`
               (`production_id`, `work_date`, `shift_id`)"
        );
        $this->db->query(
            "ALTER TABLE `production_shift_details`
             DROP INDEX `uq_production_shift_details_1`"
        );

        // Productions final quantity, plan, process, and cycle targets.
        $this->db->query(
            "ALTER TABLE `productions`
             ADD COLUMN `production_batch_id` BIGINT UNSIGNED NULL AFTER `id`,
             ADD COLUMN `part_process_id` BIGINT UNSIGNED NULL AFTER `part_id`,
             ADD COLUMN `process_no` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `part_process_id`,
             ADD COLUMN `process_name_snapshot` VARCHAR(120) NOT NULL DEFAULT 'Single Process' AFTER `process_no`,
             ADD COLUMN `process_mode_snapshot` VARCHAR(10) NOT NULL DEFAULT 'auto' AFTER `process_name_snapshot`,
             ADD COLUMN `target_duration_days` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `target_qty`,
             ADD COLUMN `shifts_per_day` TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER `target_duration_days`,
             ADD COLUMN `input_good_qty_snapshot` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `shifts_per_day`,
             ADD COLUMN `good_qty` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `actual_qty`,
             ADD COLUMN `reject_qty` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `good_qty`,
             ADD COLUMN `mode` VARCHAR(20) NOT NULL DEFAULT 'production' AFTER `session_state`,
             ADD COLUMN `plan_basis` VARCHAR(30) NOT NULL DEFAULT 'legacy' AFTER `mode`,
             ADD COLUMN `standard_machine_time_ms` INT UNSIGNED NULL AFTER `plan_basis`,
             ADD COLUMN `standard_loading_time_ms` INT UNSIGNED NULL AFTER `standard_machine_time_ms`"
        );

        // Alarm final state. Legacy production_id tetap nullable untuk audit.
        $this->db->query(
            "ALTER TABLE `production_alarms`
             ADD COLUMN `production_shift_detail_id` BIGINT UNSIGNED NULL AFTER `production_id`,
             MODIFY COLUMN `production_id` BIGINT UNSIGNED NULL,
             MODIFY COLUMN `severity` VARCHAR(32) NOT NULL DEFAULT 'warning',
             ADD COLUMN `effect` VARCHAR(32) NOT NULL DEFAULT 'notify' AFTER `severity`,
             ADD COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'active' AFTER `effect`,
             ADD COLUMN `resolution_notes` VARCHAR(500) NULL AFTER `message`,
             ADD COLUMN `updated_at` DATETIME NULL AFTER `created_at`,
             ADD KEY `idx_prod_alarm_shift_detail`
               (`production_shift_detail_id`, `resolved_at`, `effect`),
             ADD CONSTRAINT `fk_prod_alarm_shift_detail`
               FOREIGN KEY (`production_shift_detail_id`)
               REFERENCES `production_shift_details`(`id`)
               ON UPDATE CASCADE ON DELETE CASCADE"
        );

        // Tool usage immutable snapshots for reporting/history.
        $this->db->query(
            "ALTER TABLE `production_tool_usages`
             ADD COLUMN `tool_code_snapshot` VARCHAR(80) NULL AFTER `tool_id`,
             ADD COLUMN `tool_name_snapshot` VARCHAR(150) NULL AFTER `tool_code_snapshot`,
             ADD COLUMN `tool_type_code_snapshot` VARCHAR(100) NULL AFTER `tool_name_snapshot`,
             ADD COLUMN `cutting_edge_snapshot` SMALLINT UNSIGNED NULL AFTER `tool_type_code_snapshot`,
             ADD COLUMN `current_edge_snapshot` SMALLINT UNSIGNED NULL AFTER `cutting_edge_snapshot`"
        );
    }

    private function createFinalProcessDomainTables(): void
    {
        $this->db->query(
            "CREATE TABLE `part_processes` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `part_id` BIGINT UNSIGNED NOT NULL,
                `process_no` TINYINT UNSIGNED NOT NULL,
                `process_name` VARCHAR(120) NOT NULL,
                `process_mode` VARCHAR(10) NOT NULL DEFAULT 'auto',
                `is_next_grinding` TINYINT(1) NOT NULL DEFAULT 0,
                `machine_time_target_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `loading_time_target_ms` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_part_process_no` (`part_id`, `process_no`),
                KEY `idx_part_process_status` (`part_id`, `status`),
                CONSTRAINT `fk_part_process_part`
                    FOREIGN KEY (`part_id`) REFERENCES `parts`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `production_batches` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `batch_code` VARCHAR(70) NOT NULL,
                `part_id` BIGINT UNSIGNED NOT NULL,
                `rack_tool_id` BIGINT UNSIGNED NULL,
                `target_qty` INT UNSIGNED NOT NULL DEFAULT 0,
                `process_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `current_process_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `final_good_qty` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                `started_at` DATETIME NULL,
                `completed_at` DATETIME NULL,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_production_batch_code` (`batch_code`),
                KEY `idx_batch_part_status` (`part_id`, `status`),
                CONSTRAINT `fk_batch_part`
                    FOREIGN KEY (`part_id`) REFERENCES `parts`(`id`)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_batch_rack`
                    FOREIGN KEY (`rack_tool_id`) REFERENCES `rack_tools`(`id`)
                    ON UPDATE CASCADE ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "ALTER TABLE `productions`
             ADD KEY `idx_production_batch` (`production_batch_id`),
             ADD KEY `idx_production_part_process` (`part_process_id`),
             ADD KEY `idx_production_part_process_status` (`part_id`, `process_no`, `status`),
             ADD CONSTRAINT `fk_production_batch`
               FOREIGN KEY (`production_batch_id`) REFERENCES `production_batches`(`id`)
               ON UPDATE CASCADE ON DELETE SET NULL,
             ADD CONSTRAINT `fk_production_part_process`
               FOREIGN KEY (`part_process_id`) REFERENCES `part_processes`(`id`)
               ON UPDATE CASCADE ON DELETE SET NULL"
        );

        $this->db->query(
            "CREATE TABLE `rack_tool_runtime_locks` (
                `rack_tool_id` BIGINT UNSIGNED NOT NULL,
                `machine_id` BIGINT UNSIGNED NOT NULL,
                `device_id` BIGINT UNSIGNED NULL,
                `production_id` BIGINT UNSIGNED NULL,
                `production_batch_id` BIGINT UNSIGNED NULL,
                `acquired_at` DATETIME NOT NULL,
                `heartbeat_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`rack_tool_id`),
                KEY `idx_rack_lock_machine` (`machine_id`, `heartbeat_at`),
                CONSTRAINT `fk_rack_lock_rack`
                    FOREIGN KEY (`rack_tool_id`) REFERENCES `rack_tools`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_rack_lock_machine`
                    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`)
                    ON UPDATE CASCADE ON DELETE RESTRICT,
                CONSTRAINT `fk_rack_lock_device`
                    FOREIGN KEY (`device_id`) REFERENCES `tpms_devices`(`id`)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_rack_lock_production`
                    FOREIGN KEY (`production_id`) REFERENCES `productions`(`id`)
                    ON UPDATE CASCADE ON DELETE SET NULL,
                CONSTRAINT `fk_rack_lock_batch`
                    FOREIGN KEY (`production_batch_id`) REFERENCES `production_batches`(`id`)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createFinalOperationalLogTables(): void
    {
        $this->db->query(
            "CREATE TABLE `tpms_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tpms_device_id` BIGINT UNSIGNED NULL,
                `slot_id` BIGINT UNSIGNED NULL,
                `machine_id` BIGINT UNSIGNED NULL,
                `mac_address` VARCHAR(17) NULL,
                `event_type` VARCHAR(60) NOT NULL,
                `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
                `source` VARCHAR(50) NOT NULL DEFAULT 'system',
                `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
                `actor_user_id` INT UNSIGNED NULL,
                `message` VARCHAR(500) NULL,
                `before_data` LONGTEXT NULL,
                `after_data` LONGTEXT NULL,
                `metadata` LONGTEXT NULL,
                `occurred_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_tpms_logs_device_time` (`tpms_device_id`, `occurred_at`),
                KEY `idx_tpms_logs_event_time` (`event_type`, `occurred_at`),
                KEY `idx_tpms_logs_machine_time` (`machine_id`, `occurred_at`),
                CONSTRAINT `fk_tpms_logs_device`
                    FOREIGN KEY (`tpms_device_id`) REFERENCES `tpms_devices`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tpms_logs_slot`
                    FOREIGN KEY (`slot_id`) REFERENCES `production_slots`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tpms_logs_machine`
                    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tpms_logs_user`
                    FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `tool_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tool_id` BIGINT UNSIGNED NULL,
                `production_id` BIGINT UNSIGNED NULL,
                `production_shift_detail_id` BIGINT UNSIGNED NULL,
                `tool_code` VARCHAR(80) NULL,
                `event_type` VARCHAR(60) NOT NULL,
                `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
                `source` VARCHAR(50) NOT NULL DEFAULT 'system',
                `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
                `actor_user_id` INT UNSIGNED NULL,
                `previous_lifetime` INT UNSIGNED NULL,
                `new_lifetime` INT UNSIGNED NULL,
                `quantity` INT NULL,
                `message` VARCHAR(500) NULL,
                `before_data` LONGTEXT NULL,
                `after_data` LONGTEXT NULL,
                `metadata` LONGTEXT NULL,
                `occurred_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_tool_logs_tool_time` (`tool_id`, `occurred_at`),
                KEY `idx_tool_logs_event_time` (`event_type`, `occurred_at`),
                CONSTRAINT `fk_tool_logs_tool`
                    FOREIGN KEY (`tool_id`) REFERENCES `tools`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tool_logs_production`
                    FOREIGN KEY (`production_id`) REFERENCES `productions`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tool_logs_shift_detail`
                    FOREIGN KEY (`production_shift_detail_id`) REFERENCES `production_shift_details`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_tool_logs_user`
                    FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `machine_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `machine_id` BIGINT UNSIGNED NULL,
                `slot_id` BIGINT UNSIGNED NULL,
                `tpms_device_id` BIGINT UNSIGNED NULL,
                `machine_code` VARCHAR(50) NULL,
                `event_type` VARCHAR(60) NOT NULL,
                `severity` VARCHAR(20) NOT NULL DEFAULT 'info',
                `source` VARCHAR(50) NOT NULL DEFAULT 'system',
                `actor_type` VARCHAR(20) NOT NULL DEFAULT 'system',
                `actor_user_id` INT UNSIGNED NULL,
                `previous_status` VARCHAR(50) NULL,
                `new_status` VARCHAR(50) NULL,
                `message` VARCHAR(500) NULL,
                `before_data` LONGTEXT NULL,
                `after_data` LONGTEXT NULL,
                `metadata` LONGTEXT NULL,
                `occurred_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_machine_logs_machine_time` (`machine_id`, `occurred_at`),
                KEY `idx_machine_logs_event_time` (`event_type`, `occurred_at`),
                CONSTRAINT `fk_machine_logs_machine`
                    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_machine_logs_slot`
                    FOREIGN KEY (`slot_id`) REFERENCES `production_slots`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_machine_logs_device`
                    FOREIGN KEY (`tpms_device_id`) REFERENCES `tpms_devices`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL,
                CONSTRAINT `fk_machine_logs_user`
                    FOREIGN KEY (`actor_user_id`) REFERENCES `users`(`id`)
                    ON UPDATE RESTRICT ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createFinalCycleTables(): void
    {
        $this->db->query(
            "CREATE TABLE `production_cycles` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `production_id` BIGINT UNSIGNED NOT NULL,
                `production_shift_detail_id` BIGINT UNSIGNED NOT NULL,
                `part_process_id` BIGINT UNSIGNED NULL,
                `sequence_no` INT UNSIGNED NOT NULL,
                `machine_started_at` DATETIME NULL,
                `machine_stopped_at` DATETIME NULL,
                `start_tick_ms` BIGINT UNSIGNED NULL,
                `stop_tick_ms` BIGINT UNSIGNED NULL,
                `machine_time_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `loading_time_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `cycle_time_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `counter_total_snapshot` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(30) NOT NULL DEFAULT 'started',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_production_cycle_sequence`
                    (`production_shift_detail_id`, `sequence_no`),
                KEY `idx_production_cycle_open`
                    (`production_shift_detail_id`, `status`),
                CONSTRAINT `fk_cycle_production`
                    FOREIGN KEY (`production_id`) REFERENCES `productions`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_cycle_shift_detail`
                    FOREIGN KEY (`production_shift_detail_id`) REFERENCES `production_shift_details`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_cycle_part_process`
                    FOREIGN KEY (`part_process_id`) REFERENCES `part_processes`(`id`)
                    ON UPDATE CASCADE ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `production_runtime_intervals` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `production_id` BIGINT UNSIGNED NOT NULL,
                `production_shift_detail_id` BIGINT UNSIGNED NOT NULL,
                `state` VARCHAR(30) NOT NULL,
                `source` VARCHAR(40) NOT NULL DEFAULT 'production_api',
                `started_at` DATETIME NOT NULL,
                `ended_at` DATETIME NULL,
                `duration_ms` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_runtime_interval_detail`
                    (`production_shift_detail_id`, `started_at`),
                KEY `idx_runtime_interval_state`
                    (`state`, `started_at`),
                CONSTRAINT `fk_runtime_interval_production`
                    FOREIGN KEY (`production_id`) REFERENCES `productions`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE,
                CONSTRAINT `fk_runtime_interval_detail`
                    FOREIGN KEY (`production_shift_detail_id`) REFERENCES `production_shift_details`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createFinalRfidTables(): void
    {
        $this->db->query(
            "CREATE TABLE `rfid_scan_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tpms_device_id` BIGINT UNSIGNED NOT NULL,
                `uid` VARCHAR(100) NOT NULL,
                `purpose` VARCHAR(20) NOT NULL DEFAULT 'generic',
                `scanned_at` DATETIME NOT NULL,
                `created_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                KEY `idx_rfid_scan_device` (`tpms_device_id`, `id`),
                KEY `idx_rfid_scan_purpose` (`purpose`, `id`),
                CONSTRAINT `fk_rfid_scan_device`
                    FOREIGN KEY (`tpms_device_id`) REFERENCES `tpms_devices`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createFinalMachineToolDomain(): void
    {
        $this->db->query(
            "CREATE TABLE `tool_types` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(100) NOT NULL,
                `name` VARCHAR(150) NOT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT 'active',
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tool_types_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "ALTER TABLE `tools`
             ADD COLUMN `cutting_edge` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `type`,
             ADD COLUMN `current_edge` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `cutting_edge`,
             ADD COLUMN `holder` VARCHAR(120) NULL AFTER `current_edge`,
             ADD COLUMN `tool_type_id` BIGINT UNSIGNED NOT NULL AFTER `holder`,
             ADD COLUMN `legacy_metadata_json` LONGTEXT NULL AFTER `tool_type_id`,
             ADD CONSTRAINT `fk_tool_type`
               FOREIGN KEY (`tool_type_id`) REFERENCES `tool_types`(`id`)
               ON DELETE RESTRICT,
             ADD CONSTRAINT `ck_tool_edges`
               CHECK (`cutting_edge` >= 1 AND `current_edge` >= 1 AND `current_edge` <= `cutting_edge`)"
        );

        $this->db->query(
            "CREATE TABLE `machine_tools` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `machine_id` BIGINT UNSIGNED NOT NULL,
                `tool_id` BIGINT UNSIGNED NOT NULL,
                `assigned_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_physical_tool` (`tool_id`),
                KEY `idx_machine_tools_machine_id` (`machine_id`),
                CONSTRAINT `fk_machine_tool_machine`
                    FOREIGN KEY (`machine_id`) REFERENCES `machines`(`id`)
                    ON DELETE RESTRICT,
                CONSTRAINT `fk_machine_tool_tool`
                    FOREIGN KEY (`tool_id`) REFERENCES `tools`(`id`)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `process_tool_requirements` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `part_process_id` BIGINT UNSIGNED NOT NULL,
                `tool_type_id` BIGINT UNSIGNED NOT NULL,
                `position` VARCHAR(30) NULL,
                `set_lifetime` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_process_tool_type` (`part_process_id`, `tool_type_id`),
                UNIQUE KEY `uq_process_tool_position` (`part_process_id`, `position`),
                CONSTRAINT `fk_requirement_process`
                    FOREIGN KEY (`part_process_id`) REFERENCES `part_processes`(`id`)
                    ON DELETE CASCADE,
                CONSTRAINT `fk_requirement_type`
                    FOREIGN KEY (`tool_type_id`) REFERENCES `tool_types`(`id`)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->db->query(
            "CREATE TABLE `tpms_refactor_issues` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `issue_key` VARCHAR(100) NOT NULL,
                `message` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_tpms_refactor_issue_key` (`issue_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createFinalDeviceRuntimeLocks(): void
    {
        $this->db->query(
            "CREATE TABLE `tpms_device_runtime_locks` (
                `device_id` BIGINT UNSIGNED NOT NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`device_id`),
                CONSTRAINT `fk_tpms_device_runtime_locks_device`
                    FOREIGN KEY (`device_id`) REFERENCES `tpms_devices`(`id`)
                    ON UPDATE CASCADE ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function addFinalIndexes(): void
    {
        // Monitoring indexes.
        $this->db->query(
            "CREATE INDEX `idx_monitoring_production_machine`
             ON `productions` (`machine_id`, `completed_at`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_monitoring_shift_detail`
             ON `production_shift_details` (`production_id`, `status`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_monitoring_device_slot`
             ON `tpms_devices` (`current_slot_id`, `last_seen_at`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_monitoring_tool_usage`
             ON `production_tool_usages` (`production_shift_detail_id`, `tool_id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_monitoring_alarm_active`
             ON `production_alarms` (`production_shift_detail_id`, `resolved_at`, `id`)"
        );

        // Reporting / retention indexes.
        $this->db->query(
            "CREATE INDEX `idx_prod_report_work_date`
             ON `production_shift_details` (`work_date`, `shift_id`, `production_id`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_tpms_logs_occurred_id`
             ON `tpms_logs` (`occurred_at`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_tool_logs_occurred_id`
             ON `tool_logs` (`occurred_at`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_machine_logs_occurred_id`
             ON `machine_logs` (`occurred_at`, `id`)"
        );
        $this->db->query(
            "CREATE INDEX `idx_tool_lifetime_event_time`
             ON `tool_lifetime_logs` (`event_type`, `occurred_at`, `id`)"
        );
    }

    private function varchar(
        int $length,
        bool $nullable = false
    ): array {
        return [
            'type'       => 'VARCHAR',
            'constraint' => $length,
            'null'       => $nullable,
        ];
    }

    private function foreignId(bool $nullable = false): array
    {
        return [
            'type'     => 'BIGINT',
            'unsigned' => true,
            'null'     => $nullable,
        ];
    }

    private function unsignedInteger(): array
    {
        return [
            'type'     => 'INT',
            'unsigned' => true,
        ];
    }

    private function quantity(): array
    {
        return [
            'type'     => 'INT',
            'unsigned' => true,
            'default'  => 0,
        ];
    }

    private function datetime(bool $nullable = true): array
    {
        return [
            'type' => 'DATETIME',
            'null' => $nullable,
        ];
    }

    private function text(bool $nullable = true): array
    {
        return [
            'type' => 'TEXT',
            'null' => $nullable,
        ];
    }

    private function boolean(bool $default): array
    {
        return [
            'type'       => 'TINYINT',
            'constraint' => 1,
            'unsigned'   => true,
            'default'    => $default ? 1 : 0,
        ];
    }

    private function status(string $default): array
    {
        return [
            'type'       => 'VARCHAR',
            'constraint' => 20,
            'default'    => $default,
        ];
    }
}
