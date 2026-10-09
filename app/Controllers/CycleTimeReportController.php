<?php

namespace App\Controllers;

use App\Services\CycleTimeReport;
use App\Services\ProductionRules;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class CycleTimeReportController extends BaseController
{
    /**
     * Ambil dan validasi semua filter report.
     */
    private function filters(): array
    {
        $input = $this->request->getGet();

        $start = (string) (
            $input['start']
            ?? date('Y-m-01')
        );

        $end = (string) (
            $input['end']
            ?? date('Y-m-d')
        );

        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $date
            );

            if (
                ! $parsed
                || $parsed->format('Y-m-d') !== $date
            ) {
                throw new DomainException(
                    'Tanggal report tidak valid.'
                );
            }
        }

        if ($start > $end) {
            throw new DomainException(
                'Tanggal awal harus sebelum tanggal akhir.'
            );
        }

        $filters = [];

        foreach (
            [
                'machine_id',
                'part_id',
                'part_process_id',
                'shift_id',
                'operator_employee_id',
            ]
            as $key
        ) {
            $value = $input[$key] ?? '';

            $filters[$key] = ProductionRules::integer(
                $value === '' ? 0 : $value,
                $key
            );
        }

        return [
            $start,
            $end,
            $filters,
        ];
    }


    /**
     * Data halaman Cycle Time Report.
     */
    private function data(): array
    {
        [
            $start,
            $end,
            $filters,
        ] = $this->filters();

        $db = db_connect();

        $report = new CycleTimeReport($db);

        return [
            'start' => $start,

            'end' => $end,

            'filters' => $filters,

            'rows' => $report->rows(
                $start,
                $end,
                $filters['machine_id'],
                $filters['part_id'],
                $filters['part_process_id'],
                $filters['shift_id'],
                $filters['operator_employee_id']
            ),


            /*
             * Machine filter
             */
            'machines' => $db
                ->table('machines')
                ->orderBy('code')
                ->get()
                ->getResultArray(),


            /*
             * Part filter
             */
            'parts' => $db
                ->table('parts')
                ->orderBy('part_number')
                ->get()
                ->getResultArray(),


            /*
             * Process filter
             */
            'processes' => $db
                ->table('part_processes pp')
                ->select(
                    '
                        pp.id,
                        pp.process_name,
                        p.part_number
                    '
                )
                ->join(
                    'parts p',
                    'p.id = pp.part_id'
                )
                ->orderBy('p.part_number')
                ->orderBy('pp.process_no')
                ->get()
                ->getResultArray(),


            /*
             * Shift filter
             */
            'shifts' => $db
                ->table('shifts')
                ->orderBy('start_time')
                ->get()
                ->getResultArray(),


            /*
             * Operator filter
             */
            'operators' => $db
                ->table('employees')
                ->where(
                    "UPPER(role) = 'OPERATOR'",
                    null,
                    false
                )
                ->where('status', 'active')
                ->orderBy('name')
                ->get()
                ->getResultArray(),
        ];
    }


    /**
     * Halaman utama.
     */
    public function index()
    {
        try {
            return view(
                'reports/cycle-time',
                $this->data() + [
                    'title' => 'Cycle Time Report',
                ]
            );
        } catch (DomainException $e) {

            return redirect()
                ->to(
                    site_url(
                        'reports/cycle-time'
                    )
                )
                ->with(
                    'error',
                    $e->getMessage()
                );
        }
    }


    /**
     * Endpoint AJAX detail cycle.
     *
     * GET:
     *
     * /reports/cycle-time/detail
     * ?work_date=2026-10-06
     * &shift_id=1
     * &machine_id=1
     * &operator_employee_id=10
     * &part_id=5
     * &part_process_id=2
     */
    public function detail()
    {
        try {
            $input = $this->request->getGet();


            /*
             * Work date
             */
            $workDate = trim(
                (string) (
                    $input['work_date']
                    ?? ''
                )
            );

            $parsed =
                DateTimeImmutable::createFromFormat(
                    '!Y-m-d',
                    $workDate
                );

            if (
                ! $parsed
                || $parsed->format('Y-m-d')
                !== $workDate
            ) {
                throw new DomainException(
                    'work_date tidak valid.'
                );
            }


            /*
             * Composite identity
             */
            $shiftId =
                ProductionRules::integer(
                    $input['shift_id']
                        ?? null,
                    'shift_id',
                    1
                );

            $machineId =
                ProductionRules::integer(
                    $input['machine_id']
                        ?? null,
                    'machine_id',
                    1
                );

            $operatorId =
                ProductionRules::integer(
                    $input['operator_employee_id']
                        ?? null,
                    'operator_employee_id',
                    1
                );

            $partId =
                ProductionRules::integer(
                    $input['part_id']
                        ?? null,
                    'part_id',
                    1
                );

            $processId =
                ProductionRules::integer(
                    $input['part_process_id']
                        ?? null,
                    'part_process_id',
                    1
                );


            /*
             * Pakai koneksi DB yang sama
             * dengan halaman utama.
             */
            $db = db_connect();

            $report =
                new CycleTimeReport($db);

            $detail =
                $report->detail(
                    $workDate,
                    $shiftId,
                    $machineId,
                    $operatorId,
                    $partId,
                    $processId
                );


            return $this->response
                ->setStatusCode(200)
                ->setJSON([
                    'ok' => true,
                    'data' => $detail,
                ]);
        } catch (DomainException $e) {

            return $this->response
                ->setStatusCode(422)
                ->setJSON([
                    'ok' => false,
                    'message' =>
                    $e->getMessage(),
                ]);
        } catch (Throwable $e) {

            log_message(
                'error',
                'Cycle Time detail error: {message}',
                [
                    'message' =>
                    $e->getMessage(),
                ]
            );

            return $this->response
                ->setStatusCode(500)
                ->setJSON([
                    'ok' => false,

                    'message' =>
                    ENVIRONMENT
                        === 'development'
                        ? (
                            'Detail cycle time gagal: '
                            . $e->getMessage()
                        )
                        : (
                            'Detail cycle time '
                            . 'tidak dapat dimuat.'
                        ),
                ]);
        }
    }


    /**
     * Export CSV.
     */
    public function export()
    {
        try {
            $data =
                $this->data();

            $stream =
                fopen(
                    'php://temp',
                    'w+'
                );

            /*
             * UTF-8 BOM
             */
            fwrite(
                $stream,
                "\xEF\xBB\xBF"
            );


            $keys = [
                'work_date',

                'shift_code',
                'shift_name',

                'machine_code',

                'operator_nik',
                'operator_name',

                'part_number',
                'process_name',

                'produced_qty',
                'valid_cycle_count',

                'total_machine_ms',
                'total_loading_ms',
                'total_cycle_ms',

                'standard_machine_ms',
                'actual_machine_ms',
                'machine_variance_ms',

                'standard_loading_ms',
                'actual_loading_ms',
                'loading_variance_ms',

                'standard_cycle_ms',
                'actual_cycle_ms',
                'cycle_variance_ms',
            ];


            fputcsv(
                $stream,
                $keys
            );


            foreach (
                $data['rows']
                as $row
            ) {
                fputcsv(
                    $stream,
                    array_map(
                        static fn($key) =>
                        $row[$key]
                            ?? null,
                        $keys
                    )
                );
            }


            rewind($stream);

            $csv =
                stream_get_contents(
                    $stream
                );

            fclose($stream);


            return $this->response
                ->setHeader(
                    'Content-Type',
                    'text/csv; charset=UTF-8'
                )
                ->setHeader(
                    'Content-Disposition',
                    'attachment; '
                        . 'filename="cycle_time_report.csv"'
                )
                ->setBody($csv);
        } catch (DomainException $e) {

            return $this->response
                ->setStatusCode(422)
                ->setBody(
                    $e->getMessage()
                );
        }
    }
}
