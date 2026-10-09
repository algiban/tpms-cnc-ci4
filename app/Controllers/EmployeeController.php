<?php

namespace App\Controllers;

use App\Services\ProductionRules;
use App\Services\ProductionRuntimeGuard;
use DomainException;
use RuntimeException;
use Throwable;

class EmployeeController extends BaseController
{
    public function store()
    {
        return $this->save();
    }

    public function update(int $id)
    {
        return $this->save($id);
    }

    public function assignShift(int $id)
    {
        return $this->response->setStatusCode(405)->setBody('Atur jadwal melalui Planning Employee.');
    }

    public function delete(int $id)
    {
        return $this->response->setStatusCode(405)->setBody('Nonaktifkan employee melalui Edit agar riwayat tetap tersimpan.');
    }

    private function save(?int $id = null)
    {
        $db = db_connect();
        $started = false;
        try {
            $payload = [];
            foreach (['nik' => 50, 'name' => 150, 'department' => 100, 'role' => 100] as $field => $max) {
                $payload[$field] = ProductionRules::text($this->request->getPost($field), $field, $max);
            }
            $payload['nik'] = strtoupper($payload['nik']);
            $raw = $this->request->getPost('rfid_uid');
            $payload['rfid_uid'] = $raw === '' || $raw === null ? null : ProductionRules::uid($raw);
            $payload['status'] = $this->request->getPost('status') ?: 'active';
            if (!in_array($payload['status'], ['active', 'inactive'], true)) {
                throw new DomainException('Status employee tidak valid.');
            }
            $db->transBegin();
            $started = true;
            (new \App\Services\TpmsRuntimeLockService($db))->lockAllDevices();
            if ($id !== null && !$db->table('employees')->where('id', $id)->countAllResults()) {
                throw new DomainException('Employee tidak ditemukan.');
            }
            if ($id !== null) {
                (new ProductionRuntimeGuard($db))->assertEmployeeMutable($id, 'diubah');
            }
            foreach (['nik', 'rfid_uid'] as $field) {
                if ($payload[$field] !== null) {
                    $q = $db->table('employees')->where($field, $payload[$field]);
                    if ($id !== null) { $q->where('id !=', $id); }
                    if ($q->countAllResults()) { throw new DomainException("{$field} sudah digunakan employee lain."); }
                }
            }
            $payload['updated_at'] = date('Y-m-d H:i:s');
            if ($id === null) {
                $payload['created_at'] = $payload['updated_at'];
                $db->table('employees')->insert($payload);
            } else {
                $db->table('employees')->where('id', $id)->update($payload);
            }
            if (!$db->transStatus() || !$db->transCommit()) { throw new RuntimeException('Database gagal.'); }
            return redirect()->to(site_url('master-data/employees'))->with('success', 'Data employee berhasil disimpan.');
        } catch (Throwable $e) {
            if ($started) { $db->transRollback(); }
            if (!$e instanceof DomainException) { log_message('error', 'Employee: {message}', ['message' => $e->getMessage()]); }
            return redirect()->back()->withInput()->with('error', $e instanceof DomainException ? $e->getMessage() : 'Penyimpanan gagal. Periksa log.');
        }
    }
}
