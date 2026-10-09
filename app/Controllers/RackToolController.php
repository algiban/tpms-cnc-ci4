<?php

namespace App\Controllers;

use App\Models\RackToolModel;
use App\Services\ProductionRules;
use App\Services\ProductionRuntimeGuard;
use DomainException;
use Throwable;

class RackToolController extends BaseController
{
    public function store()
    {
        if (! $this->validate([
            'code' => 'required|max_length[80]|is_unique[rack_tools.code]',
            'name' => 'required|max_length[150]',
            'uid' => 'permit_empty|max_length[100]|is_unique[rack_tools.uid]',
        ])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        (new RackToolModel())->insert($this->payload());

        return redirect()->to('/master-data/rack-tools')->with('success', 'Rack Tools berhasil ditambahkan.');
    }

    public function update(int $id)
    {
        $model = new RackToolModel();
        if (! $model->find($id)) {
            return redirect()->back()->with('error', 'Rack Tools tidak ditemukan.');
        }

        try {
            (new ProductionRuntimeGuard())->assertRackMutable($id, 'diubah');
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        if (! $this->validate([
            'code' => "required|max_length[80]|is_unique[rack_tools.code,id,{$id}]",
            'name' => 'required|max_length[150]',
            'uid' => "permit_empty|max_length[100]|is_unique[rack_tools.uid,id,{$id}]",
        ])) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $model->update($id, $this->payload());

        return redirect()->to('/master-data/rack-tools')->with('success', 'Rack Tools berhasil diperbarui.');
    }

    public function sync(int $id)
    {
        if (! (new RackToolModel())->find($id)) {
            return redirect()->back()->with('error', 'Rack Tools tidak ditemukan.');
        }

        $db = db_connect();
        $started = false;

        try {
            (new ProductionRuntimeGuard($db))->assertRackMutable($id, 'diubah setup-nya');

            $partIds = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) $this->request->getPost('part_ids')
            ))));
            $toolIds = array_values(array_unique(array_filter(array_map(
                'intval',
                (array) $this->request->getPost('tool_ids')
            ))));
            $positions = (array) $this->request->getPost('positions');

            if (! $db->transBegin()) {
                throw new \RuntimeException('Tidak dapat memulai transaksi Rack Tools.');
            }
            $started = true;

            $db->table('rack_tool_parts')->where('rack_tool_id', $id)->delete();
            foreach ($partIds as $partId) {
                $db->table('rack_tool_parts')->insert([
                    'rack_tool_id' => $id,
                    'part_id' => $partId,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $db->table('rack_tool_tools')->where('rack_tool_id', $id)->delete();
            foreach ($toolIds as $index => $toolId) {
                $db->table('rack_tool_tools')->insert([
                    'rack_tool_id' => $id,
                    'tool_id' => $toolId,
                    'position' => trim((string) ($positions[$index] ?? '')) ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new \RuntimeException('Database gagal menyimpan setup Rack Tools.');
            }
            $started = false;

            return redirect()->to('/master-data/rack-tools')->with('success', 'Assignment Rack Tools diperbarui.');
        } catch (Throwable $e) {
            if ($started) {
                $db->transRollback();
            }

            if (! $e instanceof DomainException) {
                log_message('error', 'RackToolController sync: {message}', ['message' => $e->getMessage()]);
            }

            return redirect()->back()->withInput()->with(
                'error',
                $e instanceof DomainException ? $e->getMessage() : 'Assignment Rack Tools gagal disimpan.'
            );
        }
    }

    public function delete(int $id)
    {
        try {
            (new ProductionRuntimeGuard())->assertRackMutable($id, 'dihapus');
            (new RackToolModel())->delete($id);

            return redirect()->to('/master-data/rack-tools')->with('success', 'Rack Tools berhasil dihapus.');
        } catch (Throwable $e) {
            return redirect()->back()->with(
                'error',
                $e instanceof DomainException
                    ? $e->getMessage()
                    : 'Rack Tools gagal dihapus karena masih memiliki relasi.'
            );
        }
    }

    private function payload(): array
    {
        return [
            'code' => strtoupper(trim((string) $this->request->getPost('code'))),
            'name' => trim((string) $this->request->getPost('name')),
            'uid' => trim((string) $this->request->getPost('uid')) !== ''
                ? ProductionRules::uid($this->request->getPost('uid'))
                : null,
            'location' => trim((string) $this->request->getPost('location')) ?: null,
            'status' => $this->request->getPost('status') ?: 'available',
        ];
    }
}
