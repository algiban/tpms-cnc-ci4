<?php

namespace App\Controllers;

class RfidCaptureController extends BaseController
{
    public function index()
    {
        $db = db_connect();
        $rows = $db->table('rfid_scan_events r')
            ->select('r.*, d.mac_address, s.slot_no, m.name machine_name')
            ->join('tpms_devices d', 'd.id = r.tpms_device_id')
            ->join('production_slots s', 's.id = d.current_slot_id', 'left')
            ->join('machines m', 'm.id = s.machine_id', 'left')
            ->orderBy('r.id', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        return view('rfid-capture/index', [
            'title' => 'RFID Capture',
            'scans' => $rows,
        ]);
    }

    public function latest()
    {
        $afterId = max(0, (int) $this->request->getGet('after_id'));
        $purpose = strtolower(trim((string) $this->request->getGet('purpose')));
        $baseline = $this->request->getGet('baseline') === '1';

        $db = db_connect();
        $builder = $db->table('rfid_scan_events r')
            ->select('r.id, r.uid, r.purpose, r.scanned_at, d.mac_address')
            ->join('tpms_devices d', 'd.id = r.tpms_device_id');

        if ($purpose !== '' && in_array($purpose, ['employee', 'user'], true)) {
            $builder->groupStart()
                ->where('r.purpose', $purpose)
                ->orWhere('r.purpose', 'generic')
                ->groupEnd();
        }

        if (! $baseline && $afterId > 0) {
            $builder->where('r.id >', $afterId);
        }

        $row = $builder->orderBy('r.id', 'DESC')->limit(1)->get()->getRowArray();

        if ($baseline) {
            return $this->response->setJSON([
                'ok' => true,
                'data' => ['latest_id' => (int) ($row['id'] ?? 0)],
            ]);
        }

        return $this->response->setJSON([
            'ok' => true,
            'data' => $row ?: null,
        ]);
    }
}
