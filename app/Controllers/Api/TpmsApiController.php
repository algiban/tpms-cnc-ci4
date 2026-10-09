<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\TpmsRegistrationService;
use DomainException;
use RuntimeException;
use Throwable;

class TpmsApiController extends BaseController
{
    public function ping()
    {
        return $this->response->setJSON([
            'ok' => true,
            'message' => 'TPMS server reachable.',
            'server_time' => date(DATE_ATOM),
            'data' => [
                'service' => 'TPMS API',
                'status' => 'online',
            ],
        ]);
    }

    public function register()
    {
        /*
         * Registrasi HANYA boleh menggunakan
         * TPMS_API_KEY dari .env
         */
        if (! $this->authorizedRegistration()) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Invalid TPMS registration key.',
                ]);
        }

        try {
            $payload = $this->jsonPayload();

            $rules = [
                'mac_address'      => 'required|max_length[17]',
                'ip_address'       => 'permit_empty|valid_ip',
                'firmware_version' => 'permit_empty|max_length[150]',
                'hmi_version'      => 'permit_empty|max_length[150]',
                'slot_no'          => 'permit_empty|is_natural_no_zero',
                'status'           => 'permit_empty|in_list[online,run,idle,paused,alarm,offline]',
            ];

            if (! $this->validateData($payload, $rules)) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'ok' => false,
                        'message' => 'Validation failed.',
                        'errors' => $this->validator->getErrors(),
                    ]);
            }

            $device = (new TpmsRegistrationService())
                ->register($payload);

            return $this->response->setJSON([
                'ok' => true,
                'message' => 'TPMS registered.',
                'data' => [
                    'id' => $device['id'],
                    'token' => $device['token'],
                    'mac_address' => $device['mac_address'],
                    'current_slot_id' => $device['current_slot_id'],
                    'last_seen_at' => $device['last_seen_at'],
                ],
            ]);
        } catch (RuntimeException $e) {

            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]);
        } catch (Throwable $e) {

            log_message(
                'error',
                'TPMS register error: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Internal server error.',
                ]);
        }
    }

    public function heartbeat()
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        try {
            $payload = $this->jsonPayload();

            $rules = [
                'mac_address'      => 'required|max_length[17]',
                'ip_address'       => 'permit_empty|valid_ip',
                'firmware_version' => 'permit_empty|max_length[150]',
                'hmi_version'      => 'permit_empty|max_length[150]',
                'status'           => 'permit_empty|in_list[online,run,idle,paused,alarm,offline]',
            ];

            if (! $this->validateData($payload, $rules)) {
                return $this->response
                    ->setStatusCode(422)
                    ->setJSON([
                        'ok' => false,
                        'message' => 'Validation failed.',
                        'errors' => $this->validator->getErrors(),
                    ]);
            }

            /*
             * Heartbeat menggunakan token milik device yang didapat saat register.
             * Global TPMS_API_KEY hanya boleh digunakan untuk /register.
             */
            $token = trim(
                (string) $this->request
                    ->getHeaderLine('X-TPMS-Key')
            );

            if ($token === '') {
                throw new DomainException(
                    'Token perangkat tidak dikirim.',
                    401
                );
            }

            $device = (new TpmsRegistrationService())
                ->heartbeat($payload, $token);

            return $this->response->setJSON([
                'ok' => true,
                'server_time' => date(DATE_ATOM),
                'data' => [
                    'id' => (int) $device['id'],
                    'mac_address' => $device['mac_address'],
                    'current_slot_id' => $device['current_slot_id'] !== null
                        ? (int) $device['current_slot_id']
                        : null,
                    'device_status' => $device['device_status'],
                    'connection_status' => $device['connection_status'],
                    'last_seen_at' => $device['last_seen_at'],
                ],
            ]);
        } catch (DomainException $e) {
            $code = (int) $e->getCode();

            if (! in_array($code, [400, 401, 403, 404, 409, 422], true)) {
                $code = 422;
            }

            return $this->response
                ->setStatusCode($code)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]);
        } catch (RuntimeException $e) {
            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]);
        } catch (Throwable $e) {
            log_message(
                'error',
                'TPMS heartbeat error: {message}',
                ['message' => $e->getMessage()]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' => 'Internal server error.',
                ]);
        }
    }

    private function authorizedRegistration(): bool
    {
        $configured = trim(
            (string) env('TPMS_API_KEY', '')
        );

        $provided = trim(
            (string) $this->request
                ->getHeaderLine('X-TPMS-Key')
        );

        return $configured !== ''
            && $provided !== ''
            && hash_equals($configured, $provided);
    }

    private function jsonPayload(): array
    {
        $json = $this->request->getJSON(true);

        return is_array($json)
            ? $json
            : [];
    }
}
