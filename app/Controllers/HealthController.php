<?php

namespace App\Controllers;

use Throwable;

final class HealthController extends BaseController
{
    /**
     * Liveness: proves PHP/CI4 can serve a request. No database access.
     */
    public function live()
    {
        return $this->response
            ->setHeader('Cache-Control', 'no-store')
            ->setJSON([
                'ok' => true,
                'status' => 'alive',
                'time' => date(DATE_ATOM),
            ]);
    }

    /**
     * Readiness: verifies database connectivity and writable runtime directories.
     * Do not expose database credentials/error details in the HTTP response.
     */
    public function ready()
    {
        try {
            $db = db_connect();
            $row = $db->query('SELECT 1 AS ready')->getRowArray();

            $writable = is_writable(WRITEPATH)
                && is_writable(WRITEPATH . 'cache')
                && is_writable(WRITEPATH . 'logs');

            if ((int) ($row['ready'] ?? 0) !== 1 || ! $writable) {
                return $this->response
                    ->setHeader('Cache-Control', 'no-store')
                    ->setStatusCode(503)
                    ->setJSON([
                        'ok' => false,
                        'status' => 'not_ready',
                    ]);
            }

            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON([
                    'ok' => true,
                    'status' => 'ready',
                    'time' => date(DATE_ATOM),
                ]);
        } catch (Throwable $e) {
            log_message('error', 'Health readiness failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            return $this->response
                ->setHeader('Cache-Control', 'no-store')
                ->setStatusCode(503)
                ->setJSON([
                    'ok' => false,
                    'status' => 'not_ready',
                ]);
        }
    }
}
