<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\EspProductionService;
use DomainException;
use JsonException;
use Throwable;

class ProductionApiController extends BaseController
{
    /**
     * Handle semua endpoint TPMS production/setting.
     *
     * Read: context, parts, pic, operator, state.
     * Setting: setting-start, setting-configure, setting-finish.
     * Production: start, count, cycle-start, cycle-stop, pause, alarm,
     * resume, service-complete, operator-sick, operator-sick-resolve,
     * operator-replacement, stop, finish.
     * Tool maintenance: change-edge, reset-tool.
     */
    public function dispatch(string $action)
    {
        $this->response->setHeader(
            'Cache-Control',
            'no-store'
        );

        try {
            /*
            |--------------------------------------------------------------------------
            | Parse JSON Payload
            |--------------------------------------------------------------------------
            */
            $input = json_decode(
                $this->request->getBody(),
                true,
                64,
                JSON_THROW_ON_ERROR
            );

            if (! is_array($input)) {
                throw new DomainException(
                    'Payload harus objek JSON.',
                    422
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Ambil Token Device
            |--------------------------------------------------------------------------
            |
            | REGISTER:
            | X-TPMS-Key = TPMS_API_KEY dari .env
            |
            | PRODUCTION:
            | X-TPMS-Key = tpms_devices.token
            |
            | Jadi nama header tetap X-TPMS-Key,
            | hanya isi/value-nya yang berbeda.
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

            /*
            |--------------------------------------------------------------------------
            | Process Production
            |--------------------------------------------------------------------------
            */
            $data = (
                new EspProductionService()
            )->handle(
                $action,
                $input,
                $token
            );

            /*
            |--------------------------------------------------------------------------
            | Success Response
            |--------------------------------------------------------------------------
            */
            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'ok' => true,
                    'data' => $data,
                ]);
        } catch (JsonException $e) {

            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'ok' => false,
                    'message' => 'JSON tidak valid.',
                ]);
        } catch (DomainException $e) {

            $code = (int) $e->getCode();

            /*
             * Hanya izinkan HTTP status yang memang digunakan API.
             */
            if (! in_array(
                $code,
                [
                    400,
                    401,
                    403,
                    404,
                    409,
                    422,
                ],
                true
            )) {
                $code = 422;
            }

            return $this->response
                ->setStatusCode($code)
                ->setJSON([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]);
        } catch (Throwable $e) {

            log_message(
                'error',
                'ESP production [{action}]: {message}',
                [
                    'action' => $action,
                    'message' => $e->getMessage(),
                ]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,
                    'message' =>
                    'Penyimpanan gagal. Retry dengan event_id dan payload yang sama.',
                ]);
        }
    }
}
