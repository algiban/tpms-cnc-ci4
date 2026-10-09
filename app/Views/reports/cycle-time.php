<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?php

$rows = $rows ?? [];
$machines = $machines ?? [];
$parts = $parts ?? [];
$processes = $processes ?? [];
$shifts = $shifts ?? [];
$operators = $operators ?? [];
$filters = $filters ?? [];

$totalRows = count($rows);
$totalProduced = 0;
$totalValidCycles = 0;
$totalMachineMs = 0.0;
$totalLoadingMs = 0.0;
$weightedCycleMs = 0.0;

foreach ($rows as $row) {
    $produced = (int) ($row['produced_qty'] ?? 0);
    $valid = (int) ($row['valid_cycle_count'] ?? 0);

    $totalProduced += $produced;
    $totalValidCycles += $valid;
    $totalMachineMs += (float) ($row['total_machine_ms'] ?? 0);
    $totalLoadingMs += (float) ($row['total_loading_ms'] ?? 0);

    if (
        $valid > 0
        && isset($row['actual_cycle_ms'])
        && $row['actual_cycle_ms'] !== null
    ) {
        $weightedCycleMs +=
            (float) $row['actual_cycle_ms'] * $valid;
    }
}

$avgMachineMs = $totalProduced > 0
    ? $totalMachineMs / $totalProduced
    : null;

$avgLoadingMs = $totalValidCycles > 0
    ? $totalLoadingMs / $totalValidCycles
    : null;

$avgCycleMs = $totalValidCycles > 0
    ? $weightedCycleMs / $totalValidCycles
    : null;


/*
|--------------------------------------------------------------------------
| Formatter
|--------------------------------------------------------------------------
*/

$formatSeconds = static function ($value, int $decimals = 3): string {
    if ($value === null) {
        return '—';
    }

    return number_format(
        (float) $value / 1000,
        $decimals
    ) . 's';
};

$formatDuration = static function ($value): string {
    if ($value === null) {
        return '—';
    }

    $totalSeconds = max(
        0,
        (int) round((float) $value / 1000)
    );

    $hours = intdiv($totalSeconds, 3600);
    $minutes = intdiv($totalSeconds % 3600, 60);
    $seconds = $totalSeconds % 60;

    return $hours > 0
        ? sprintf(
            '%02d:%02d:%02d',
            $hours,
            $minutes,
            $seconds
        )
        : sprintf(
            '%02d:%02d',
            $minutes,
            $seconds
        );
};

$formatVariance = static function ($value): string {
    if ($value === null) {
        return '—';
    }

    $seconds = (float) $value / 1000;

    return ($seconds > 0 ? '+' : '')
        . number_format($seconds, 3)
        . 's';
};

$varianceClass = static function ($value): string {
    if (
        $value === null
        || (float) $value === 0.0
    ) {
        return 'neutral';
    }

    return (float) $value > 0
        ? 'bad'
        : 'good';
};

?>

<style>
    /* =========================================================
   GENERAL
   ========================================================= */

    .ctr-note {
        display: flex;
        gap: 10px;
        align-items: flex-start;

        margin-bottom: 16px;
        padding: 12px 14px;

        border: 1px solid #dbe5f1;
        border-radius: 12px;

        background: #f8fbff;
        color: #667085;

        font-size: 11px;
        line-height: 1.6;
    }

    .ctr-note i {
        margin-top: 2px;
        color: #4f46e5;
    }

    .ctr-note strong {
        color: #344054;
    }


    /* =========================================================
   FILTER
   ========================================================= */

    .ctr-filter-result {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;

        margin-top: 10px;

        color: #7c8da6;

        font-size: 11px;
        font-weight: 650;
    }

    .ctr-filter-result strong {
        color: #344054;
    }


    /* =========================================================
   TABLE TOOLBAR
   ========================================================= */

    .ctr-table-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 12px;
        margin-bottom: 14px;
    }

    .ctr-table-title {
        display: flex;
        align-items: center;
        gap: 8px;

        color: #253149;

        font-size: 14px;
        font-weight: 850;
    }

    .ctr-table-title-icon {
        width: 32px;
        height: 32px;

        display: grid;
        place-items: center;

        border-radius: 9px;

        background: #eef2ff;
        color: #4f46e5;
    }

    .ctr-table-count {
        padding: 5px 9px;

        border-radius: 999px;

        background: #f2f4f7;
        color: #667085;

        font-size: 10px;
        font-weight: 800;
    }


    /* =========================================================
   TABLE ENTITY
   ========================================================= */

    .ctr-date strong,
    .ctr-entity strong {
        display: block;

        color: #253149;

        font-size: 12px;
        font-weight: 850;

        white-space: nowrap;
    }

    .ctr-date small,
    .ctr-entity small {
        display: block;

        margin-top: 3px;

        color: #98a2b3;

        font-size: 10px;
        font-weight: 650;

        white-space: nowrap;
    }


    /* =========================================================
   OPERATOR
   ========================================================= */

    .ctr-operator {
        display: flex;
        align-items: center;

        gap: 8px;

        min-width: 145px;
    }

    .ctr-avatar {
        width: 30px;
        height: 30px;

        display: grid;
        place-items: center;

        flex: 0 0 30px;

        border-radius: 50%;

        background: #eef2ff;
        color: #4f46e5;

        font-size: 11px;
        font-weight: 850;
    }

    .ctr-operator strong {
        display: block;

        color: #344054;

        font-size: 11px;
        font-weight: 850;
    }

    .ctr-operator small {
        display: block;

        color: #98a2b3;

        font-size: 9px;
        font-weight: 650;
    }


    /* =========================================================
   PRODUCED
   ========================================================= */

    .ctr-produced {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        min-width: 52px;

        padding: 6px 9px;

        border-radius: 9px;

        background: #eefbf3;
        color: #087443;

        font-size: 11px;
        font-weight: 850;
    }

    .ctr-valid {
        display: block;

        margin-top: 4px;

        color: #98a2b3;

        font-size: 9px;

        white-space: nowrap;
    }


    /* =========================================================
   MAIN TABLE GROUP HEADER
   ========================================================= */

    .ctr-group-head {
        text-align: center !important;

        background: #f8fafc !important;
        color: #475467 !important;

        font-size: 9px !important;
        font-weight: 850 !important;

        text-transform: uppercase !important;
        letter-spacing: .03em !important;

        border-left: 1px solid #e8ecf2 !important;
    }

    .ctr-sub-head {
        text-align: right !important;

        background: #fbfcfd !important;
        color: #98a2b3 !important;

        font-size: 8px !important;
        font-weight: 800 !important;

        text-transform: uppercase !important;
    }

    .ctr-group-start {
        border-left: 1px solid #e8ecf2 !important;
    }


    /* =========================================================
   TIME
   ========================================================= */

    .ctr-time {
        font-family:
            ui-monospace,
            SFMono-Regular,
            Menlo,
            Monaco,
            Consolas,
            monospace;

        color: #475467;

        font-size: 10px;
        font-weight: 700;

        white-space: nowrap;
    }

    .ctr-total {
        color: #344054;
        font-weight: 800;
    }


    /* =========================================================
   VARIANCE
   ========================================================= */

    .ctr-variance {
        display: inline-flex;
        align-items: center;

        gap: 3px;

        padding: 4px 6px;

        border-radius: 7px;

        font-family:
            ui-monospace,
            SFMono-Regular,
            Menlo,
            Monaco,
            Consolas,
            monospace;

        font-size: 9px;
        font-weight: 800;

        white-space: nowrap;
    }

    .ctr-variance.bad {
        background: #fef2f2;
        color: #dc2626;
    }

    .ctr-variance.good {
        background: #ecfdf3;
        color: #027a48;
    }

    .ctr-variance.neutral {
        background: #f2f4f7;
        color: #667085;
    }


    /* =========================================================
   DETAIL BUTTON
   ========================================================= */

    .ctr-detail-btn {
        width: 34px;
        height: 34px;

        display: grid;
        place-items: center;

        padding: 0;

        border-radius: 9px;
    }


    /* =========================================================
   EMPTY
   ========================================================= */

    .ctr-empty {
        padding: 52px 24px !important;

        text-align: center;

        color: #98a2b3 !important;
    }

    .ctr-empty i {
        display: block;

        margin-bottom: 9px;

        font-size: 32px;
    }


    /* =========================================================
   PERIOD
   ========================================================= */

    .ctr-period {
        display: inline-flex;
        align-items: center;

        gap: 6px;

        padding: 5px 8px;

        border-radius: 8px;

        background: #f8fafc;

        border: 1px solid #edf0f5;

        color: #667085;

        font-size: 10px;
        font-weight: 700;
    }

    .ctr-stat-value {
        font-size: 18px !important;
    }


    /* =========================================================
   MODAL
   ========================================================= */

    .ctr-modal-meta {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;

        margin-bottom: 14px;
    }

    .ctr-meta-pill {
        padding: 6px 9px;

        border: 1px solid #e5eaf1;
        border-radius: 9px;

        background: #f8fafc;
        color: #475467;

        font-size: 10px;
        font-weight: 700;
    }

    .ctr-detail-stats {
        display: grid;

        grid-template-columns:
            repeat(4, minmax(0, 1fr));

        gap: 10px;

        margin-bottom: 14px;
    }

    .ctr-detail-stat {
        padding: 11px 12px;

        border: 1px solid #edf0f5;
        border-radius: 11px;

        background: #fff;
    }

    .ctr-detail-stat span {
        display: block;

        color: #98a2b3;

        font-size: 9px;
        font-weight: 750;

        text-transform: uppercase;
    }

    .ctr-detail-stat strong {
        display: block;

        margin-top: 3px;

        color: #253149;

        font-size: 15px;
        font-weight: 850;
    }

    .ctr-first-cycle {
        display: inline-flex;

        padding: 3px 6px;

        border-radius: 6px;

        background: #f2f4f7;
        color: #667085;

        font-size: 9px;
        font-weight: 750;

        white-space: nowrap;
    }


    /* =========================================================
   DETAIL ACTUAL / STANDARD HEADER
   ========================================================= */

    #cycleDetailModal .ctr-detail-group-head {
        text-align: center !important;

        padding: 10px 8px;

        background: #f8fafc !important;
        color: #475467 !important;

        border-left: 1px solid #e5e7eb;
        border-bottom: 1px solid #e5e7eb;

        font-size: 10px;
        font-weight: 850;

        text-transform: uppercase;
        letter-spacing: .04em;
    }

    #cycleDetailModal .ctr-detail-sub-head {
        text-align: right !important;

        padding: 8px;

        background: #fbfcfd !important;
        color: #98a2b3 !important;

        font-size: 9px;
        font-weight: 800;

        text-transform: uppercase;

        white-space: nowrap;
    }

    #cycleDetailModal thead th {
        vertical-align: middle;
    }

    #cycleDetailModal tbody td {
        white-space: nowrap;
    }


    /* =========================================================
   MODAL SIZE
   ========================================================= */

    #cycleDetailModal .modal-dialog {
        width: calc(100% - 32px);
        max-width: 900px;

        margin-left: auto;
        margin-right: auto;
    }

    #cycleDetailModal .modal-content {
        max-height: 82vh;

        border-radius: 14px;

        overflow: hidden;
    }

    #cycleDetailModal .modal-header,
    #cycleDetailModal .modal-footer {
        flex-shrink: 0;
    }

    #cycleDetailModal .modal-body {
        overflow-y: auto;
        overflow-x: auto;
    }


    /* =========================================================
   FALLBACK MODAL
   ========================================================= */

    #cycleDetailModal.ctr-modal-fallback-open {
        display: block !important;

        opacity: 1 !important;

        background: rgba(15, 23, 42, .45);

        overflow-y: auto;
    }

    #cycleDetailModal.ctr-modal-fallback-open .modal-dialog {
        transform: none !important;
    }


    /* =========================================================
   RESPONSIVE
   ========================================================= */

    @media (max-width: 991.98px) {
        .ctr-detail-stats {
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
        }

        #cycleDetailModal .modal-dialog {
            max-width: 760px;
        }
    }

    @media (max-width: 767.98px) {

        .ctr-table-toolbar,
        .ctr-filter-result {
            align-items: flex-start;
            flex-direction: column;
        }

        .ctr-detail-stats {
            grid-template-columns:
                1fr 1fr;
        }

        #cycleDetailModal .modal-dialog {
            width: calc(100% - 20px);
            max-width: none;

            margin: 10px auto;
        }

        #cycleDetailModal .modal-content {
            max-height: 90vh;
        }
    }
</style>


<!-- =====================================================
     PAGE HEADING
     ===================================================== -->

<div class="page-heading">

    <div>

        <h1>
            Cycle Time
            <span>Report</span>
        </h1>

        <div class="page-subtitle">
            Per hari, shift, mesin, operator,
            part, dan process.
            Klik Detail untuk melihat cycle setiap part.
        </div>

    </div>


    <div class="action-row">

        <a
            class="btn btn-outline-primary"
            href="<?= esc(
                        site_url('reports/cycle-time/export')
                            . '?'
                            . http_build_query(
                                [
                                    'start' => $start,
                                    'end' => $end,
                                ]
                                    + $filters
                            ),
                        'attr'
                    ) ?>">
            <i class="bi bi-download me-2"></i>
            Export CSV
        </a>

    </div>

</div>


<!-- =====================================================
     FILTER
     ===================================================== -->

<form
    class="panel filter-panel mb-3"
    method="get">

    <div class="row g-3 align-items-end">


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cycleStart">
                From
            </label>

            <input
                class="form-control"
                id="cycleStart"
                type="date"
                name="start"
                value="<?= esc($start, 'attr') ?>"
                required>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cycleEnd">
                To
            </label>

            <input
                class="form-control"
                id="cycleEnd"
                type="date"
                name="end"
                value="<?= esc($end, 'attr') ?>"
                required>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cycleShift">
                Shift
            </label>

            <select
                class="form-select"
                id="cycleShift"
                name="shift_id">

                <option value="0">
                    All Shifts
                </option>

                <?php foreach ($shifts as $o): ?>

                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= (
                            (int) $o['id']
                            ===
                            (int) ($filters['shift_id'] ?? 0)
                        )
                            ? 'selected'
                            : ''
                        ?>>
                        <?= esc(
                            $o['name']
                                ?? $o['code']
                                ?? '-'
                        ) ?>
                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cycleMachine">
                Machine
            </label>

            <select
                class="form-select"
                id="cycleMachine"
                name="machine_id">

                <option value="0">
                    All Machines
                </option>

                <?php foreach ($machines as $o): ?>

                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= (
                            (int) $o['id']
                            ===
                            (int) ($filters['machine_id'] ?? 0)
                        )
                            ? 'selected'
                            : ''
                        ?>>
                        <?= esc($o['code'] ?? '-') ?>
                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cycleOperator">
                Operator
            </label>

            <select
                class="form-select"
                id="cycleOperator"
                name="operator_employee_id">

                <option value="0">
                    All Operators
                </option>

                <?php foreach ($operators as $o): ?>

                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= (
                            (int) $o['id']
                            ===
                            (int) (
                                $filters['operator_employee_id']
                                ?? 0
                            )
                        )
                            ? 'selected'
                            : ''
                        ?>>
                        <?= esc(
                            ($o['name'] ?? '-')
                                . ' · '
                                . ($o['nik'] ?? '-')
                        ) ?>
                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <label
                class="filter-label"
                for="cyclePart">
                Part
            </label>

            <select
                class="form-select"
                id="cyclePart"
                name="part_id">

                <option value="0">
                    All Parts
                </option>

                <?php foreach ($parts as $o): ?>

                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= (
                            (int) $o['id']
                            ===
                            (int) ($filters['part_id'] ?? 0)
                        )
                            ? 'selected'
                            : ''
                        ?>>
                        <?= esc(
                            $o['part_number']
                                ?? '-'
                        ) ?>
                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="col-12 col-md-4 col-xl-3">

            <label
                class="filter-label"
                for="cycleProcess">
                Process
            </label>

            <select
                class="form-select"
                id="cycleProcess"
                name="part_process_id">

                <option value="0">
                    All Processes
                </option>

                <?php foreach ($processes as $o): ?>

                    <option
                        value="<?= (int) $o['id'] ?>"
                        <?= (
                            (int) $o['id']
                            ===
                            (int) (
                                $filters['part_process_id']
                                ?? 0
                            )
                        )
                            ? 'selected'
                            : ''
                        ?>>
                        <?= esc(
                            ($o['part_number'] ?? '-')
                                . ' · '
                                . ($o['process_name'] ?? '-')
                        ) ?>
                    </option>

                <?php endforeach ?>

            </select>

        </div>


        <div class="col-12 col-md-4 col-xl-2">

            <button
                type="submit"
                class="btn btn-primary w-100">
                <i class="bi bi-funnel me-1"></i>
                Apply Filter
            </button>

        </div>

    </div>


    <div class="ctr-filter-result">

        <span>

            <i class="bi bi-calendar3 me-1"></i>

            Period

            <strong>
                <?= esc(
                    date(
                        'd M Y',
                        strtotime($start)
                    )
                ) ?>
            </strong>

            →

            <strong>
                <?= esc(
                    date(
                        'd M Y',
                        strtotime($end)
                    )
                ) ?>
            </strong>

        </span>


        <a
            href="<?= esc(
                        site_url('reports/cycle-time'),
                        'attr'
                    ) ?>"
            class="text-decoration-none">
            <i
                class="
                    bi
                    bi-arrow-counterclockwise
                    me-1
                "></i>

            Reset Filter
        </a>

    </div>

</form>


<!-- =====================================================
     NOTE
     ===================================================== -->

<div class="ctr-note">

    <i class="bi bi-info-circle-fill"></i>

    <div>

        <strong>
            Calculation Note:
        </strong>

        satu row mewakili kombinasi
        Date + Shift + Machine + Operator + Part + Process.

        Operator disimpan sebagai snapshot pada setiap cycle.

    </div>

</div>


<!-- =====================================================
     SUMMARY CARDS
     ===================================================== -->

<div class="stat-grid">


    <div class="stat-card blue">

        <div class="stat-label">
            Report Groups
        </div>

        <div class="stat-value ctr-stat-value">
            <?= number_format($totalRows) ?>
        </div>

        <div class="stat-meta text-primary">
            Date · Shift · Operator · Part
        </div>

        <div class="stat-icon text-primary">
            <i class="bi bi-table"></i>
        </div>

    </div>


    <div class="stat-card green">

        <div class="stat-label">
            Produced Parts
        </div>

        <div
            class="
                stat-value
                ctr-stat-value
                text-success
            ">
            <?= number_format($totalProduced) ?>
        </div>

        <div class="stat-meta text-success">

            <?= number_format($totalValidCycles) ?>

            comparable cycles

        </div>

        <div class="stat-icon text-success">
            <i class="bi bi-box-seam"></i>
        </div>

    </div>


    <div class="stat-card orange">

        <div class="stat-label">
            Avg Machine Time
        </div>

        <div class="stat-value ctr-stat-value">
            <?= $formatSeconds($avgMachineMs) ?>
        </div>

        <div class="stat-meta">
            Across <?= number_format($totalProduced) ?>
            produced parts
        </div>

        <div class="stat-icon">
            <i class="bi bi-stopwatch"></i>
        </div>

    </div>


    <div class="stat-card purple">

        <div class="stat-label">
            Avg Cycle Time
        </div>

        <div class="stat-value ctr-stat-value">
            <?= $formatSeconds($avgCycleMs) ?>
        </div>

        <div class="stat-meta">

            <?= $formatSeconds($avgLoadingMs) ?>

            avg loading

        </div>

        <div class="stat-icon">
            <i class="bi bi-activity"></i>
        </div>

    </div>

</div>


<!-- =====================================================
     MAIN TABLE
     ===================================================== -->

<div class="panel p-3">


    <div class="ctr-table-toolbar">

        <div>

            <div class="ctr-table-title">

                <span class="ctr-table-title-icon">
                    <i class="bi bi-stopwatch"></i>
                </span>

                Cycle Time Summary

            </div>


            <div class="mt-1">

                <span class="ctr-period">

                    <i class="bi bi-calendar-range"></i>

                    <?= esc(
                        date(
                            'd M Y',
                            strtotime($start)
                        )
                    ) ?>

                    –

                    <?= esc(
                        date(
                            'd M Y',
                            strtotime($end)
                        )
                    ) ?>

                </span>

            </div>

        </div>


        <div class="ctr-table-count">

            <i class="bi bi-database me-1"></i>

            <?= number_format($totalRows) ?>

            groups

        </div>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th rowspan="2">
                        Date / Shift
                    </th>

                    <th rowspan="2">
                        Machine
                    </th>

                    <th rowspan="2">
                        Operator
                    </th>

                    <th rowspan="2">
                        Part / Process
                    </th>

                    <th
                        rowspan="2"
                        class="text-center">
                        Produced
                    </th>


                    <th
                        colspan="3"
                        class="ctr-group-head">
                        Total Time
                    </th>


                    <th
                        colspan="3"
                        class="ctr-group-head">
                        Average Time
                    </th>


                    <th
                        colspan="2"
                        class="ctr-group-head">
                        Cycle vs Standard
                    </th>


                    <th
                        rowspan="2"
                        class="text-center">
                        Detail
                    </th>

                </tr>


                <tr>

                    <th
                        class="
                            ctr-sub-head
                            ctr-group-start
                        ">
                        Machine
                    </th>

                    <th class="ctr-sub-head">
                        Loading
                    </th>

                    <th class="ctr-sub-head">
                        Cycle
                    </th>


                    <th
                        class="
                            ctr-sub-head
                            ctr-group-start
                        ">
                        Machine
                    </th>

                    <th class="ctr-sub-head">
                        Loading
                    </th>

                    <th class="ctr-sub-head">
                        Cycle
                    </th>


                    <th
                        class="
                            ctr-sub-head
                            ctr-group-start
                        ">
                        Standard
                    </th>

                    <th class="ctr-sub-head">
                        Variance
                    </th>

                </tr>

            </thead>


            <tbody>


                <?php foreach ($rows as $row): ?>

                    <?php

                    $operatorName = trim(
                        (string) (
                            $row['operator_name']
                            ?? ''
                        )
                    ) ?: 'Unknown';

                    $initials = strtoupper(
                        substr(
                            $operatorName,
                            0,
                            1
                        )
                    );

                    ?>


                    <tr>


                        <td>

                            <div class="ctr-date">

                                <strong>
                                    <?= esc(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                $row['work_date']
                                            )
                                        )
                                    ) ?>
                                </strong>

                                <small>
                                    <?= esc(
                                        $row['shift_name']
                                            ?? $row['shift_code']
                                            ?? '-'
                                    ) ?>
                                </small>

                            </div>

                        </td>


                        <td>

                            <div class="ctr-entity">

                                <strong>
                                    <?= esc(
                                        $row['machine_code']
                                            ?? '-'
                                    ) ?>
                                </strong>

                                <small>
                                    <?= esc(
                                        $row['machine_name']
                                            ?? ''
                                    ) ?>
                                </small>

                            </div>

                        </td>


                        <td>

                            <div class="ctr-operator">

                                <span class="ctr-avatar">
                                    <?= esc($initials) ?>
                                </span>

                                <span>

                                    <strong>
                                        <?= esc($operatorName) ?>
                                    </strong>

                                    <small>
                                        <?= esc(
                                            $row['operator_nik']
                                                ?? '-'
                                        ) ?>
                                    </small>

                                </span>

                            </div>

                        </td>


                        <td>

                            <div class="ctr-entity">

                                <strong>
                                    <?= esc(
                                        $row['part_number']
                                            ?? '-'
                                    ) ?>
                                </strong>

                                <small>

                                    <i
                                        class="
                                        bi
                                        bi-diagram-3
                                        me-1
                                    "></i>

                                    <?= esc(
                                        $row['process_name']
                                            ?? '-'
                                    ) ?>

                                </small>

                            </div>

                        </td>


                        <td class="text-center">

                            <span class="ctr-produced">
                                <?= number_format(
                                    (int) (
                                        $row['produced_qty']
                                        ?? 0
                                    )
                                ) ?>
                            </span>

                            <span class="ctr-valid">

                                <?= number_format(
                                    (int) (
                                        $row['valid_cycle_count']
                                        ?? 0
                                    )
                                ) ?>

                                comparable cycles

                            </span>

                        </td>


                        <td
                            class="
                            text-end
                            ctr-group-start
                        ">
                            <span class="ctr-time ctr-total">
                                <?= $formatDuration(
                                    $row['total_machine_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td class="text-end">
                            <span class="ctr-time ctr-total">
                                <?= $formatDuration(
                                    $row['total_loading_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td class="text-end">
                            <span class="ctr-time ctr-total">
                                <?= $formatDuration(
                                    $row['total_cycle_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td
                            class="
                            text-end
                            ctr-group-start
                        ">
                            <span class="ctr-time">
                                <?= $formatSeconds(
                                    $row['actual_machine_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td class="text-end">
                            <span class="ctr-time">
                                <?= $formatSeconds(
                                    $row['actual_loading_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td class="text-end">
                            <span class="ctr-time">
                                <?= $formatSeconds(
                                    $row['actual_cycle_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td
                            class="
                            text-end
                            ctr-group-start
                        ">
                            <span class="ctr-time">
                                <?= $formatSeconds(
                                    $row['standard_cycle_ms']
                                        ?? null
                                ) ?>
                            </span>
                        </td>


                        <td class="text-end">

                            <span
                                class="
                                ctr-variance
                                <?= $varianceClass(
                                    $row['cycle_variance_ms']
                                        ?? null
                                ) ?>
                            ">
                                <?= $formatVariance(
                                    $row['cycle_variance_ms']
                                        ?? null
                                ) ?>
                            </span>

                        </td>


                        <td class="text-center">

                            <button
                                type="button"
                                class="
                                btn
                                btn-outline-primary
                                ctr-detail-btn
                                js-cycle-detail
                            "
                                title="Detail cycle"

                                data-work-date="<?= esc(
                                                    $row['work_date'],
                                                    'attr'
                                                ) ?>"

                                data-shift-id="<?= (int) (
                                                    $row['shift_id']
                                                    ?? 0
                                                ) ?>"

                                data-machine-id="<?= (int) (
                                                        $row['machine_id']
                                                        ?? 0
                                                    ) ?>"

                                data-operator-id="<?= (int) (
                                                        $row['operator_employee_id']
                                                        ?? 0
                                                    ) ?>"

                                data-part-id="<?= (int) (
                                                    $row['part_id']
                                                    ?? 0
                                                ) ?>"

                                data-process-id="<?= (int) (
                                                        $row['part_process_id']
                                                        ?? 0
                                                    ) ?>">
                                <i class="bi bi-eye"></i>
                            </button>

                        </td>

                    </tr>


                <?php endforeach ?>


                <?php if (!$rows): ?>

                    <tr>

                        <td
                            colspan="14"
                            class="ctr-empty">

                            <i class="bi bi-stopwatch"></i>

                            No measured production cycles
                            for this selection.

                        </td>

                    </tr>

                <?php endif ?>


            </tbody>

        </table>

    </div>

</div>


<!-- =====================================================
     DETAIL MODAL
     ===================================================== -->

<div
    class="modal fade"
    id="cycleDetailModal"
    tabindex="-1"
    aria-labelledby="cycleDetailTitle"
    aria-hidden="true">

    <div
        class="
            modal-dialog
            modal-lg
            modal-dialog-centered
            modal-dialog-scrollable
        ">

        <div class="modal-content">


            <!-- HEADER -->

            <div class="modal-header">

                <div>

                    <h5
                        class="modal-title"
                        id="cycleDetailTitle">
                        Cycle Detail
                    </h5>

                    <div
                        id="cycleDetailSubtitle"
                        class="
                            text-muted
                            small
                            mt-1
                        ">
                        Loading...
                    </div>

                </div>


                <button
                    type="button"
                    class="
                        btn-close
                        js-cycle-modal-close
                    "
                    data-bs-dismiss="modal"
                    aria-label="Close"></button>

            </div>


            <!-- BODY -->

            <div class="modal-body">


                <!-- LOADING -->

                <div
                    id="cycleDetailLoading"
                    class="
                        py-5
                        text-center
                        text-muted
                    ">

                    <div
                        class="
                            spinner-border
                            spinner-border-sm
                            me-2
                        "
                        role="status"></div>

                    Loading cycle data...

                </div>


                <!-- CONTENT -->

                <div
                    id="cycleDetailContent"
                    class="d-none">


                    <div
                        id="cycleDetailMeta"
                        class="ctr-modal-meta"></div>


                    <!-- STATS -->

                    <div class="ctr-detail-stats">


                        <div class="ctr-detail-stat">

                            <span>
                                Produced
                            </span>

                            <strong id="detailProduced">
                                0
                            </strong>

                        </div>


                        <div class="ctr-detail-stat">

                            <span>
                                Total Machine
                            </span>

                            <strong id="detailTotalMachine">
                                —
                            </strong>

                        </div>


                        <div class="ctr-detail-stat">

                            <span>
                                Total Loading
                            </span>

                            <strong id="detailTotalLoading">
                                —
                            </strong>

                        </div>


                        <div class="ctr-detail-stat">

                            <span>
                                Avg Cycle
                            </span>

                            <strong id="detailAvgCycle">
                                —
                            </strong>

                        </div>


                    </div>


                    <!-- DETAIL TABLE -->

                    <div class="table-responsive">

                        <table
                            class="
                                table
                                table-sm
                                align-middle
                            ">

                            <thead>

                                <!-- LEVEL 1 -->

                                <tr>

                                    <th
                                        rowspan="2"
                                        class="align-middle">
                                        Part #
                                    </th>

                                    <th
                                        rowspan="2"
                                        class="align-middle">
                                        Completed
                                    </th>

                                    <th
                                        rowspan="2"
                                        class="align-middle">
                                        Production
                                    </th>


                                    <th
                                        colspan="3"
                                        class="ctr-detail-group-head">
                                        Actual
                                    </th>


                                    <th
                                        colspan="3"
                                        class="ctr-detail-group-head">
                                        Standard
                                    </th>


                                    <th
                                        rowspan="2"
                                        class="
                                            align-middle
                                            text-end
                                        ">
                                        Variance
                                    </th>

                                </tr>


                                <!-- LEVEL 2 -->

                                <tr>

                                    <!-- ACTUAL -->

                                    <th class="ctr-detail-sub-head">
                                        Machine
                                    </th>

                                    <th class="ctr-detail-sub-head">
                                        Loading
                                    </th>

                                    <th class="ctr-detail-sub-head">
                                        Cycle
                                    </th>


                                    <!-- STANDARD -->

                                    <th class="ctr-detail-sub-head">
                                        Machine
                                    </th>

                                    <th class="ctr-detail-sub-head">
                                        Loading
                                    </th>

                                    <th class="ctr-detail-sub-head">
                                        Cycle
                                    </th>

                                </tr>

                            </thead>


                            <tbody
                                id="cycleDetailRows"></tbody>

                        </table>

                    </div>

                </div>


                <!-- ERROR -->

                <div
                    id="cycleDetailError"
                    class="
                        alert
                        alert-danger
                        d-none
                        mb-0
                    "></div>

            </div>


            <!-- FOOTER -->

            <div class="modal-footer">

                <button
                    type="button"
                    class="
                        btn
                        btn-secondary
                        js-cycle-modal-close
                    "
                    data-bs-dismiss="modal">
                    Tutup
                </button>

            </div>


        </div>

    </div>

</div>


<script>
    (() => {

        'use strict';


        /*
        |--------------------------------------------------------------------------
        | ELEMENT
        |--------------------------------------------------------------------------
        */

        const root =
            document;


        const modalEl =
            root.getElementById(
                'cycleDetailModal'
            );


        if (!modalEl) {

            console.error(
                '[CycleTime] ' +
                'cycleDetailModal ' +
                'tidak ditemukan.'
            );

            return;

        }


        const endpoint =
            <?= json_encode(
                site_url(
                    'reports/cycle-time/detail'
                ),
                JSON_UNESCAPED_SLASHES
            ) ?>;


        const loading =
            root.getElementById(
                'cycleDetailLoading'
            );


        const content =
            root.getElementById(
                'cycleDetailContent'
            );


        const errorBox =
            root.getElementById(
                'cycleDetailError'
            );


        const rowsEl =
            root.getElementById(
                'cycleDetailRows'
            );


        const subtitle =
            root.getElementById(
                'cycleDetailSubtitle'
            );


        const metaEl =
            root.getElementById(
                'cycleDetailMeta'
            );


        const detailProduced =
            root.getElementById(
                'detailProduced'
            );


        const detailTotalMachine =
            root.getElementById(
                'detailTotalMachine'
            );


        const detailTotalLoading =
            root.getElementById(
                'detailTotalLoading'
            );


        const detailAvgCycle =
            root.getElementById(
                'detailAvgCycle'
            );


        /*
        |--------------------------------------------------------------------------
        | BOOTSTRAP MODAL
        |--------------------------------------------------------------------------
        */

        const hasBootstrapModal =
            typeof window.bootstrap !== 'undefined' &&
            typeof window.bootstrap.Modal !== 'undefined';


        const bootstrapModal =
            hasBootstrapModal

            ?
            window.bootstrap.Modal
            .getOrCreateInstance(
                modalEl
            )

            :
            null;


        /*
        |--------------------------------------------------------------------------
        | MODAL OPEN
        |--------------------------------------------------------------------------
        */

        function openModal() {

            if (bootstrapModal) {

                bootstrapModal.show();

                return;

            }


            modalEl.classList.add(
                'show',
                'ctr-modal-fallback-open'
            );


            modalEl.style.display =
                'block';


            modalEl.removeAttribute(
                'aria-hidden'
            );


            modalEl.setAttribute(
                'aria-modal',
                'true'
            );


            document.body.classList.add(
                'modal-open'
            );

        }


        /*
        |--------------------------------------------------------------------------
        | MODAL CLOSE
        |--------------------------------------------------------------------------
        */

        function closeModal() {

            if (bootstrapModal) {

                bootstrapModal.hide();

                return;

            }


            modalEl.classList.remove(
                'show',
                'ctr-modal-fallback-open'
            );


            modalEl.style.display =
                'none';


            modalEl.setAttribute(
                'aria-hidden',
                'true'
            );


            modalEl.removeAttribute(
                'aria-modal'
            );


            document.body.classList.remove(
                'modal-open'
            );

        }


        root.querySelectorAll(
            '.js-cycle-modal-close'
        ).forEach(
            button => {

                button.addEventListener(
                    'click',
                    () => {

                        if (!bootstrapModal) {
                            closeModal();
                        }

                    }
                );

            }
        );


        modalEl.addEventListener(
            'click',
            event => {

                if (
                    !bootstrapModal &&
                    event.target === modalEl
                ) {
                    closeModal();
                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | UTILITIES
        |--------------------------------------------------------------------------
        */

        const esc =
            value => String(
                value ?? ''
            ).replace(
                /[&<>'"]/g,
                character => ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#039;',
                    '"': '&quot;',
                })[character]
            );


        const sec =
            ms => {

                if (
                    ms === null ||
                    ms === undefined ||
                    ms === ''
                ) {
                    return '—';
                }


                const value =
                    Number(ms);


                if (
                    !Number.isFinite(value)
                ) {
                    return '—';
                }


                return (
                    value / 1000
                ).toFixed(3) + 's';

            };


        const duration =
            ms => {

                if (
                    ms === null ||
                    ms === undefined ||
                    ms === ''
                ) {
                    return '—';
                }


                let value =
                    Number(ms);


                if (
                    !Number.isFinite(value)
                ) {
                    return '—';
                }


                let total =
                    Math.max(
                        0,
                        Math.round(
                            value / 1000
                        )
                    );


                const hours =
                    Math.floor(
                        total / 3600
                    );


                total %= 3600;


                const minutes =
                    Math.floor(
                        total / 60
                    );


                const seconds =
                    total % 60;


                if (hours > 0) {

                    return [
                            hours,
                            minutes,
                            seconds,
                        ]
                        .map(
                            value =>
                            String(value)
                            .padStart(
                                2,
                                '0'
                            )
                        )
                        .join(':');

                }


                return [
                        minutes,
                        seconds,
                    ]
                    .map(
                        value =>
                        String(value)
                        .padStart(
                            2,
                            '0'
                        )
                    )
                    .join(':');

            };


        const timeOnly =
            value => {

                if (!value) {
                    return '—';
                }


                const text =
                    String(value);


                if (text.length >= 19) {
                    return text.slice(
                        11,
                        19
                    );
                }


                return text;

            };


        const variance =
            ms => {

                if (
                    ms === null ||
                    ms === undefined ||
                    ms === ''
                ) {

                    return (
                        '<span ' +
                        'class="' +
                        'ctr-variance neutral' +
                        '">' +
                        '—' +
                        '</span>'
                    );

                }


                const number =
                    Number(ms);


                if (
                    !Number.isFinite(number)
                ) {

                    return (
                        '<span ' +
                        'class="' +
                        'ctr-variance neutral' +
                        '">' +
                        '—' +
                        '</span>'
                    );

                }


                const cssClass =
                    number > 0

                    ?
                    'bad'

                    :
                    (
                        number < 0 ?
                        'good' :
                        'neutral'
                    );


                const label =
                    (
                        number > 0 ?
                        '+' :
                        ''
                    ) +
                    (
                        number / 1000
                    ).toFixed(3) +
                    's';


                return (
                    '<span ' +
                    'class="' +
                    'ctr-variance ' +
                    cssClass +
                    '">' +
                    esc(label) +
                    '</span>'
                );

            };


        /*
        |--------------------------------------------------------------------------
        | STATE
        |--------------------------------------------------------------------------
        */

        function showLoading() {

            loading.classList.remove(
                'd-none'
            );


            content.classList.add(
                'd-none'
            );


            errorBox.classList.add(
                'd-none'
            );


            errorBox.textContent =
                '';


            rowsEl.innerHTML =
                '';


            metaEl.innerHTML =
                '';


            subtitle.textContent =
                'Loading...';


            detailProduced.textContent =
                '0';


            detailTotalMachine.textContent =
                '—';


            detailTotalLoading.textContent =
                '—';


            detailAvgCycle.textContent =
                '—';

        }


        function showError(
            message
        ) {

            loading.classList.add(
                'd-none'
            );


            content.classList.add(
                'd-none'
            );


            errorBox.textContent =
                message ||
                'Gagal memuat detail cycle.';


            errorBox.classList.remove(
                'd-none'
            );

        }


        function showContent() {

            loading.classList.add(
                'd-none'
            );


            errorBox.classList.add(
                'd-none'
            );


            content.classList.remove(
                'd-none'
            );

        }


        /*
        |--------------------------------------------------------------------------
        | OPEN DETAIL
        |--------------------------------------------------------------------------
        */

        async function openDetail(
            button
        ) {

            showLoading();

            openModal();


            const params =
                new URLSearchParams({

                    work_date: button.dataset.workDate ||
                        '',

                    shift_id: button.dataset.shiftId ||
                        '0',

                    machine_id: button.dataset.machineId ||
                        '0',

                    operator_employee_id: button.dataset.operatorId ||
                        '0',

                    part_id: button.dataset.partId ||
                        '0',

                    part_process_id: button.dataset.processId ||
                        '0',

                });


            const requestUrl =
                endpoint +
                '?' +
                params.toString();


            try {


                const response =
                    await fetch(
                        requestUrl, {
                            method: 'GET',

                            headers: {

                                'X-Requested-With': 'XMLHttpRequest',

                                'Accept': 'application/json',

                            },

                            credentials: 'same-origin',
                        }
                    );


                const rawResponse =
                    await response.text();


                let payload;


                try {

                    payload =
                        JSON.parse(
                            rawResponse
                        );

                } catch (
                    jsonError
                ) {

                    console.error(
                        '[CycleTime] ' +
                        'Response bukan JSON:',
                        rawResponse
                    );


                    throw new Error(
                        'Server mengembalikan ' +
                        'response tidak valid. ' +
                        'HTTP ' +
                        response.status
                    );

                }


                if (
                    !response.ok ||
                    !payload ||
                    payload.ok !== true
                ) {

                    throw new Error(
                        payload?.message ||
                        (
                            'Gagal memuat ' +
                            'detail cycle. ' +
                            'HTTP ' +
                            response.status
                        )
                    );

                }


                const data =
                    payload.data ||
                    {};


                const meta =
                    data.meta ||
                    {};


                const summary =
                    data.summary ||
                    {};


                const cycles =
                    Array.isArray(
                        data.cycles
                    ) ?
                    data.cycles :
                    [];


                /*
                 * =================================================
                 * HEADER
                 * =================================================
                 */

                subtitle.textContent =
                    `${
                    meta.part_number
                    || '-'
                }` +
                    ` · ${
                    meta.process_name
                    || '-'
                }` +
                    ` · ${
                    meta.operator_name
                    || '-'
                }`;


                metaEl.innerHTML = [

                        meta.work_date ?
                        (
                            'Date: ' +
                            meta.work_date
                        ) :
                        '',


                        (
                            meta.shift_name ||
                            meta.shift_code
                        ) ?
                        (
                            'Shift: ' +
                            (
                                meta.shift_name ||
                                meta.shift_code
                            )
                        ) :
                        '',


                        meta.machine_code ?
                        (
                            'Machine: ' +
                            meta.machine_code
                        ) :
                        '',


                        meta.operator_name ?
                        (
                            'Operator: ' +
                            meta.operator_name +
                            (
                                meta.operator_nik ?
                                (
                                    ' · ' +
                                    meta.operator_nik
                                ) :
                                ''
                            )
                        ) :
                        '',

                    ]

                    .filter(Boolean)

                    .map(
                        value =>
                        '<span ' +
                        'class="ctr-meta-pill">' +
                        esc(value) +
                        '</span>'
                    )

                    .join('');


                /*
                 * =================================================
                 * SUMMARY
                 * =================================================
                 */

                detailProduced.textContent =
                    Number(
                        summary.produced_qty ||
                        0
                    ).toLocaleString(
                        'id-ID'
                    );


                detailTotalMachine.textContent =
                    duration(
                        summary.total_machine_ms
                    );


                detailTotalLoading.textContent =
                    duration(
                        summary.total_loading_ms
                    );


                detailAvgCycle.textContent =
                    sec(
                        summary.actual_cycle_ms
                    );


                /*
                 * =================================================
                 * DETAIL ROWS
                 * =================================================
                 */

                rowsEl.innerHTML =
                    cycles
                    .map(
                        (
                            cycle,
                            index
                        ) => {


                            const loadingValid =
                                Number(
                                    cycle.loading_valid ??
                                    0
                                ) === 1;


                            /*
                             * ---------------------------------
                             * ACTUAL
                             * ---------------------------------
                             */

                            const actualMachine =
                                sec(
                                    cycle.machine_time_ms
                                );


                            const actualLoading =
                                loadingValid

                                ?
                                sec(
                                    cycle.loading_time_ms
                                )

                                :
                                '—';


                            const actualCycle =
                                loadingValid

                                ?
                                sec(
                                    cycle.cycle_time_ms
                                )

                                :
                                sec(
                                    cycle.machine_time_ms
                                );


                            /*
                             * ---------------------------------
                             * STANDARD
                             * ---------------------------------
                             */

                            const standardMachine =
                                sec(
                                    cycle.standard_machine_ms ??
                                    null
                                );


                            const standardLoading =
                                sec(
                                    cycle.standard_loading_ms ??
                                    null
                                );


                            /*
                             * Fallback:
                             * kalau standard_cycle_ms kosong
                             * tetapi machine + loading ada,
                             * hitung dari keduanya.
                             */

                            let standardCycleMs =
                                cycle.standard_cycle_ms ??
                                null;


                            if (
                                standardCycleMs === null &&
                                cycle.standard_machine_ms !== null &&
                                cycle.standard_machine_ms !== undefined &&
                                cycle.standard_loading_ms !== null &&
                                cycle.standard_loading_ms !== undefined
                            ) {

                                standardCycleMs =
                                    Number(
                                        cycle.standard_machine_ms
                                    ) +
                                    Number(
                                        cycle.standard_loading_ms
                                    );

                            }


                            const standardCycle =
                                sec(
                                    standardCycleMs
                                );


                            /*
                             * ---------------------------------
                             * VARIANCE
                             * ---------------------------------
                             */

                            const varianceHtml =
                                loadingValid

                                ?
                                variance(
                                    cycle.cycle_variance_ms
                                )

                                :
                                (
                                    '<span ' +
                                    'class="' +
                                    'ctr-variance neutral' +
                                    '">' +
                                    '—' +
                                    '</span>'
                                );


                            /*
                             * ---------------------------------
                             * ROW
                             * ---------------------------------
                             */

                            return `
                                <tr>

                                    <td>

                                        <strong>
                                            #${index + 1}
                                        </strong>

                                        <div
                                            class="text-muted"
                                            style="
                                                font-size:9px
                                            "
                                        >
                                            Seq ${
                                                esc(
                                                    cycle.sequence_no
                                                    ?? '-'
                                                )
                                            }
                                        </div>

                                    </td>


                                    <td>

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    timeOnly(
                                                        cycle.machine_stopped_at
                                                        ?? cycle.completed_at
                                                    )
                                                )
                                            }
                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    cycle.production_code
                                                    || '-'
                                                )
                                            }
                                        </span>

                                    </td>


                                    <!-- ================= -->
                                    <!-- ACTUAL            -->
                                    <!-- ================= -->


                                    <td class="text-end">

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    actualMachine
                                                )
                                            }
                                        </span>

                                    </td>


                                    <td class="text-end">

                                        ${
                                            loadingValid

                                                ? (
                                                    '<span '
                                                    + 'class="'
                                                    + 'ctr-time'
                                                    + '">'
                                                    + esc(
                                                        actualLoading
                                                    )
                                                    + '</span>'
                                                )

                                                : (
                                                    '<span '
                                                    + 'class="'
                                                    + 'ctr-first-cycle'
                                                    + '">'
                                                    + 'First cycle'
                                                    + '</span>'
                                                )
                                        }

                                    </td>


                                    <td class="text-end">

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    actualCycle
                                                )
                                            }
                                        </span>

                                    </td>


                                    <!-- ================= -->
                                    <!-- STANDARD          -->
                                    <!-- ================= -->


                                    <td class="text-end">

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    standardMachine
                                                )
                                            }
                                        </span>

                                    </td>


                                    <td class="text-end">

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    standardLoading
                                                )
                                            }
                                        </span>

                                    </td>


                                    <td class="text-end">

                                        <span
                                            class="ctr-time"
                                        >
                                            ${
                                                esc(
                                                    standardCycle
                                                )
                                            }
                                        </span>

                                    </td>


                                    <!-- ================= -->
                                    <!-- VARIANCE          -->
                                    <!-- ================= -->


                                    <td class="text-end">

                                        ${varianceHtml}

                                    </td>

                                </tr>
                            `;

                        }
                    )

                    .join('')

                    ||

                    `
                    <tr>

                        <td
                            colspan="10"
                            class="
                                text-center
                                text-muted
                                py-4
                            "
                        >
                            Tidak ada cycle.
                        </td>

                    </tr>
                `;


                showContent();


            } catch (
                exception
            ) {


                console.error(
                    '[CycleTime] ' +
                    'Detail error:',
                    exception
                );


                showError(
                    exception?.message ||
                    (
                        'Gagal memuat ' +
                        'detail cycle.'
                    )
                );

            }

        }


        /*
        |--------------------------------------------------------------------------
        | DETAIL BUTTON
        |--------------------------------------------------------------------------
        */

        const detailButtons =
            root.querySelectorAll(
                '.js-cycle-detail'
            );


        detailButtons.forEach(
            button => {

                button.addEventListener(
                    'click',
                    event => {

                        event.preventDefault();

                        openDetail(
                            button
                        );

                    }
                );

            }
        );


        console.log(
            '[CycleTime] ' +
            'Detail initialized.', {
                buttons: detailButtons.length,

                bootstrapModal: hasBootstrapModal,

                endpoint: endpoint,
            }
        );

    })();
</script>


<?= $this->endSection() ?>