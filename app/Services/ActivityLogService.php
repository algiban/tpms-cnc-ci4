<?php

namespace App\Services;

use App\Models\MachineLogModel;
use App\Models\ToolLogModel;
use App\Models\TpmsLogModel;

final class ActivityLogService
{
    public function tpms(
        int $deviceId,
        string $event,
        ?array $before = null,
        ?array $after = null,
        array $context = []
    ): void {
        $db = db_connect();

        $device = $db
            ->table('tpms_devices d')
            ->select(
                '
                d.id,
                d.mac_address,
                d.current_slot_id,
                s.machine_id
                '
            )
            ->join(
                'production_slots s',
                's.id = d.current_slot_id',
                'left'
            )
            ->where('d.id', $deviceId)
            ->get()
            ->getRowArray();

        if (! $device) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $actorUserId = $this->resolveActorUserId($db, $context);

        (new TpmsLogModel())->insert([
            'tpms_device_id' => $deviceId,

            'slot_id' =>
            $context['slot_id']
                ?? $device['current_slot_id']
                ?? null,

            'machine_id' =>
            $context['machine_id']
                ?? $device['machine_id']
                ?? null,

            'mac_address' =>
            $device['mac_address'],

            'event_type' => $event,

            'severity' =>
            $context['severity']
                ?? 'info',

            'source' =>
            $context['source']
                ?? 'system',

            'actor_type' =>
            $context['actor_type']
                ?? ($actorUserId !== null ? 'user' : 'system'),

            'actor_user_id' => $actorUserId,

            'message' =>
            $context['message']
                ?? null,

            'before_data' =>
            $this->json($before),

            'after_data' =>
            $this->json($after),

            'metadata' =>
            $this->json(
                $context['metadata']
                    ?? null
            ),

            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function tool(
        int $toolId,
        string $event,
        ?array $before = null,
        ?array $after = null,
        array $context = []
    ): void {
        $db = db_connect();

        $tool = $db
            ->table('tools')
            ->where('id', $toolId)
            ->get()
            ->getRowArray();

        $now = date('Y-m-d H:i:s');
        $actorUserId = $this->resolveActorUserId($db, $context);

        (new ToolLogModel())->insert([
            'tool_id' => $toolId,

            'production_id' =>
            $context['production_id']
                ?? null,

            'production_shift_detail_id' =>
            $context['production_shift_detail_id']
                ?? null,

            'tool_code' =>
            $tool['code']
                ?? $context['tool_code']
                ?? null,

            'event_type' => $event,

            'severity' =>
            $context['severity']
                ?? 'info',

            'source' =>
            $context['source']
                ?? 'system',

            'actor_type' =>
            $context['actor_type']
                ?? ($actorUserId !== null ? 'user' : 'system'),

            'actor_user_id' => $actorUserId,

            'previous_lifetime' =>
            $context['previous_lifetime']
                ?? null,

            'new_lifetime' =>
            $context['new_lifetime']
                ?? null,

            'quantity' =>
            $context['quantity']
                ?? null,

            'message' =>
            $context['message']
                ?? null,

            'before_data' =>
            $this->json($before),

            'after_data' =>
            $this->json($after),

            'metadata' =>
            $this->json(
                $context['metadata']
                    ?? null
            ),

            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    public function machine(
        int $machineId,
        string $event,
        ?array $before = null,
        ?array $after = null,
        array $context = []
    ): void {
        $db = db_connect();

        $machine = $db
            ->table('machines')
            ->where('id', $machineId)
            ->get()
            ->getRowArray();

        if (! $machine) {
            return;
        }

        $slot = $db
            ->table('production_slots')
            ->where('machine_id', $machineId)
            ->get()
            ->getRowArray();

        $device = null;

        if ($slot) {
            $device = $db
                ->table('tpms_devices')
                ->where(
                    'current_slot_id',
                    $slot['id']
                )
                ->orderBy(
                    'last_seen_at',
                    'DESC'
                )
                ->get()
                ->getRowArray();
        }

        $now = date('Y-m-d H:i:s');
        $actorUserId = $this->resolveActorUserId($db, $context);

        (new MachineLogModel())->insert([
            'machine_id' => $machineId,

            'slot_id' =>
            $context['slot_id']
                ?? $slot['id']
                ?? null,

            'tpms_device_id' =>
            $context['tpms_device_id']
                ?? $device['id']
                ?? null,

            'machine_code' =>
            $machine['code'],

            'event_type' => $event,

            'severity' =>
            $context['severity']
                ?? 'info',

            'source' =>
            $context['source']
                ?? 'system',

            'actor_type' =>
            $context['actor_type']
                ?? ($actorUserId !== null ? 'user' : 'system'),

            'actor_user_id' => $actorUserId,

            'previous_status' =>
            $context['previous_status']
                ?? $before['status']
                ?? null,

            'new_status' =>
            $context['new_status']
                ?? $after['status']
                ?? null,

            'message' =>
            $context['message']
                ?? null,

            'before_data' =>
            $this->json($before),

            'after_data' =>
            $this->json($after),

            'metadata' =>
            $this->json(
                $context['metadata']
                    ?? null
            ),

            'occurred_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * Ambil actor user yang benar-benar masih ada di tabel users.
     * Session lama dapat menyimpan user_id yang sudah dihapus sehingga FK log gagal.
     */
    private function resolveActorUserId(\CodeIgniter\Database\BaseConnection $db, array $context): ?int
    {
        $candidate = array_key_exists('actor_user_id', $context)
            ? $context['actor_user_id']
            : session()->get('user_id');

        if ($candidate === null || $candidate === '' || ! is_numeric($candidate)) {
            return null;
        }

        $userId = (int) $candidate;
        if ($userId < 1) {
            return null;
        }

        $exists = $db->table('users')
            ->select('id')
            ->where('id', $userId)
            ->get(1)
            ->getRowArray();

        return $exists ? $userId : null;
    }

    private function json(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return json_encode(
            $data,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
        );
    }
}
