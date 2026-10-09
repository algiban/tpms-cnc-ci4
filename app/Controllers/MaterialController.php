<?php

namespace App\Controllers;

use App\Models\MaterialModel;

class MaterialController extends BaseController
{
    public function store()
    {
        if (!$this->validate(['code' => 'required|max_length[50]|is_unique[materials.code]', 'name' => 'required|max_length[180]'])) return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        (new MaterialModel())->insert($this->payload());
        return redirect()->to('/master-data/materials')->with('success', 'Material berhasil ditambahkan.');
    }
    public function update(int $id)
    {
        $m = new MaterialModel();
        if (!$m->find($id)) return redirect()->back()->with('error', 'Material tidak ditemukan.');
        if (!$this->validate(['code' => "required|max_length[50]|is_unique[materials.code,id,{$id}]", 'name' => 'required|max_length[180]'])) return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        $m->update($id, $this->payload());
        return redirect()->to('/master-data/materials')->with('success', 'Material berhasil diperbarui.');
    }
    public function delete(int $id)
    {
        $db = db_connect();
        if ($db->table('parts')->where('material_id', $id)->countAllResults() > 0) return redirect()->back()->with('error', 'Material masih digunakan oleh part.');
        (new MaterialModel())->delete($id);
        return redirect()->to('/master-data/materials')->with('success', 'Material berhasil dihapus.');
    }
    private function payload(): array
    {
        return ['code' => strtoupper(trim((string)$this->request->getPost('code'))), 'name' => trim((string)$this->request->getPost('name')) ?: null];
    }
}
