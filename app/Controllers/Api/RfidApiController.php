<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\ProductionRules;
use DomainException;
use JsonException;
use Throwable;

class RfidApiController extends BaseController
{
    public function scan()
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        try {
            $payload = json_decode(
                $this->request->getBody(),
                true,
                32,
                JSON_THROW_ON_ERROR
            );

            if (! is_array($payload)) {
                throw new DomainException('Payload harus objek JSON.', 422);
            }

            $mac = strtoupper(trim((string) ($payload['mac_address'] ?? '')));
            $uid = ProductionRules::uid($payload['uid'] ?? '');
            $purpose = strtolower(trim((string) ($payload['purpose'] ?? 'generic')));

            if ($mac === '') {
                throw new DomainException('mac_address wajib dikirim.', 422);
            }

            if (! in_array($purpose, ['generic', 'employee', 'user'], true)) {
                throw new DomainException('Purpose RFID tidak valid.', 422);
            }

            $token = trim((string) $this->request->getHeaderLine('X-TPMS-Key'));
            if ($token === '') {
                throw new DomainException('Token perangkat tidak dikirim.', 401);
            }

            $db = db_connect();
            $device = $db->table('tpms_devices')
                ->where('mac_address', $mac)
                ->get()
                ->getRowArray();

            if (! $device || empty($device['token']) || ! hash_equals((string) $device['token'], $token)) {
                throw new DomainException('Device atau token TPMS tidak valid.', 401);
            }

            $now = date('Y-m-d H:i:s');
            $ok = $db->table('rfid_scan_events')->insert([
                'tpms_device_id' => (int) $device['id'],
                'uid' => $uid,
                'purpose' => $purpose,
                'scanned_at' => $now,
                'created_at' => $now,
            ]);

            if (! $ok) {
                throw new \RuntimeException('RFID scan gagal disimpan.');
            }

            $scanId = (int) $db->insertID();
            $db->table('tpms_devices')->where('id', $device['id'])->update([
                'last_seen_at' => $now,
                'connection_status' => 'reachable',
                'updated_at' => $now,
            ]);

            return $this->response->setJSON([
                'ok' => true,
                'data' => [
                    'scan_id' => $scanId,
                    'uid' => $uid,
                    'purpose' => $purpose,
                    'device_id' => (int) $device['id'],
                    'mac_address' => $device['mac_address'],
                    'scanned_at' => $now,
                ],
            ]);
        } catch (JsonException $e) {
            return $this->response->setStatusCode(422)->setJSON([
                'ok' => false,
                'message' => 'JSON tidak valid.',
            ]);
        } catch (DomainException $e) {
            $code = in_array((int) $e->getCode(), [400, 401, 403, 404, 409, 422], true)
                ? (int) $e->getCode()
                : 422;
            return $this->response->setStatusCode($code)->setJSON([
                'ok' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            log_message('error', 'RFID scan: {message}', ['message' => $e->getMessage()]);
            return $this->response->setStatusCode(500)->setJSON([
                'ok' => false,
                'message' => 'RFID scan gagal disimpan.',
            ]);
        }
    }
}
