<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class TpmsStressManifest extends BaseCommand
{
    protected $group = 'TPMS';
    protected $name = 'tpms:stress-manifest';
    protected $description = 'Buat manifest credential device untuk Phase 8 stress test dari database test/staging.';
    protected $usage = 'tpms:stress-manifest [--output writable/stress/devices.json] [--limit 100] [--active-only] [--stress-only]';
    protected $options = [
        '--output' => 'Path output JSON. Default writable/stress/devices.json.',
        '--limit' => 'Maksimum device yang diekspor (1-1000). Default 200.',
        '--active-only' => 'Hanya device yang memiliki production shift aktif.',
        '--stress-only' => 'Hanya device dummy hasil tpms:stress-seed (token stress-*).',
    ];

    public function run(array $params)
    {
        try {
            $outputRaw = CLI::getOption('output');
            $outputValue = $outputRaw !== null ? (string) $outputRaw : '';
            // Copy/paste dari chat/Docs kadang membawa NBSP / narrow NBSP / BOM.
            // Normalisasi supaya nama file tidak diam-diam mengandung whitespace Unicode.
            $outputValue = preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}\x{FEFF}]+|[\s\x{00A0}\x{2007}\x{202F}\x{FEFF}]+$/u', '', $outputValue) ?? '';

            if ($outputValue === '') {
                $output = WRITEPATH . 'stress/devices.json';
            } else {
                $isAbsolute = str_starts_with($outputValue, DIRECTORY_SEPARATOR)
                    || preg_match('/^[A-Za-z]:[\\\/]/', $outputValue) === 1;
                $output = $isAbsolute
                    ? $outputValue
                    : rtrim(ROOTPATH, '/\\') . DIRECTORY_SEPARATOR . ltrim($outputValue, '/\\');
            }

            $limitRaw = CLI::getOption('limit');
            $limit = $limitRaw !== null && is_numeric($limitRaw) ? (int) $limitRaw : 200;
            $limit = max(1, min(1000, $limit));
            $activeOnly = CLI::getOption('active-only') !== null;
            $stressOnly = CLI::getOption('stress-only') !== null;

            $db = db_connect();
            $deviceBuilder = $db->table('tpms_devices')
                ->select('id, mac_address, token, current_slot_id')
                ->where('token !=', '');
            if ($stressOnly) {
                $deviceBuilder->like('token', 'stress-', 'after');
            }
            $deviceRows = $deviceBuilder
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            if ($deviceRows === []) {
                CLI::error('Tidak ada device bertoken yang dapat diekspor.');
                return EXIT_ERROR;
            }

            $deviceIds = array_map(static fn (array $row): int => (int) $row['id'], $deviceRows);
            $activeRows = $db->table('production_shift_details sd')
                ->select('p.device_id, p.id AS production_id, sd.id AS production_shift_detail_id, sd.counter_epoch, sd.actual_qty AS counter_total, sd.status AS shift_status')
                ->join('productions p', 'p.id = sd.production_id')
                ->whereIn('p.device_id', $deviceIds)
                ->whereIn('p.status', ['running', 'active'])
                ->whereIn('sd.status', ['running', 'paused', 'service_required', 'awaiting_defects', 'operator_change_required', 'setting'])
                ->orderBy('sd.id', 'DESC')
                ->get()
                ->getResultArray();

            // Rows sudah DESC, jadi assignment pertama adalah shift aktif terbaru per device.
            $activeByDevice = [];
            foreach ($activeRows as $row) {
                $deviceId = (int) $row['device_id'];
                if (! isset($activeByDevice[$deviceId])) {
                    $activeByDevice[$deviceId] = $row;
                }
            }

            $devices = [];
            foreach ($deviceRows as $deviceRow) {
                $deviceId = (int) $deviceRow['id'];
                $active = $activeByDevice[$deviceId] ?? null;
                if ($activeOnly && $active === null) {
                    continue;
                }

                $devices[] = [
                    'id' => $deviceId,
                    'mac_address' => strtoupper((string) $deviceRow['mac_address']),
                    'token' => (string) $deviceRow['token'],
                    'current_slot_id' => $deviceRow['current_slot_id'] !== null ? (int) $deviceRow['current_slot_id'] : null,
                    'production_id' => $active !== null ? (int) $active['production_id'] : null,
                    'production_shift_detail_id' => $active !== null ? (int) $active['production_shift_detail_id'] : null,
                    'counter_epoch' => $active !== null ? (string) $active['counter_epoch'] : null,
                    'counter_total' => $active !== null ? (int) $active['counter_total'] : 0,
                    'shift_status' => $active !== null ? (string) $active['shift_status'] : null,
                ];
            }

            if ($devices === []) {
                CLI::error('Tidak ada device yang memenuhi filter. Dengan --active-only, pastikan ada production shift aktif.');
                return EXIT_ERROR;
            }

            $dir = dirname($output);
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Tidak dapat membuat directory output: ' . $dir);
            }

            $payload = [
                'generated_at' => date(DATE_ATOM),
                'environment' => ENVIRONMENT,
                'warning' => 'FILE INI BERISI TOKEN DEVICE. Gunakan hanya pada environment test/staging dan jangan commit ke repository.',
                'devices' => $devices,
            ];

            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $bytesWritten = file_put_contents($output, $json . PHP_EOL, LOCK_EX);
            if ($bytesWritten === false) {
                throw new \RuntimeException('Gagal menulis manifest: ' . $output);
            }
            @chmod($output, 0600);
            clearstatcache(true, $output);
            if (! is_file($output)) {
                throw new \RuntimeException('Write dilaporkan berhasil tetapi file tidak ditemukan: ' . $output);
            }

            $resolvedOutput = realpath($output) ?: $output;
            $activeCount = count(array_filter($devices, static fn (array $d): bool => $d['production_id'] !== null));

            CLI::write('TPMS Phase 8 Stress Manifest', 'green');
            CLI::write('Output        : ' . $resolvedOutput);
            CLI::write('Bytes         : ' . number_format((int) $bytesWritten));
            CLI::write('Devices       : ' . count($devices));
            CLI::write('Active session: ' . $activeCount);
            CLI::write('Mode          : ' . ($stressOnly ? 'STRESS ONLY' : ($activeOnly ? 'ACTIVE ONLY' : 'ALL TOKENIZED DEVICES')));
            CLI::newLine();
            CLI::write('PERINGATAN: file berisi token device. Jangan upload/commit manifest ini.', 'yellow');

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Stress manifest gagal: ' . $e->getMessage());
            return EXIT_ERROR;
        }
    }
}
