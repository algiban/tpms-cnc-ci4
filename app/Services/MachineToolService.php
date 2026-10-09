<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

final class MachineToolService
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= db_connect();
    }

    /**
     * Low-level assignment helper. UI utama menggunakan syncMachine(), sehingga
     * ownership assignment tetap dari sisi Machine, bukan dari halaman Tool.
     */
    public function assign(int $toolId, int $machineId, bool $reassign = false): void
    {
        $this->db->transBegin();
        try {
            $this->lockRuntime();
            $this->assignUnlocked($toolId, $machineId, $reassign);
            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Assignment gagal.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Machine menjadi pemilik aksi assignment. Daftar Tool yang dikirim adalah
     * kondisi final physical Tool yang terpasang pada Machine tersebut.
     */
    public function syncMachine(int $machineId, array $toolIds): void
    {
        $normalized = [];
        foreach ($toolIds as $toolId) {
            $id = ProductionRules::integer($toolId, 'Tool', 1);
            if (isset($normalized[$id])) {
                throw new DomainException('Tool yang sama tidak boleh dipilih dua kali.', 422);
            }
            $normalized[$id] = $id;
        }
        $toolIds = array_values($normalized);
        sort($toolIds);

        $this->db->transBegin();
        try {
            $this->lockRuntime();

            $machine = $this->db->table('machines')
                ->where('id', $machineId)
                ->where('disposed_at', null)
                ->get()
                ->getRowArray();
            if (! $machine) {
                throw new DomainException('Machine tidak valid / sudah disposed.', 422);
            }

            $guard = new ProductionRuntimeGuard($this->db);
            $guard->assertMachineMutable($machineId, 'mengubah assignment tools');

            $currentRows = $this->db->table('machine_tools mt')
                ->select('mt.tool_id, t.code')
                ->join('tools t', 't.id = mt.tool_id')
                ->where('mt.machine_id', $machineId)
                ->get()
                ->getResultArray();
            $currentIds = array_map('intval', array_column($currentRows, 'tool_id'));
            sort($currentIds);

            $remove = array_values(array_diff($currentIds, $toolIds));
            $add = array_values(array_diff($toolIds, $currentIds));

            foreach ($remove as $toolId) {
                $guard->assertToolMutable($toolId, 'dilepas dari Machine');
                $before = $this->db->table('machine_tools')->where('tool_id', $toolId)->get()->getRowArray();
                $this->db->table('machine_tools')->where('machine_id', $machineId)->where('tool_id', $toolId)->delete();
                (new ActivityLogService())->tool(
                    $toolId,
                    'machine_unassignment',
                    $before,
                    null,
                    ['source' => 'web', 'message' => 'Machine melepas physical tool.']
                );
            }

            foreach ($add as $toolId) {
                $this->assignUnlocked($toolId, $machineId, false);
            }

            (new ActivityLogService())->machine(
                $machineId,
                'tools_assignment_updated',
                ['tool_ids' => $currentIds],
                ['tool_ids' => $toolIds],
                ['source' => 'web', 'message' => 'Daftar physical tools pada Machine diperbarui.']
            );

            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Assignment tools Machine gagal.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    public function unassign(int $toolId): void
    {
        $this->db->transBegin();
        try {
            $this->lockRuntime();
            $old = $this->db->table('machine_tools')->where('tool_id', $toolId)->get()->getRowArray();
            if (! $old) {
                $this->db->transCommit();
                return;
            }

            $guard = new ProductionRuntimeGuard($this->db);
            $guard->assertToolMutable($toolId, 'dilepas');
            $guard->assertMachineMutable((int) $old['machine_id'], 'melepas tool');
            $this->db->table('machine_tools')->where('tool_id', $toolId)->delete();
            (new ActivityLogService())->tool(
                $toolId,
                'machine_unassignment',
                $old,
                null,
                ['source' => 'web', 'message' => 'Machine melepas physical tool.']
            );

            if (! $this->db->transStatus()) {
                throw new \RuntimeException('Pelepasan tool gagal.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * Resolve physical Tool untuk satu Process.
     * - kebutuhan Type + Position berasal dari Part/Process
     * - physical Tool berasal dari daftar Tool yang diassign Machine
     */
    public function resolve(int $machineId, int $processId, bool $requireReady = true): array
    {
        $requirements = $this->db->table('process_tool_requirements r')
            ->select('r.*, tt.code type_code, tt.status type_status')
            ->join('tool_types tt', 'tt.id = r.tool_type_id')
            ->where('r.part_process_id', $processId)
            ->orderBy('r.position')
            ->orderBy('tt.code')
            ->get()
            ->getResultArray();

        if (! $requirements) {
            throw new DomainException('Process belum memiliki Required Tool Types. Lengkapi Master Part.', 409);
        }

        foreach ($requirements as $requirement) {
            if (trim((string) ($requirement['position'] ?? '')) === '') {
                throw new DomainException(
                    'Posisi Tool Type ' . ($requirement['type_code'] ?? '-') . ' belum diset pada Master Part.',
                    409
                );
            }
        }

        $installed = $this->db->table('machine_tools mt')
            ->select('mt.tool_id, t.*, tt.code type_code')
            ->join('tools t', 't.id = mt.tool_id')
            ->join('tool_types tt', 'tt.id = t.tool_type_id')
            ->where('mt.machine_id', $machineId)
            ->orderBy('t.code')
            ->get()
            ->getResultArray();

        $resolved = [];
        $missing = [];
        $blocked = [];

        foreach ($requirements as $requirement) {
            $candidate = null;
            foreach ($installed as $tool) {
                if ((int) $tool['tool_type_id'] !== (int) $requirement['tool_type_id']) {
                    continue;
                }
                if (($requirement['type_status'] ?? '') !== 'active') {
                    continue;
                }

                $remaining = (int) $requirement['set_lifetime'] - (int) $tool['actual_lifetime'];
                if ($requireReady) {
                    if (! in_array($tool['status'], ['ready', 'warning'], true)) {
                        $blocked[$requirement['type_code']][] = sprintf(
                            '%s status %s',
                            $tool['code'],
                            $tool['status']
                        );
                        continue;
                    }
                    if ($remaining <= 0) {
                        $blocked[$requirement['type_code']][] = sprintf(
                            '%s lifetime habis (actual %d / set %d)',
                            $tool['code'],
                            (int) $tool['actual_lifetime'],
                            (int) $requirement['set_lifetime']
                        );
                        continue;
                    }
                }

                $candidate = $tool;
                break;
            }

            if (! $candidate) {
                $missing[] = $requirement['type_code'];
                continue;
            }

            $candidate['tool_id'] = (int) $candidate['tool_id'];
            $candidate['position'] = strtoupper(trim((string) $requirement['position']));
            $candidate['set_lifetime'] = (int) $requirement['set_lifetime'];
            $candidate['remaining'] = $candidate['set_lifetime'] - (int) $candidate['actual_lifetime'];
            $candidate['lifetime_level'] = $candidate['remaining'] <= 0
                ? 'critical'
                : ($candidate['remaining'] <= 1
                    ? 'danger'
                    : ($candidate['remaining'] <= 5 ? 'warning' : 'ready'));
            $resolved[] = $candidate;
        }

        if ($missing) {
            $details = [];
            foreach ($missing as $typeCode) {
                $reasons = $blocked[$typeCode] ?? [];
                $details[] = $reasons
                    ? $typeCode . ' (' . implode('; ', array_unique($reasons)) . ')'
                    : $typeCode;
            }
            throw new DomainException(
                'Machine belum memenuhi kebutuhan Tool Type Process. Belum tersedia/siap: ' . implode(', ', $details) . '.',
                409
            );
        }

        return $resolved;
    }

    private function assignUnlocked(int $toolId, int $machineId, bool $reassign): void
    {
        $tool = $this->db->table('tools')->where('id', $toolId)->get()->getRowArray();
        $machine = $this->db->table('machines')->where('id', $machineId)->where('disposed_at', null)->get()->getRowArray();
        if (! $tool || ! $machine) {
            throw new DomainException('Tool atau Machine tidak valid.', 422);
        }

        $guard = new ProductionRuntimeGuard($this->db);
        $guard->assertToolMutable($toolId, 'dipindahkan');
        $guard->assertMachineMutable($machineId, 'mengubah assignment tools');

        $old = $this->db->table('machine_tools')->where('tool_id', $toolId)->get()->getRowArray();
        if ($old) {
            $oldMachineId = (int) $old['machine_id'];
            if ($oldMachineId === $machineId) {
                return;
            }

            $oldMachine = $this->db->table('machines')->select('code')->where('id', $oldMachineId)->get()->getRowArray();
            if (! $reassign) {
                throw new DomainException(
                    'Tool ' . $tool['code'] . ' masih diassign ke Machine ' . ($oldMachine['code'] ?? ('#' . $oldMachineId)) . '. Lepas dari Machine tersebut terlebih dahulu.',
                    409
                );
            }
            $guard->assertMachineMutable($oldMachineId, 'memindahkan tool');
            $this->db->table('machine_tools')->where('tool_id', $toolId)->delete();
        }

        $now = date('Y-m-d H:i:s');
        $this->db->table('machine_tools')->insert([
            'machine_id' => $machineId,
            'tool_id' => $toolId,
            'assigned_at' => $now,
            'updated_at' => $now,
        ]);

        (new ActivityLogService())->tool(
            $toolId,
            'machine_assignment',
            $old,
            ['machine_id' => $machineId],
            ['source' => 'web', 'message' => 'Machine mengassign physical tool.']
        );
    }

    private function lockRuntime(): void
    {
        // Rare master-data mutation: use the per-device maintenance barrier so
        // it still synchronizes with ESP production writers after Phase 1.
        (new TpmsRuntimeLockService($this->db))->lockAllDevices();
    }
}
