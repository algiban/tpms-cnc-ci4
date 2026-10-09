<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CycleTimeReport
{
    private ?bool $hasOperatorSnapshot = null;


    public function __construct(
        private ?BaseConnection $db = null
    ) {
        $this->db ??= db_connect();
    }


    /**
     * Apakah migration operator snapshot
     * sudah tersedia pada production_cycles?
     */
    private function hasOperatorSnapshot(): bool
    {
        if (
            $this->hasOperatorSnapshot
            === null
        ) {
            $this->hasOperatorSnapshot =
                $this->db->fieldExists(
                    'operator_employee_id',
                    'production_cycles'
                );
        }

        return $this->hasOperatorSnapshot;
    }


    /**
     * Ambil operator dari snapshot cycle.
     *
     * Jika cycle lama belum mempunyai snapshot,
     * fallback ke production_shift_details.
     */
    private function operatorExpr(
        string $cycleAlias = 'pc',
        string $detailAlias = 'd'
    ): string {
        if (
            $this->hasOperatorSnapshot()
        ) {
            return sprintf(
                'COALESCE(%s.operator_employee_id,'
                    . '%s.operator_employee_id)',
                $cycleAlias,
                $detailAlias
            );
        }

        return
            "{$detailAlias}.operator_employee_id";
    }


    /**
     * Loading cycle pertama pada setiap operator
     * tidak dianggap valid sebagai loading sample.
     */
    private function validLoadingSql(
        string $cycleAlias = 'pc',
        string $detailAlias = 'd'
    ): string {

        if (
            ! $this->hasOperatorSnapshot()
        ) {
            return "
                (
                    {$cycleAlias}.sequence_no
                    >
                    COALESCE(
                        (
                            SELECT
                                MIN(
                                    pc_first.sequence_no
                                )
                            FROM
                                production_cycles pc_first
                            WHERE
                                pc_first.production_shift_detail_id
                                =
                                {$cycleAlias}.production_shift_detail_id

                                AND pc_first.status
                                =
                                'completed'
                        ),
                        {$cycleAlias}.sequence_no
                    )
                )
            ";
        }


        $operatorExpr =
            $this->operatorExpr(
                $cycleAlias,
                $detailAlias
            );


        return "
            (
                {$cycleAlias}.sequence_no
                >
                COALESCE(
                    (
                        SELECT
                            MIN(
                                pc_first.sequence_no
                            )
                        FROM
                            production_cycles pc_first
                        WHERE
                            pc_first.production_shift_detail_id
                            =
                            {$cycleAlias}.production_shift_detail_id

                            AND pc_first.status
                            =
                            'completed'

                            AND
                            COALESCE(
                                pc_first.operator_employee_id,
                                {$detailAlias}.operator_employee_id
                            )
                            =
                            {$operatorExpr}
                    ),
                    {$cycleAlias}.sequence_no
                )
            )
        ";
    }


    /**
     * =====================================================
     * SUMMARY
     * =====================================================
     *
     * Satu row:
     *
     * Date
     * + Shift
     * + Machine
     * + Operator
     * + Part
     * + Process
     */
    public function rows(
        string $start,
        string $end,
        int $machineId = 0,
        int $partId = 0,
        int $processId = 0,
        int $shiftId = 0,
        int $operatorId = 0
    ): array {

        $operatorExpr =
            $this->operatorExpr();

        $validLoading =
            $this->validLoadingSql();


        $builder =
            $this->db
            ->table(
                'production_cycles pc'
            )
            ->select(
                "
                    d.work_date,

                    sh.id
                        AS shift_id,
                    sh.code
                        AS shift_code,
                    sh.name
                        AS shift_name,
                    sh.start_time
                        AS shift_start_time,

                    m.id
                        AS machine_id,
                    m.code
                        AS machine_code,
                    m.name
                        AS machine_name,

                    {$operatorExpr}
                        AS operator_employee_id,

                    e.nik
                        AS operator_nik,
                    e.name
                        AS operator_name,

                    part.id
                        AS part_id,
                    part.part_number,

                    p.part_process_id,

                    p.process_name_snapshot
                        AS process_name,

                    COUNT(*)
                        AS produced_qty,

                    SUM(
                        CASE
                            WHEN {$validLoading}
                            THEN 1
                            ELSE 0
                        END
                    )
                        AS valid_cycle_count,

                    SUM(
                        pc.machine_time_ms
                    )
                        AS total_machine_ms,

                    SUM(
                        CASE
                            WHEN {$validLoading}
                            THEN pc.loading_time_ms
                            ELSE 0
                        END
                    )
                        AS total_loading_ms,

                    SUM(
                        CASE
                            WHEN {$validLoading}
                            THEN pc.cycle_time_ms
                            ELSE 0
                        END
                    )
                        AS total_cycle_ms,


                    /* STANDARD MACHINE */
                    AVG(
                        p.standard_machine_time_ms
                    )
                        AS standard_machine_ms,


                    /* ACTUAL MACHINE */
                    AVG(
                        pc.machine_time_ms
                    )
                        AS actual_machine_ms,


                    /* STANDARD LOADING */
                    AVG(
                        CASE
                            WHEN {$validLoading}
                            THEN p.standard_loading_time_ms
                        END
                    )
                        AS standard_loading_ms,


                    /* ACTUAL LOADING */
                    AVG(
                        CASE
                            WHEN {$validLoading}
                            THEN pc.loading_time_ms
                        END
                    )
                        AS actual_loading_ms,


                    /* STANDARD CYCLE */
                    AVG(
                        CASE
                            WHEN {$validLoading}
                            THEN
                                p.standard_machine_time_ms
                                +
                                p.standard_loading_time_ms
                        END
                    )
                        AS standard_cycle_ms,


                    /* ACTUAL CYCLE */
                    AVG(
                        CASE
                            WHEN {$validLoading}
                            THEN pc.cycle_time_ms
                        END
                    )
                        AS actual_cycle_ms
                    ",
                false
            )

            ->join(
                'productions p',
                'p.id = pc.production_id'
            )

            ->join(
                'production_shift_details d',
                'd.id = pc.production_shift_detail_id'
            )

            ->join(
                'shifts sh',
                'sh.id = d.shift_id'
            )

            ->join(
                'machines m',
                'm.id = p.machine_id'
            )

            ->join(
                'parts part',
                'part.id = p.part_id'
            )

            ->join(
                'employees e',
                'e.id = '
                    . $operatorExpr,
                'left',
                false
            )

            ->where(
                'p.mode',
                'production'
            )

            ->where(
                'pc.status',
                'completed'
            )

            ->where(
                'd.work_date >=',
                $start
            )

            ->where(
                'd.work_date <=',
                $end
            );


        /*
         * Filters
         */
        foreach (
            [
                'p.machine_id'
                => $machineId,

                'p.part_id'
                => $partId,

                'p.part_process_id'
                => $processId,

                'd.shift_id'
                => $shiftId,
            ]
            as $field => $value
        ) {
            if ($value > 0) {
                $builder->where(
                    $field,
                    $value
                );
            }
        }


        if ($operatorId > 0) {
            $builder->where(
                $operatorExpr
                    . ' = '
                    . (int) $operatorId,
                null,
                false
            );
        }


        $builder
            ->groupBy([
                'd.work_date',

                'sh.id',
                'sh.code',
                'sh.name',
                'sh.start_time',

                'm.id',
                'm.code',
                'm.name',

                'e.nik',
                'e.name',

                'part.id',
                'part.part_number',

                'p.part_process_id',
                'p.process_name_snapshot',
            ])

            ->groupBy(
                $operatorExpr,
                false
            )

            ->orderBy(
                'd.work_date',
                'DESC'
            )

            ->orderBy(
                'sh.start_time',
                'ASC'
            )

            ->orderBy(
                'm.code',
                'ASC'
            )

            ->orderBy(
                'e.name',
                'ASC'
            )

            ->orderBy(
                'part.part_number',
                'ASC'
            )

            ->orderBy(
                'p.part_process_id',
                'ASC'
            );


        $rows =
            $builder
            ->get()
            ->getResultArray();


        foreach (
            $rows
            as &$row
        ) {
            $this->appendVariance(
                $row
            );
        }

        unset($row);


        return $rows;
    }


    /**
     * =====================================================
     * DETAIL ENDPOINT
     * =====================================================
     *
     * Mengembalikan setiap cycle
     * berdasarkan satu row summary.
     */
    public function detail(
        string $workDate,
        int $shiftId,
        int $machineId,
        int $operatorId,
        int $partId,
        int $processId
    ): array {

        $operatorExpr =
            $this->operatorExpr();

        $validLoading =
            $this->validLoadingSql();


        /*
         * =================================================
         * QUERY CYCLE
         * =================================================
         */

        $cycles =
            $this->db
            ->table(
                'production_cycles pc'
            )
            ->select(
                "
                    pc.id
                        AS cycle_id,

                    pc.production_id,

                    pc.production_shift_detail_id,

                    pc.sequence_no,

                    pc.machine_started_at,

                    pc.machine_stopped_at,

                    pc.machine_time_ms,

                    pc.loading_time_ms,

                    pc.cycle_time_ms,

                    pc.counter_total_snapshot,


                    CASE
                        WHEN {$validLoading}
                        THEN 1
                        ELSE 0
                    END
                        AS loading_valid,


                    p.production_code,


                    /*
                     * IMPORTANT:
                     *
                     * Alias disamakan dengan
                     * kontrak JSON frontend.
                     */
                    p.standard_machine_time_ms
                        AS standard_machine_ms,

                    p.standard_loading_time_ms
                        AS standard_loading_ms,

                    (
                        COALESCE(
                            p.standard_machine_time_ms,
                            0
                        )
                        +
                        COALESCE(
                            p.standard_loading_time_ms,
                            0
                        )
                    )
                        AS standard_cycle_ms,


                    d.work_date,


                    sh.code
                        AS shift_code,

                    sh.name
                        AS shift_name,


                    m.code
                        AS machine_code,

                    m.name
                        AS machine_name,


                    {$operatorExpr}
                        AS operator_employee_id,


                    e.nik
                        AS operator_nik,

                    e.name
                        AS operator_name,


                    part.part_number,


                    p.process_name_snapshot
                        AS process_name
                    ",
                false
            )

            ->join(
                'productions p',
                'p.id = pc.production_id'
            )

            ->join(
                'production_shift_details d',
                'd.id = pc.production_shift_detail_id'
            )

            ->join(
                'shifts sh',
                'sh.id = d.shift_id'
            )

            ->join(
                'machines m',
                'm.id = p.machine_id'
            )

            ->join(
                'parts part',
                'part.id = p.part_id'
            )

            ->join(
                'employees e',
                'e.id = '
                    . $operatorExpr,
                'left',
                false
            )

            ->where(
                'p.mode',
                'production'
            )

            ->where(
                'pc.status',
                'completed'
            )

            ->where(
                'd.work_date',
                $workDate
            )

            ->where(
                'd.shift_id',
                $shiftId
            )

            ->where(
                'p.machine_id',
                $machineId
            )

            ->where(
                $operatorExpr
                    . ' = '
                    . (int) $operatorId,
                null,
                false
            )

            ->where(
                'p.part_id',
                $partId
            )

            ->where(
                'p.part_process_id',
                $processId
            )

            ->orderBy(
                'pc.machine_stopped_at',
                'ASC'
            )

            ->orderBy(
                'pc.id',
                'ASC'
            )

            ->get()
            ->getResultArray();


        /*
         * Tidak dianggap error.
         *
         * Modal tetap bisa menampilkan
         * "Tidak ada cycle".
         */
        if (! $cycles) {
            return [
                'meta' => [
                    'work_date'
                    => $workDate,

                    'shift_code'
                    => null,

                    'shift_name'
                    => null,

                    'machine_code'
                    => null,

                    'machine_name'
                    => null,

                    'operator_employee_id'
                    => $operatorId,

                    'operator_nik'
                    => null,

                    'operator_name'
                    => null,

                    'part_number'
                    => null,

                    'process_name'
                    => null,
                ],

                'summary' => [
                    'produced_qty' => 0,

                    'valid_cycle_count' => 0,

                    'total_machine_ms' => 0,

                    'total_loading_ms' => 0,

                    'total_cycle_ms' => 0,

                    'standard_machine_ms'
                    => null,

                    'actual_machine_ms'
                    => null,

                    'standard_loading_ms'
                    => null,

                    'actual_loading_ms'
                    => null,

                    'standard_cycle_ms'
                    => null,

                    'actual_cycle_ms'
                    => null,
                ],

                'cycles' => [],

                'operator_snapshot_enabled'
                => $this->hasOperatorSnapshot(),
            ];
        }


        /*
         * =================================================
         * CALCULATE DETAIL
         * =================================================
         */

        $machineValues = [];

        $loadingValues = [];

        $cycleValues = [];

        $standardMachineValues = [];

        $standardLoadingValues = [];

        $standardCycleValues = [];


        foreach (
            $cycles
            as &$cycle
        ) {

            /*
             * Actual machine
             */
            $machineMs =
                $cycle['machine_time_ms']
                !== null
                ? (int) $cycle['machine_time_ms']
                : null;


            /*
             * Loading validity
             */
            $loadingValid =
                (int) (
                    $cycle['loading_valid']
                    ?? 0
                ) === 1;


            /*
             * Actual loading
             */
            $loadingMs =
                $loadingValid
                && $cycle['loading_time_ms'] !== null

                ? (int) $cycle['loading_time_ms']

                : null;


            /*
             * Actual cycle
             */
            $cycleMs =
                $loadingValid
                && $cycle['cycle_time_ms'] !== null

                ? (int) $cycle['cycle_time_ms']

                : null;


            /*
             * Standard machine
             */
            $standardMachineMs =
                $cycle['standard_machine_ms'] !== null

                ? (int) $cycle['standard_machine_ms']

                : null;


            /*
             * Standard loading
             */
            $standardLoadingMs =
                $cycle['standard_loading_ms'] !== null

                ? (int) $cycle['standard_loading_ms']

                : null;


            /*
             * Standard cycle
             */
            $standardCycleMs =
                (
                    $standardMachineMs
                    !== null
                    &&
                    $standardLoadingMs
                    !== null
                )

                ? (
                    $standardMachineMs
                    +
                    $standardLoadingMs
                )

                : null;


            /*
             * Normalisasi kembali field
             * supaya JSON konsisten.
             */
            $cycle['standard_machine_ms'] = $standardMachineMs;

            $cycle['standard_loading_ms'] = $standardLoadingMs;

            $cycle['standard_cycle_ms'] = $standardCycleMs;


            /*
             * Variance Machine
             */
            $cycle['machine_variance_ms'] =
                (
                    $machineMs === null
                    ||
                    $standardMachineMs
                    === null
                )

                ? null

                : (
                    $machineMs
                    -
                    $standardMachineMs
                );


            /*
             * Variance Loading
             */
            $cycle['loading_variance_ms'] =
                (
                    ! $loadingValid
                    ||
                    $loadingMs === null
                    ||
                    $standardLoadingMs
                    === null
                )

                ? null

                : (
                    $loadingMs
                    -
                    $standardLoadingMs
                );


            /*
             * Variance Cycle
             */
            $cycle['cycle_variance_ms'] =
                (
                    ! $loadingValid
                    ||
                    $cycleMs === null
                    ||
                    $standardCycleMs
                    === null
                )

                ? null

                : (
                    $cycleMs
                    -
                    $standardCycleMs
                );


            /*
             * Actual machine sample
             */
            if (
                $machineMs !== null
            ) {
                $machineValues[] =
                    $machineMs;
            }


            /*
             * Standard machine sample
             */
            if (
                $standardMachineMs
                !== null
            ) {
                $standardMachineValues[] =
                    $standardMachineMs;
            }


            /*
             * Loading/Cycle hanya valid
             * setelah first operator cycle.
             */
            if ($loadingValid) {

                if (
                    $loadingMs !== null
                ) {
                    $loadingValues[] =
                        $loadingMs;
                }

                if (
                    $cycleMs !== null
                ) {
                    $cycleValues[] =
                        $cycleMs;
                }

                if (
                    $standardLoadingMs
                    !== null
                ) {
                    $standardLoadingValues[] =
                        $standardLoadingMs;
                }

                if (
                    $standardCycleMs
                    !== null
                ) {
                    $standardCycleValues[] =
                        $standardCycleMs;
                }
            }
        }

        unset($cycle);


        /*
         * =================================================
         * SUMMARY
         * =================================================
         */

        $summary = [

            'produced_qty'
            => count($cycles),

            'valid_cycle_count'
            => count($cycleValues),


            /*
             * TOTAL ACTUAL
             */
            'total_machine_ms'
            => array_sum(
                $machineValues
            ),

            'total_loading_ms'
            => array_sum(
                $loadingValues
            ),

            'total_cycle_ms'
            => array_sum(
                $cycleValues
            ),


            /*
             * STANDARD AVG
             */
            'standard_machine_ms'
            => $standardMachineValues

                ? array_sum(
                    $standardMachineValues
                )
                / count(
                    $standardMachineValues
                )

                : null,


            'standard_loading_ms'
            => $standardLoadingValues

                ? array_sum(
                    $standardLoadingValues
                )
                / count(
                    $standardLoadingValues
                )

                : null,


            'standard_cycle_ms'
            => $standardCycleValues

                ? array_sum(
                    $standardCycleValues
                )
                / count(
                    $standardCycleValues
                )

                : null,


            /*
             * ACTUAL AVG
             */
            'actual_machine_ms'
            => $machineValues

                ? array_sum(
                    $machineValues
                )
                / count(
                    $machineValues
                )

                : null,


            'actual_loading_ms'
            => $loadingValues

                ? array_sum(
                    $loadingValues
                )
                / count(
                    $loadingValues
                )

                : null,


            'actual_cycle_ms'
            => $cycleValues

                ? array_sum(
                    $cycleValues
                )
                / count(
                    $cycleValues
                )

                : null,
        ];


        /*
         * Summary Variance
         */
        $this->appendVariance(
            $summary
        );


        /*
         * =================================================
         * RESPONSE
         * =================================================
         */

        return [

            'meta' => [

                'work_date'
                => $cycles[0]['work_date'],

                'shift_code'
                => $cycles[0]['shift_code'],

                'shift_name'
                => $cycles[0]['shift_name'],

                'machine_code'
                => $cycles[0]['machine_code'],

                'machine_name'
                => $cycles[0]['machine_name'],

                'operator_employee_id'
                => (int) $cycles[0]['operator_employee_id'],

                'operator_nik'
                => $cycles[0]['operator_nik'],

                'operator_name'
                => $cycles[0]['operator_name'],

                'part_number'
                => $cycles[0]['part_number'],

                'process_name'
                => $cycles[0]['process_name'],
            ],


            'summary'
            => $summary,


            'cycles'
            => $cycles,


            'operator_snapshot_enabled'
            => $this
                ->hasOperatorSnapshot(),
        ];
    }


    /**
     * Tambahkan variance:
     *
     * Actual - Standard
     */
    private function appendVariance(
        array &$row
    ): void {

        foreach (
            [
                'machine',
                'loading',
                'cycle',
            ]
            as $metric
        ) {

            $actual =
                $row['actual_'
                    . $metric
                    . '_ms']
                ?? null;


            $standard =
                $row['standard_'
                    . $metric
                    . '_ms']
                ?? null;


            $row[$metric
                . '_variance_ms'] =
                (
                    $actual === null
                    ||
                    $standard === null
                )

                ? null

                : (
                    (float) $actual
                    -
                    (float) $standard
                );
        }
    }
}
