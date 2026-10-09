<?php

namespace App\Controllers;

use App\Services\ActivityLogService;
use App\Services\ProductionRules;
use DomainException;
use RuntimeException;
use Throwable;

class ToolController extends BaseController
{
    public function store()
    {
        return $this->mutate('store');
    }

    public function update(int $id)
    {
        return $this->mutate('update', $id);
    }

    public function resetLifetime(int $id)
    {
        return $this->mutate('reset', $id);
    }

    public function changeEdge(int $id)
    {
        return $this->mutate('side_change', $id);
    }

    public function delete(int $id)
    {
        return $this->mutate('delete', $id);
    }

    private function mutate(string $action, ?int $id = null)
    {
        $db = db_connect();
        $db->transBegin();

        try {
            /*
             * Maintenance Tool tetap harus sinkron dengan writer ESP32.
             * Phase 1 memakai barrier seluruh per-device lock untuk operasi web
             * yang jarang terjadi, tanpa membuat traffic antar-TPMS saling antre.
             */
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();

            $tool = null;

            if ($id !== null) {
                $tool = $db
                    ->table('tools')
                    ->where('id', $id)
                    ->get()
                    ->getRowArray();

                if (! $tool) {
                    throw new DomainException('Tool tidak ditemukan.');
                }
            }

            if ($id !== null) {
                if (in_array($action, ['reset', 'side_change'], true)) {
                    $this->assertResetAllowed($db, $id);
                } elseif (in_array($action, ['update', 'delete'], true)) {
                    $this->assertToolNotInActiveProduction($db, $id);
                }
            }

            $now = date('Y-m-d H:i:s');

            /*
             * ============================================================
             * SIDE CHANGE
             * ============================================================
             * Physical tool tetap sama. Hanya sisi potong yang berpindah.
             * Lifetime sisi lama dianggap selesai dan sisi baru mulai dari 0.
             */
            if ($action === 'side_change') {
                $next=ProductionRules::integer($this->request->getPost('current_edge') ?: ((int)$tool['current_edge']+1),'Current Edge',1);
                (new \App\Services\ToolEdgeService($db))->change($id,$next,ProductionRules::text($this->request->getPost('reason'),'Reason',500),'web');
            }
            elseif ($action === 'reset') {
                $reason = ProductionRules::text(
                    $this->request->getPost('reason'),
                    'Alasan reset',
                    500
                );

                $previousLifetime = max(0, (int) ($tool['actual_lifetime'] ?? 0));
                $previousSide = max(1, (int) ($tool['current_edge'] ?? 1));
                $totalSide = max(1, (int) ($tool['cutting_edge'] ?? 1));
                $serviceUsage = $this->findServiceUsage($db, $id);

                $updated = $db
                    ->table('tools')
                    ->where('id', $id)
                    ->update([
                        'current_edge'    => 1,
                        'actual_lifetime' => 0,
                        'status'          => 'ready',
                        'updated_at'      => $now,
                    ]);

                if (! $updated) {
                    throw new RuntimeException('Reset lifetime tool gagal.');
                }

                if ($serviceUsage !== null) {
                    $db
                        ->table('production_tool_usages')
                        ->where('id', $serviceUsage['usage_id'])
                        ->update([
                            'end_lifetime' => 0,
                            'updated_at'   => $now,
                        ]);
                }

                $db
                    ->table('tool_lifetime_logs')
                    ->insert([
                        'tool_id'           => $id,
                        'event_type'        => 'reset',
                        'previous_lifetime' => $previousLifetime,
                        'new_lifetime'      => 0,
                        'quantity'          => null,
                        'reason'            => $reason,
                        'actor_user_id'     => session()->get('user_id') ?: null,
                        'occurred_at'       => $now,
                        'created_at'        => $now,
                    ]);

                $after = $db
                    ->table('tools')
                    ->where('id', $id)
                    ->get()
                    ->getRowArray();

                (new ActivityLogService())->tool(
                    $id,
                    'lifetime_reset',
                    $tool,
                    $after,
                    [
                        'source' => 'web',
                        'previous_lifetime' => $previousLifetime,
                        'new_lifetime' => 0,
                        'message' => sprintf(
                            'Reset/ganti tool fisik. Current Edge %d/%d -> 1/%d. Lifetime di-reset ke 0. Alasan: %s',
                            $previousSide,
                            $totalSide,
                            $totalSide,
                            $reason
                        ),
                        'metadata' => [
                            'change_type' => 'tool_reset_or_replacement',
                            'tool_replaced' => true,
                            'previous_side' => $previousSide,
                            'new_side' => 1,
                            'cutting_edge' => $totalSide,
                        ],
                    ]
                );
            }

            /* ============================================================
             * DELETE TOOL
             * ============================================================ */
            elseif ($action === 'delete') {
                if ($db->table('machine_tools')->where('tool_id',$id)->countAllResults()) throw new DomainException('Tool masih terpasang pada Machine. Unassign dahulu sebelum menghapus.');
                foreach (
                    [
                        'part_tools',
                        'rack_tool_tools',
                        'production_tool_usages',
                        'tool_lifetime_logs',
                    ] as $table
                ) {
                    if ($db->table($table)->where('tool_id', $id)->countAllResults() > 0) {
                        throw new DomainException(
                            'Tool memiliki relasi atau riwayat; tidak boleh dihapus.'
                        );
                    }
                }

                $db->table('tools')->where('id', $id)->delete();
            }

            /* ============================================================
             * STORE / UPDATE TOOL
             * ============================================================ */
            else {
                $code = strtoupper(
                    ProductionRules::text(
                        $this->request->getPost('code'),
                        'Kode',
                        80
                    )
                );

                $duplicateQuery = $db->table('tools')->where('code', $code);

                if ($id !== null) {
                    $duplicateQuery->where('id !=', $id);
                }

                if ($duplicateQuery->countAllResults() > 0) {
                    throw new DomainException('Kode tool sudah digunakan.');
                }

                $status = trim((string) ($this->request->getPost('status') ?: 'ready'));

                if (! in_array(
                    $status,
                    ['ready', 'warning', 'broken', 'maintenance', 'inactive'],
                    true
                )) {
                    throw new DomainException('Status tool tidak valid.');
                }

                $rawLifetime = $this->request->getPost('default_lifetime');
                $defaultLifetime = ($rawLifetime === '' || $rawLifetime === null)
                    ? null
                    : ProductionRules::integer($rawLifetime, 'Lifetime');

                $totalSide = ProductionRules::integer(
                    $this->request->getPost('cutting_edge') ?: 1,
                    'Cutting Edge',
                    1
                );

                if ($id !== null) {
                    $currentSide = max(1, (int) ($tool['current_edge'] ?? 1));

                    if ($totalSide < $currentSide) {
                        throw new DomainException(
                            sprintf(
                                'Cutting Edge tidak boleh lebih kecil dari Current Edge %d. Lakukan reset/ganti tool terlebih dahulu atau gunakan jumlah Cutting Edge minimal %d.',
                                $currentSide,
                                $currentSide
                            )
                        );
                    }
                }

                $typeId=ProductionRules::integer($this->request->getPost('tool_type_id'),'Tool Type',1);
                if (!$db->table('tool_types')->where('id',$typeId)->where('status','active')->countAllResults()) throw new DomainException('Tool Type tidak valid/aktif.');
                $currentEdge=ProductionRules::integer($this->request->getPost('current_edge') ?: ($tool['current_edge'] ?? 1),'Current Edge',1);
                if ($currentEdge > $totalSide) throw new DomainException('Current Edge tidak boleh melebihi Cutting Edge.');
                if ($id !== null && $currentEdge !== (int)$tool['current_edge']) throw new DomainException('Gunakan Change Edge agar pergantian mata potong tercatat.');
                if ($totalSide > 65535) throw new DomainException('Cutting Edge maksimal 65535.');
                $payload = [
                    'code' => $code,
                    'name' => ProductionRules::text(
                        $this->request->getPost('name'),
                        'Nama',
                        150
                    ),
                    'tool_type_id' => $typeId,
                    'cutting_edge' => $totalSide,
                    'holder' => trim((string) $this->request->getPost('holder')) ?: null,
                    
                    'default_lifetime' => $defaultLifetime,
                    'status' => $status,
                    'notes' => trim((string) $this->request->getPost('notes')) ?: null,
                    'updated_at' => $now,
                ];

                if ($id !== null) {
                    $db->table('tools')->where('id', $id)->update($payload);
                } else {
                    $payload['current_edge'] = $currentEdge;
                    $payload['actual_lifetime'] = 0;
                    $payload['created_at'] = $now;
                    $db->table('tools')->insert($payload);
                }
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('Database gagal.');
            }

            $message = match ($action) {
                'side_change' => 'Edge tool berhasil diganti dan lifetime edge baru dimulai dari 0.',
                'reset' => 'Tool berhasil di-reset/diganti. Current Edge kembali ke 1.',
                'delete' => 'Tool berhasil dihapus.',
                'store' => 'Tool berhasil ditambahkan.',
                default => 'Data tool berhasil diperbarui.',
            };

            return redirect()
                ->to(site_url('master-data/tools'))
                ->with('success', $message);
        } catch (Throwable $e) {
            $db->transRollback();

            if (! $e instanceof DomainException) {
                log_message(
                    'error',
                    'ToolController: {message}',
                    ['message' => $e->getMessage()]
                );
            }

            return redirect()
                ->back()
                ->withInput()
                ->with(
                    'error',
                    $e instanceof DomainException
                        ? $e->getMessage()
                        : 'Penyimpanan tool gagal. Periksa log.'
                );
        }
    }

    /**
     * Update/delete tidak diperbolehkan ketika tool masih berada
     * pada production shift aktif.
     */
    private function assertToolNotInActiveProduction($db, int $toolId): void
    {
        $usage = $db
            ->table('production_tool_usages u')
            ->select(
                'u.id AS usage_id,
                 d.id AS detail_id,
                 d.status AS detail_status,
                 p.id AS production_id,
                 p.production_code'
            )
            ->join('production_shift_details d', 'd.id = u.production_shift_detail_id')
            ->join('productions p', 'p.id = d.production_id')
            ->where('u.tool_id', $toolId)
            ->whereIn(
                'd.status',
                ['running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting']
            )
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if ($usage) {
            throw new DomainException(
                'Tool sedang digunakan pada production '
                    . ($usage['production_code'] ?? '')
                    . ' dengan status '
                    . $usage['detail_status']
                    . '. Tool tidak dapat diubah atau dihapus.'
            );
        }
    }

    /**
     * Reset lifetime maupun pergantian side diperbolehkan jika:
     * - tool tidak sedang berada di shift aktif; atau
     * - shift service_required dan ada unresolved service_stop alarm
     *   untuk tool yang sama.
     */
    private function assertResetAllowed($db, int $toolId): void
    {
        $activeUsages = $db
            ->table('production_tool_usages u')
            ->select(
                'u.id AS usage_id,
                 d.id AS detail_id,
                 d.status AS detail_status,
                 p.id AS production_id,
                 p.production_code'
            )
            ->join('production_shift_details d', 'd.id = u.production_shift_detail_id')
            ->join('productions p', 'p.id = d.production_id')
            ->where('u.tool_id', $toolId)
            ->whereIn(
                'd.status',
                ['running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting']
            )
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getResultArray();

        if (empty($activeUsages)) {
            return;
        }

        if (count($activeUsages) > 1) {
            throw new DomainException(
                'Tool terdeteksi aktif pada lebih dari satu production shift. Periksa data production.'
            );
        }

        $usage = $activeUsages[0];

        if ($usage['detail_status'] === 'running') {
            throw new DomainException(
                'Tool masih digunakan pada production '
                    . ($usage['production_code'] ?? '')
                    . '. Trigger alarm critical/service terlebih dahulu.'
            );
        }

        if ($usage['detail_status'] === 'paused') {
            throw new DomainException(
                'Production hanya sedang paused. Pergantian side/reset lifetime hanya diperbolehkan pada critical service.'
            );
        }

        if ($usage['detail_status'] === 'awaiting_defects') {
            throw new DomainException(
                'Shift sedang menunggu input defect/NC. Selesaikan shift terlebih dahulu.'
            );
        }

        if ($usage['detail_status'] !== 'service_required') {
            throw new DomainException('Tool belum berada pada kondisi service.');
        }

        $alarm = $db
            ->table('production_alarms')
            ->where('production_shift_detail_id', $usage['detail_id'])
            ->where('tool_id', $toolId)
            ->where('effect', 'service_stop')
            ->where('resolved_at', null)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $alarm) {
            throw new DomainException(
                'Production sedang service, tetapi tidak ada critical service alarm aktif untuk tool ini.'
            );
        }
    }

    /**
     * Cari production_tool_usage ketika tool berada pada service_required.
     */
    private function findServiceUsage($db, int $toolId): ?array
    {
        $usage = $db
            ->table('production_tool_usages u')
            ->select(
                'u.id AS usage_id,
                 u.production_shift_detail_id,
                 u.tool_id,
                 u.set_lifetime_snapshot,
                 u.start_lifetime,
                 u.end_lifetime,
                 u.quantity_increment,
                 d.production_id,
                 d.status AS detail_status'
            )
            ->join('production_shift_details d', 'd.id = u.production_shift_detail_id')
            ->where('u.tool_id', $toolId)
            ->where('d.status', 'service_required')
            ->orderBy('d.id', 'DESC')
            ->get()
            ->getRowArray();

        if (! $usage) {
            return null;
        }

        $alarmExists = $db
            ->table('production_alarms')
            ->where('production_shift_detail_id', $usage['production_shift_detail_id'])
            ->where('tool_id', $toolId)
            ->where('effect', 'service_stop')
            ->where('resolved_at', null)
            ->countAllResults();

        return $alarmExists > 0 ? $usage : null;
    }
}
