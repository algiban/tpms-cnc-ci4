<?php

namespace App\Controllers;

use App\Models\MachineModel;
use App\Models\ProductionSlotModel;
use App\Services\ActivityLogService;
use App\Services\ProductionRuntimeGuard;
use DomainException;

class MachineController extends BaseController
{
    public function storeSlot()
    {
        if (
            ! $this->validate([
                'slot_no' => 'required|is_natural_no_zero|is_unique[production_slots.slot_no]',
            ])
        ) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors())->with('error', implode(' ', $this->validator->getErrors()));
        }

        $slotModel = new ProductionSlotModel();

        $slotModel->insert([
            'slot_no' => (int) $this->request->getPost('slot_no'),
            'name' => $this->nullableString('name'),
            'area' => $this->nullableString('area'),
            'status' => 'offline',
        ]);

        return redirect()
            ->to('/master-data/machines')
            ->with('success', 'Slot berhasil ditambahkan.');
    }

    public function storeMachine()
    {
        if (
            ! $this->validateData($this->machinePayload(), [
                'code' => 'required|max_length[50]|is_unique[machines.code]',
                'name' => 'required|max_length[100]',
                'registration_code' => 'required|max_length[50]|is_unique[machines.registration_code]',
                'serial_number' => 'permit_empty|max_length[100]|is_unique[machines.serial_number]',
            ])
        ) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors())->with('error', implode(' ', $this->validator->getErrors()));
        }

        $machineModel = new MachineModel();

        $payload = $this->machinePayload();

        $machineId = $machineModel->insert(
            $payload,
            true
        );

        (new ActivityLogService())->machine(
            (int) $machineId,
            'created',
            null,
            $machineModel->find($machineId),
            [
                'source' => 'web',
                'message' => 'Machine ditambahkan.',
            ]
        );

        return redirect()
            ->to('/master-data/machines')
            ->with('success', 'Machine berhasil ditambahkan.');
    }

    public function update(int $id)
    {
        $machineModel = new MachineModel();
        $machine = $machineModel->find($id);

        if (! $machine) {
            return redirect()
                ->back()
                ->with('error', 'Machine tidak ditemukan.');
        }

        if (
            ! $this->validateData($this->machinePayload(), [
                'code' => "required|max_length[50]|is_unique[machines.code,id,{$id}]",
                'name' => 'required|max_length[100]',
                'registration_code' => "required|max_length[50]|is_unique[machines.registration_code,id,{$id}]",
                'serial_number' => "permit_empty|max_length[100]|is_unique[machines.serial_number,id,{$id}]",
            ])
        ) {
            return redirect()
                ->back()
                ->withInput()
                ->with('errors', $this->validator->getErrors())->with('error', implode(' ', $this->validator->getErrors()));
        }

        try {
            (new ProductionRuntimeGuard())->assertMachineMutable($id, 'diubah');
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $before = $machine;

        $machineModel->update(
            $id,
            $this->machinePayload()
        );

        $after = $machineModel->find($id);

        (new ActivityLogService())->machine(
            $id,
            'updated',
            $before,
            $after,
            [
                'source' => 'web',
                'message' => 'Data machine diperbarui.',
            ]
        );
        return redirect()
            ->to('/master-data/machines')
            ->with('success', 'Machine berhasil diperbarui.');
    }

    public function assign(int $machineId)
    {
        $slotId = (int) $this->request->getPost('slot_id');

        $machineModel = new MachineModel();
        $slotModel = new ProductionSlotModel();

        $machine = $machineModel->find($machineId);
        $slot = $slotModel->find($slotId);

        if (! $machine || ! $slot) {
            return redirect()
                ->back()
                ->with('error', 'Machine atau slot tidak ditemukan.');
        }

        if (
            ! empty($slot['machine_id'])
            && (int) $slot['machine_id'] !== $machineId
        ) {
            return redirect()
                ->back()
                ->with('error', 'Slot tujuan sudah memiliki machine.');
        }

        $db = db_connect();

        $previousSlot = $db
            ->table('production_slots')
            ->where('machine_id', $machineId)
            ->get()
            ->getRowArray();

        try {
            $guard = new ProductionRuntimeGuard($db);
            $guard->assertMachineMutable($machineId, 'dipindahkan');
            $guard->assertSlotMutable($slotId, 'dipakai untuk assignment baru');
            if ($previousSlot && (int) $previousSlot['id'] !== $slotId) {
                $guard->assertSlotMutable((int) $previousSlot['id'], 'dilepas dari machine');
            }
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $db->transStart();

        // Lepaskan machine dari slot sebelumnya.
        $db->table('production_slots')
            ->where('machine_id', $machineId)
            ->update([
                'machine_id' => null,
                'status' => 'offline',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        // Assign machine ke slot baru.
        $slotModel->update($slotId, [
            'machine_id' => $machineId,
        ]);

        // Update status machine.
        $machineModel->update($machineId, [
            'status' => 'assigned',
        ]);

        (new ActivityLogService())->machine(
            $machineId,
            $previousSlot ? 'moved_slot' : 'assigned_slot',
            [
                'slot_id' =>
                $previousSlot['id']
                    ?? null,

                'slot_no' =>
                $previousSlot['slot_no']
                    ?? null,
            ],
            [
                'slot_id' =>
                $slot['id'],

                'slot_no' =>
                $slot['slot_no'],
            ],
            [
                'source' => 'web',

                'slot_id' =>
                $slot['id'],

                'message' =>
                $previousSlot
                    ? 'Machine dipindahkan ke slot lain.'
                    : 'Machine dipasang ke slot.',
            ]
        );
        $db->transComplete();

        return redirect()
            ->to('/master-data/machines')
            ->with('success', 'Machine berhasil di-assign ke slot.');
    }

    public function disposeFromSlot(int $slotId)
    {
        $slotModel = new ProductionSlotModel();
        $machineModel = new MachineModel();

        $slot = $slotModel->find($slotId);

        if (! $slot) {
            return redirect()
                ->back()
                ->with('error', 'Slot tidak ditemukan.');
        }

        $machineId = $slot['machine_id'];

        $db = db_connect();

        try {
            $guard = new ProductionRuntimeGuard($db);
            $guard->assertSlotMutable($slotId, 'dikosongkan');
            if ($machineId) {
                $guard->assertMachineMutable((int) $machineId, 'dilepas dari slot');
            }
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $db->transStart();

        // Lepaskan machine dari slot.
        $slotModel->update($slotId, [
            'machine_id' => null,
            'status' => 'offline',
        ]);

        // Kembalikan status machine menjadi available.
        if ($machineId) {
            $machineModel->update($machineId, [
                'status' => 'available',
            ]);
        }

        if ($machineId) {
            (new ActivityLogService())->machine(
                (int) $machineId,
                'removed_from_slot',
                [
                    'slot_id' =>
                    $slotId,

                    'slot_no' =>
                    $slot['slot_no']
                        ?? null,
                ],
                null,
                [
                    'source' => 'web',

                    'message' =>
                    'Machine dilepas dari production slot.',
                ]
            );
        }

        $db->transComplete();

        return redirect()
            ->to('/master-data/machines')
            ->with('success', 'Machine dilepas dari slot.');
    }

    /**
     * Build machine payload from request input.
     */
    private function machinePayload(): array
    {
        return [
            'registration_code' => strtoupper(trim((string)$this->request->getPost('registration_code'))),
            'code' => strtoupper(
                trim((string) $this->request->getPost('code'))
            ),

            'name' => trim(
                (string) $this->request->getPost('name')
            ),

            'maker' => $this->nullableString('maker'),

            'model' => $this->nullableString('model'),

            'type' => $this->nullableString('type'),

            'tonnage' => $this->nullableInt('tonnage'),

            'screw_diameter' => $this->nullableFloat('screw_diameter'),

            'power' => $this->nullableString('power'),

            'serial_number' => $this->nullableString('serial_number'),

            'year' => $this->nullableInt('year'),
        ];
    }

    /**
     * Get nullable string input.
     */
    private function nullableString(string $field): ?string
    {
        $value = trim((string) $this->request->getPost($field));

        return $value !== '' ? $value : null;
    }

    /**
     * Get nullable integer input.
     */
    private function nullableInt(string $field): ?int
    {
        $value = $this->request->getPost($field);

        return $value !== '' ? (int) $value : null;
    }

    /**
     * Get nullable float input.
     */
    private function nullableFloat(string $field): ?float
    {
        $value = $this->request->getPost($field);

        return $value !== '' ? (float) $value : null;
    }
}
