<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

final class PartConfigurationService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    // Caller owns transaction and the same runtime lock as production API.
    public function save(array $input, ?int $id = null): int
    {
        $existing = $id ? $this->db->table('parts')->where('id', $id)->get()->getRowArray() : null;
        if ($id && ! $existing) {
            throw new DomainException('Part tidak ditemukan.', 404);
        }
        if ($id) {
            (new ProductionRuntimeGuard($this->db))->assertPartMutable($id);
        }

        $number = strtoupper(ProductionRules::text($input['part_number'] ?? null, 'Part Number', 100));
        $duplicate = $this->db->table('parts')->where('part_number', $number);
        if ($id) {
            $duplicate->where('id !=', $id);
        }
        if ($duplicate->countAllResults()) {
            throw new DomainException('Part Number sudah digunakan.', 409);
        }

        $customer = ProductionRules::integer($input['customer_id'] ?? null, 'Customer', 1);
        if (! $this->db->table('customers')->where('id', $customer)->countAllResults()) {
            throw new DomainException('Customer tidak valid.');
        }

        $material = empty($input['material_id']) ? null : ProductionRules::integer($input['material_id'], 'Material', 1);
        if ($material && ! $this->db->table('materials')->where('id', $material)->countAllResults()) {
            throw new DomainException('Material tidak valid.');
        }

        $types = [
            'single_auto' => [1, 'auto', true],
            'single_manual' => [1, 'manual', true],
            'process_1_auto' => [1, 'auto', false],
            'process_2_auto' => [2, 'auto', false],
            'process_3_auto' => [3, 'auto', false],
            'process_1_manual' => [1, 'manual', false],
            'process_2_manual' => [2, 'manual', false],
            'process_3_manual' => [3, 'manual', false],
            'next_grinding' => [1, 'none', false],
        ];
        $type = (string) ($input['process_type'] ?? 'single_auto');
        if (! isset($types[$type])) {
            throw new DomainException('Pilihan Process tidak valid.');
        }
        [$count, $mode, $single] = $types[$type];

        $grinding = $type === 'next_grinding'
            || ($count > 1 && in_array(strtolower((string) ($input['next_grinding'] ?? '')), ['1', 'yes', 'true', 'on'], true));
        $shifts = ProductionRules::integer($input['shifts_per_day'] ?? 3, 'Shift per day', 1);
        $now = date('Y-m-d H:i:s');
        $configured = [];

        for ($no = 1; $no <= $count; $no++) {
            $machine = ProcessPlan::milliseconds($input['process_machine_time_seconds'][$no] ?? null, 'Machine Time P' . $no);
            $loading = ProcessPlan::milliseconds($input['process_loading_time_seconds'][$no] ?? null, 'Loading Time P' . $no);
            $plan = ProcessPlan::calculate($machine, $loading, $shifts);
            $req = $input['process_requirements'][$no] ?? [];
            if (! is_array($req) || ! $req) {
                throw new DomainException('P' . $no . ' wajib memiliki Required Tool Types.');
            }

            $valid = [];
            $positions = [];
            foreach ($req as $r) {
                if (! is_array($r)) {
                    throw new DomainException('Required Tool Types tidak valid.');
                }

                $toolTypeId = ProductionRules::integer($r['tool_type_id'] ?? null, 'Tool Type', 1);
                $position = strtoupper(ProductionRules::text($r['position'] ?? null, 'Position P' . $no, 30));
                $setLifetime = ProductionRules::integer($r['set_lifetime'] ?? null, 'Set Lifetime', 1);

                // Duplikat hanya dilarang di process yang sama. Process lain boleh
                // memakai Tool Type yang sama karena $valid dibuat ulang per Pn.
                if (isset($valid[$toolTypeId])) {
                    throw new DomainException('Tool Type duplikat pada P' . $no . '. Satu process harus memakai Tool Type yang berbeda.');
                }
                if (isset($positions[$position])) {
                    throw new DomainException('Position ' . $position . ' duplikat pada P' . $no . '.');
                }
                if (! $this->db->table('tool_types')->where('id', $toolTypeId)->where('status', 'active')->countAllResults()) {
                    throw new DomainException('Tool Type tidak aktif/tidak ditemukan.');
                }

                $valid[$toolTypeId] = [
                    'tool_type_id' => $toolTypeId,
                    'position' => $position,
                    'set_lifetime' => $setLifetime,
                ];
                $positions[$position] = true;
            }

            $isGrinding = $grinding && $no === $count;
            $configured[$no] = [
                'row' => [
                    'process_no' => $no,
                    'process_name' => $isGrinding ? 'Next Grinding' : ($single ? 'Single Process' : 'Process ' . $no),
                    'process_mode' => $isGrinding ? 'none' : $mode,
                    'is_next_grinding' => $isGrinding ? 1 : 0,
                    'machine_time_target_ms' => $machine,
                    'loading_time_target_ms' => $loading,
                    'status' => 'active',
                    'updated_at' => $now,
                ],
                'requirements' => $valid,
                'plan' => $plan,
            ];
        }

        $payload = [
            'part_number' => $number,
            'name' => ProductionRules::text($input['name'] ?? null, 'Part Name', 180),
            'customer_id' => $customer,
            'material_id' => $material,
            'process_type' => $type,
            'process_count' => $count,
            'process_mode' => $mode,
            'next_grinding' => $grinding ? 1 : 0,
            'shifts_per_day' => $shifts,
            'updated_at' => $now,
        ];

        // Legacy columns remain for history; no manual target/duration from input.
        if (! $id) {
            $payload += ['target_production' => 0, 'target_duration_days' => 1, 'status' => 'active', 'created_at' => $now];
            $this->db->table('parts')->insert($payload);
            $id = (int) $this->db->insertID();
        } else {
            $this->db->table('parts')->where('id', $id)->update($payload);
        }

        $this->db->table('part_processes')->where('part_id', $id)->update(['status' => 'inactive', 'updated_at' => $now]);
        foreach ($configured as $no => $config) {
            $old = $this->db->table('part_processes')->where('part_id', $id)->where('process_no', $no)->get()->getRowArray();
            $row = $config['row'];
            if ($old) {
                $processId = (int) $old['id'];
                $this->db->table('part_processes')->where('id', $processId)->update($row);
            } else {
                $row += ['part_id' => $id, 'created_at' => $now];
                $this->db->table('part_processes')->insert($row);
                $processId = (int) $this->db->insertID();
            }

            $this->db->table('process_tool_requirements')->where('part_process_id', $processId)->delete();
            foreach ($config['requirements'] as $requirement) {
                $this->db->table('process_tool_requirements')->insert($requirement + ['part_process_id' => $processId]);
            }
        }

        return $id;
    }
}
