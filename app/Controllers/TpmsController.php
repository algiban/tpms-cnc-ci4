<?php

namespace App\Controllers;

use App\Models\TpmsDeviceModel;
use App\Services\DeviceReachabilityService;

class TpmsController extends BaseController
{
    public function scanConnections()
    {
        $model =
            new TpmsDeviceModel();

        $service =
            new DeviceReachabilityService();

        $logger =
            new ActivityLogService();

        foreach (
            $model
                ->where(
                    'ip_address IS NOT NULL',
                    null,
                    false
                )
                ->findAll()
            as $device
        ) {
            $reachable =
                $service->ping(
                    $device['ip_address'],
                    1
                );

            $newStatus =
                $reachable
                ? 'reachable'
                : 'unreachable';

            $oldStatus =
                $device['connection_status'];

            $model->update(
                $device['id'],
                [
                    'connection_status' =>
                    $newStatus,

                    'last_connection_check_at' =>
                    date(
                        'Y-m-d H:i:s'
                    ),
                ]
            );

            /*
         * Hanya log jika berubah.
         */
            if (
                $oldStatus
                !== $newStatus
            ) {
                $logger->tpms(
                    (int) $device['id'],

                    $reachable
                        ? 'connection_restored'
                        : 'connection_lost',

                    [
                        'connection_status' =>
                        $oldStatus,
                    ],

                    [
                        'connection_status' =>
                        $newStatus,
                    ],

                    [
                        'source' =>
                        'connection_scan',

                        'severity' =>
                        $reachable
                            ? 'info'
                            : 'warning',

                        'message' =>
                        $reachable
                            ? 'Koneksi TPMS kembali aktif.'
                            : 'Koneksi TPMS terputus.',
                    ]
                );
            }
        }

        return redirect()
            ->to(
                '/master-data/tpms'
            )
            ->with(
                'success',
                'Connection scan selesai.'
            );
    }
}
