<?= $this->extend('layouts/app') ?>

<?= $this->section('content') ?>

<?php

$machineStatus = $machineStatus ?? [];
$todayProduction = $todayProduction ?? [];
$masterSummary = $masterSummary ?? [];

$productionTrend = $productionTrend ?? [];
$topProductionToday = $topProductionToday ?? [];

$recentMachines = $recentMachines ?? [];

$number = static fn($value): string =>
number_format(
    (int) $value,
    0,
    ',',
    '.'
);

$percent = static fn($value): string =>
number_format(
    (float) $value,
    1,
    ',',
    '.'
) . '%';

$formatDate = static function (?string $value): string {
    if (! $value) {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('d M, H:i', $timestamp)
        : '—';
};

$statusMeta = [
    'setting'=>['label'=>'Setting','class'=>'info'],
    'running' => [
        'label' => 'Run',
        'class' => 'success',
    ],

    'idle' => [
        'label' => 'Idle',
        'class' => 'warning',
    ],

    'alarm' => [
        'label' => 'Alarm',
        'class' => 'danger',
    ],

    'offline' => [
        'label' => 'Offline',
        'class' => 'muted',
    ],
];

$trendJson = json_encode(
    $productionTrend,
    JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
);

?>

<style>
    .production-dashboard {
        --dash-blue: #377cf6;
        --dash-green: #0fb981;
        --dash-orange: #f59e0b;
        --dash-red: #ef4444;
        --dash-offline: #778195;
        --dash-text: #172033;
        --dash-muted: #72819a;
        --dash-border: #e7edf5;
    }

    .production-dashboard .dashboard-hero {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 24px;
        margin-bottom: 28px;
    }

    .production-dashboard .hero-title {
        margin: 0;
        font-size: clamp(42px, 5.3vw, 68px);
        line-height: .95;
        font-weight: 900;
        letter-spacing: -.065em;
        color: #050505;
    }

    .production-dashboard .hero-title span {
        background:
            linear-gradient(90deg,
                #377cf6,
                #725cf6);

        -webkit-background-clip: text;
        background-clip: text;
        color: transparent;
    }

    .production-dashboard .hero-subtitle {
        margin: 16px 0 0;

        color: #6d7e98;

        font-size: 13px;
        font-weight: 800;

        letter-spacing: .12em;
        text-transform: uppercase;
    }

    .production-dashboard .generated-time {
        display: inline-flex;
        align-items: center;

        gap: 12px;

        min-height: 48px;

        padding: 0 18px;

        border:
            1px solid var(--dash-border);

        border-radius: 14px;

        background:
            rgba(255,
                255,
                255,
                .9);

        color: #47556d;

        font-weight: 700;

        box-shadow:
            0 8px 24px rgba(15,
                23,
                42,
                .05);

        white-space: nowrap;
    }

    /* STATUS */

    .production-dashboard .status-strip {
        display: flex;
        flex-wrap: wrap;

        gap: 10px;

        margin-bottom: 28px;
    }

    .production-dashboard .status-pill {
        display: inline-flex;
        align-items: center;

        gap: 10px;

        padding: 9px 15px;

        border:
            1px solid var(--dash-border);

        border-radius: 999px;

        background: #fff;

        color: #2d3a4f;

        font-size: 13px;
        font-weight: 800;

        box-shadow:
            0 6px 18px rgba(15,
                23,
                42,
                .05);
    }

    .production-dashboard .status-dot {
        width: 10px;
        height: 10px;

        flex: 0 0 10px;

        border-radius: 50%;
    }

    .status-dot.running {
        background: var(--dash-green);
    }

    .status-dot.idle {
        background: var(--dash-orange);
    }

    .status-dot.alarm {
        background: var(--dash-red);
    }

    .status-dot.empty {
        background: #97a5ba;
    }

    /* SUMMARY */

    .production-dashboard .summary-grid {
        display: grid;

        grid-template-columns:
            repeat(5,
                minmax(0, 1fr));

        gap: 18px;

        margin-bottom: 34px;
    }

    .production-dashboard .summary-card {
        position: relative;

        min-height: 212px;

        overflow: hidden;

        padding: 24px;

        border:
            1px solid rgba(226,
                232,
                240,
                .9);

        border-radius: 20px;

        background: #fff;

        box-shadow:
            0 12px 30px rgba(15,
                23,
                42,
                .07);
    }

    .production-dashboard .summary-card::after {
        content: '';

        position: absolute;
        inset: 0;

        pointer-events: none;

        opacity: .8;
    }

    .summary-card.blue::after {
        background:
            radial-gradient(circle at 92% 85%,
                rgba(96, 165, 250, .48),
                transparent 47%);
    }

    .summary-card.green::after {
        background:
            radial-gradient(circle at 92% 85%,
                rgba(52, 211, 153, .48),
                transparent 47%);
    }

    .summary-card.purple::after {
        background:
            radial-gradient(circle at 92% 85%,
                rgba(129, 140, 248, .43),
                transparent 47%);
    }

    .summary-card.violet::after {
        background:
            radial-gradient(circle at 92% 85%,
                rgba(192, 132, 252, .40),
                transparent 47%);
    }

    .summary-card.yellow::after {
        background:
            radial-gradient(circle at 92% 85%,
                rgba(251, 191, 36, .44),
                transparent 47%);
    }

    .production-dashboard .summary-card>* {
        position: relative;
        z-index: 1;
    }

    .production-dashboard .summary-icon {
        position: absolute;

        z-index: 2;

        right: 24px;
        top: 52px;

        width: 48px;
        height: 48px;

        display: grid;
        place-items: center;

        border-radius: 15px;

        background:
            rgba(255,
                255,
                255,
                .50);

        font-size: 22px;
    }

    .production-dashboard .summary-label {
        color: #62718a;

        font-size: 13px;
        font-weight: 800;
    }

    .production-dashboard .summary-value {
        margin: 12px 0 5px;

        color: #111827;

        font-size: 38px;
        line-height: 1;

        font-weight: 900;

        letter-spacing: -.04em;
    }

    .production-dashboard .summary-sub {
        min-height: 20px;

        color: #5f6e86;

        font-size: 12px;
        font-weight: 700;
    }

    .summary-sub.primary {
        color: #2563eb !important;
    }

    .summary-sub.success {
        color: #059669 !important;
    }

    .summary-sub.purple {
        color: #6d4aff !important;
    }

    .summary-sub.orange {
        color: #d97706 !important;
    }

    .production-dashboard .mini-status {
        display: grid;

        grid-template-columns:
            1fr 1fr;

        gap: 5px 12px;

        margin-top: 17px;

        font-size: 12px;

        color: #6f7d93;
    }

    .production-dashboard .mini-status strong {
        font-weight: 900;
    }

    .production-dashboard .text-run {
        color: #0aa371;
    }

    .production-dashboard .text-idle {
        color: #df8b00;
    }

    .production-dashboard .text-alarm {
        color: #e53935;
    }

    .production-dashboard .text-offline {
        color: #69778f;
    }

    /* TODAY PROGRESS */

    .production-dashboard .summary-progress {
        margin-top: 18px;
    }

    .production-dashboard .summary-progress-head,

    .production-dashboard .summary-progress-foot {
        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 12px;

        color: #63718a;

        font-size: 12px;
        font-weight: 700;
    }

    .production-dashboard .summary-progress-head strong {
        color: #101828;
    }

    .production-dashboard .summary-progress .progress {
        height: 6px;

        margin: 7px 0;

        border-radius: 999px;

        background:
            rgba(255,
                255,
                255,
                .72);
    }

    /* SECTION */

    .production-dashboard .section-divider {
        display: flex;
        align-items: center;

        gap: 20px;

        margin:
            4px 0 24px;
    }

    .production-dashboard .section-divider::before,

    .production-dashboard .section-divider::after {
        content: '';

        flex: 1;

        height: 1px;

        background: #dfe7f0;
    }

    .production-dashboard .section-divider h2 {
        margin: 0;

        color: #243146;

        font-size: 22px;
        font-weight: 900;

        letter-spacing: -.035em;

        white-space: nowrap;
    }

    .production-dashboard .section-divider h2 span {
        color: var(--dash-blue);
    }

    /* PERFORMANCE */

    .production-dashboard .performance-grid {
        display: grid;

        grid-template-columns:
            minmax(0, 2.05fr) minmax(300px, 1fr);

        gap: 22px;

        margin-bottom: 22px;
    }

    .production-dashboard .dashboard-panel {
        overflow: hidden;

        border:
            1px solid var(--dash-border);

        border-radius: 20px;

        background:
            rgba(255,
                255,
                255,
                .96);

        box-shadow:
            0 12px 30px rgba(15,
                23,
                42,
                .07);
    }

    .production-dashboard .dashboard-panel-header {
        min-height: 70px;

        display: flex;
        align-items: center;
        justify-content: space-between;

        gap: 16px;

        padding: 18px 22px;

        border-bottom:
            1px solid var(--dash-border);
    }

    .production-dashboard .dashboard-panel-title {
        display: flex;
        align-items: center;

        gap: 10px;

        margin: 0;

        color: #1f2a3d;

        font-size: 17px;
        font-weight: 900;
    }

    .production-dashboard .dashboard-panel-title i {
        color: var(--dash-blue);
    }

    /* CHART PERIOD */

    .production-dashboard .period-switcher {
        display: flex;
        align-items: center;

        gap: 4px;
    }

    .production-dashboard .period-btn {
        border: 0;

        border-radius: 10px;

        padding: 8px 13px;

        background: transparent;

        color: #64748b;

        font-size: 12px;
        font-weight: 900;
    }

    .production-dashboard .period-btn.active {
        background: #dceafe;

        color: #245ad8;
    }

    .production-dashboard .chart-wrap {
        position: relative;

        min-height: 375px;

        padding:
            22px 22px 18px;
    }

    /* MACHINE STATUS */

    .production-dashboard .status-chart-wrap {
        position: relative;

        width:
            min(260px,
                85%);

        margin:
            24px auto 12px;

        aspect-ratio: 1;
    }

    .production-dashboard .status-grid {
        display: grid;

        grid-template-columns:
            1fr 1fr;

        gap: 12px;

        padding:
            0 22px 22px;
    }

    .production-dashboard .status-box {
        display: grid;
        place-items: center;

        min-height: 92px;

        border:
            1px solid var(--dash-border);

        border-radius: 14px;

        background: #fff;

        text-align: center;
    }

    .production-dashboard .status-box strong {
        display: block;

        font-size: 24px;
        line-height: 1;

        font-weight: 900;
    }

    .production-dashboard .status-box span {
        display: block;

        margin-top: 8px;

        color: #65748c;

        font-size: 11px;
        font-weight: 900;

        text-transform: uppercase;
    }

    /* BOTTOM CARDS */

    .production-dashboard .two-panel-grid {
        display: grid;

        grid-template-columns:
            1fr 1fr;

        gap: 22px;

        margin-bottom: 22px;
    }

    .production-dashboard .panel-link {
        color: #2563eb;

        font-size: 12px;
        font-weight: 800;

        text-decoration: none;
    }

    .production-dashboard .rank-list {
        padding: 0;
        margin: 0;

        list-style: none;
    }

    .production-dashboard .rank-item {
        display: grid;

        grid-template-columns:
            minmax(0, 1fr) auto;

        gap: 14px;

        padding: 16px 20px;

        border-bottom:
            1px solid #edf1f6;
    }

    .production-dashboard .rank-item:last-child {
        border-bottom: 0;
    }

    .production-dashboard .rank-main {
        min-width: 0;
    }

    .production-dashboard .rank-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;

        gap: 8px;

        margin-bottom: 4px;

        color: #273449;

        font-size: 13px;
        font-weight: 900;
    }

    .production-dashboard .rank-subtitle {
        color: #8290a6;

        font-size: 11px;
        font-weight: 700;
    }

    .production-dashboard .rank-value {
        align-self: center;

        text-align: right;

        color: #172033;

        font-size: 18px;
        font-weight: 900;
    }

    .production-dashboard .rank-value small {
        display: block;

        margin-top: 3px;

        color: #8a98ad;

        font-size: 10px;
        font-weight: 700;
    }

    .production-dashboard .tiny-progress {
        height: 5px;

        margin-top: 10px;

        border-radius: 999px;

        background: #edf2f7;

        overflow: hidden;
    }

    .production-dashboard .tiny-progress>span {
        display: block;

        height: 100%;

        border-radius: inherit;

        background:
            linear-gradient(90deg,
                #3b82f6,
                #6366f1);
    }

    /* EMPTY */

    .production-dashboard .empty-state {
        min-height: 220px;

        display: grid;
        place-items: center;

        padding: 30px;

        text-align: center;

        color: #91a0b5;
    }

    .production-dashboard .empty-state i {
        display: block;

        margin-bottom: 12px;

        font-size: 38px;
    }

    /* TABLE */

    .production-dashboard .recent-panel {
        margin-bottom: 10px;
    }

    .production-dashboard .recent-panel .table-responsive {
        overflow-x: auto;
    }

    .production-dashboard .recent-table {
        margin: 0;

        min-width: 850px;
    }

    .production-dashboard .recent-table> :not(caption)>*>* {
        padding: 15px 20px;

        vertical-align: middle;

        border-bottom-color: #edf1f6;
    }

    .production-dashboard .recent-table thead th {
        background: #fbfcfe;

        color: #66758d;

        font-size: 11px;
        font-weight: 900;

        text-transform: uppercase;

        letter-spacing: .02em;
    }

    .production-dashboard .machine-cell {
        display: flex;
        align-items: center;

        gap: 12px;
    }

    .production-dashboard .machine-icon {
        width: 42px;
        height: 42px;

        display: grid;
        place-items: center;

        flex: 0 0 42px;

        border-radius: 12px;

        background: #f4f7fb;

        color: #8090a7;
    }

    .production-dashboard .machine-cell strong {
        display: block;

        color: #273449;

        font-size: 13px;
    }

    .production-dashboard .machine-cell small {
        display: block;

        margin-top: 2px;

        color: #91a0b5;

        font-size: 11px;
        font-weight: 700;
    }

    .production-dashboard .part-chip {
        display: inline-flex;
        align-items: center;

        gap: 5px;

        max-width: 260px;

        padding: 5px 8px;

        border-radius: 7px;

        background: #e9edff;

        color: #4c4dcc;

        font-size: 11px;
        font-weight: 800;
    }

    .production-dashboard .part-chip span {
        overflow: hidden;

        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* RESPONSIVE */

    @media (max-width: 1399.98px) {

        .production-dashboard .summary-grid {
            grid-template-columns:
                repeat(3,
                    minmax(0, 1fr));
        }

    }

    @media (max-width: 1199.98px) {

        .production-dashboard .performance-grid {
            grid-template-columns: 1fr;
        }

        .production-dashboard .status-chart-wrap {
            width: 240px;
        }

    }

    @media (max-width: 991.98px) {

        .production-dashboard .summary-grid {
            grid-template-columns:
                repeat(2,
                    minmax(0, 1fr));
        }

        .production-dashboard .two-panel-grid {
            grid-template-columns: 1fr;
        }

    }

    @media (max-width: 767.98px) {

        .production-dashboard .dashboard-hero {
            align-items: flex-start;
            flex-direction: column;
        }

        .production-dashboard .hero-title {
            font-size: 45px;
        }

        .production-dashboard .summary-grid {
            grid-template-columns: 1fr;
        }

        .production-dashboard .summary-card {
            min-height: 190px;
        }

        .production-dashboard .chart-wrap {
            min-height: 320px;

            padding-inline: 12px;
        }

        .production-dashboard .dashboard-panel-header {
            align-items: flex-start;
            flex-direction: column;
        }

    }
</style>

<div class="production-dashboard">

    <!-- HEADER -->

    <header class="dashboard-hero">

        <div>

            <h1 class="hero-title">
                Production
                <span>Monitor</span>
            </h1>

            <p class="hero-subtitle">
                Real-time manufacturing intelligence
            </p>

        </div>

        <div class="generated-time">

            <i class="bi bi-clock"></i>

            <span>
                <?= esc(
                    $generatedAt
                        ?? date('d M Y, H:i')
                ) ?>
            </span>

            <i
                class="bi bi-arrow-repeat text-primary">
            </i>

        </div>

    </header>


    <!-- STATUS -->

    <div class="status-strip">

        <span class="status-pill">

            <span
                class="status-dot running">
            </span>

            <?= $number(
                $machineStatus['running']
                    ?? 0
            ) ?>

            Running

        </span>

        <span class="status-pill">

            <span
                class="status-dot idle">
            </span>

            <?= $number(
                $machineStatus['idle']
                    ?? 0
            ) ?>

            Idle

        </span>

        <span class="status-pill">

            <span
                class="status-dot alarm">
            </span>

            <?= $number(
                $machineStatus['alarm']
                    ?? 0
            ) ?>

            Alarm

        </span>

        <span class="status-pill">

            <span
                class="status-dot empty">
            </span>

            <?= $number(
                $machineStatus['empty']
                    ?? 0
            ) ?>

            Empty Slots

        </span>

    </div>


    <!-- SUMMARY -->

    <section class="summary-grid">

        <!-- SLOT -->

        <article
            class="summary-card blue">

            <div class="summary-label">
                Total Slots
            </div>

            <div class="summary-value">

                <?= $number(
                    $machineStatus['total_slots']
                        ?? 0
                ) ?>

            </div>

            <div
                class="summary-sub primary">

                <?= $number(
                    $machineStatus['assigned']
                        ?? 0
                ) ?>

                machine assigned

            </div>

            <div class="mini-status">

                <span>

                    <strong class="text-run">

                        <?= $number(
                            $machineStatus['running']
                                ?? 0
                        ) ?>

                    </strong>

                    Run

                </span>

                <span>

                    <strong class="text-idle">

                        <?= $number(
                            $machineStatus['idle']
                                ?? 0
                        ) ?>

                    </strong>

                    Idle

                </span>

                <span>

                    <strong class="text-alarm">

                        <?= $number(
                            $machineStatus['alarm']
                                ?? 0
                        ) ?>

                    </strong>

                    Alarm

                </span>

                <span>

                    <strong
                        class="text-offline">

                        <?= $number(
                            $machineStatus['offline']
                                ?? 0
                        ) ?>

                    </strong>

                    Offline

                </span>

            </div>

            <span
                class="summary-icon text-primary">

                <i class="bi bi-buildings"></i>

            </span>

        </article>


        <!-- PRODUCTION -->

        <article
            class="summary-card green">

            <div class="summary-label">
                Today's Good Production
            </div>

            <div class="summary-value">

                <?= $number(
                    $todayProduction['actual']
                        ?? 0
                ) ?>

            </div>

            <div
                class="summary-sub success">

                <?= $number(
                    $todayProduction['active_parts']
                        ?? 0
                ) ?>

                active parts

            </div>

            <div class="summary-progress">

                <div
                    class="summary-progress-head">

                    <span>
                        Progress
                    </span>

                    <strong>

                        <?= $percent(
                            $todayProduction['progress']
                                ?? 0
                        ) ?>

                    </strong>

                </div>

                <div class="progress">

                    <div
                        class="progress-bar bg-success"
                        style="
                            width:
                            <?= min(
                                100,
                                max(
                                    0,
                                    (float) (
                                        $todayProduction['progress']
                                        ?? 0
                                    )
                                )
                            ) ?>%
                        ">
                    </div>

                </div>

                <div
                    class="summary-progress-foot">

                    <span>
                        Good: <?= $number($todayProduction['good'] ?? 0) ?>
                    </span>

                    <span>
                        Gross: <?= $number($todayProduction['gross'] ?? 0) ?>
                    </span>

                    <span>
                        Plan: <?= $number($todayProduction['target'] ?? 0) ?>
                    </span>

                    <span>
                        NC: <?= $percent($todayProduction['nc_rate'] ?? 0) ?>
                    </span>

                </div>

            </div>

            <span
                class="summary-icon text-success">

                <i
                    class="bi bi-bar-chart-line">
                </i>

            </span>

        </article>


        <!-- CUSTOMERS -->

        <article
            class="summary-card violet">

            <div class="summary-label">
                Customers
            </div>

            <div class="summary-value">

                <?= $number(
                    $masterSummary['customers']
                        ?? 0
                ) ?>

            </div>

            <div
                class="summary-sub purple">

                <?= $number(
                    $masterSummary['parts']
                        ?? 0
                ) ?>

                Parts

            </div>

            <div
                class="mini-status mt-4">

                <span>
                    Active customer
                </span>

                <span class="text-end">

                    <strong
                        class="text-primary">

                        <?= $number(
                            $masterSummary['active_customers']
                                ?? 0
                        ) ?>

                    </strong>

                </span>

            </div>

            <span
                class="summary-icon"
                style="color:#9333ea">

                <i class="bi bi-buildings"></i>

            </span>

        </article>


        <!-- MATERIAL -->

        <article
            class="summary-card yellow">

            <div class="summary-label">
                Materials
            </div>

            <div class="summary-value">

                <?= $number(
                    $masterSummary['materials']
                        ?? 0
                ) ?>

            </div>

            <div
                class="summary-sub orange">

                Active master data

            </div>

            <span
                class="summary-icon"
                style="color:#ea8400">

                <i class="bi bi-box-seam"></i>

            </span>

        </article>

    </section>


    <!-- PERFORMANCE TITLE -->

    <div class="section-divider">

        <h2>

            Performance
            <span>Overview</span>

        </h2>

    </div>


    <!-- PERFORMANCE -->

    <section class="performance-grid">

        <!-- LINE CHART -->

        <article class="dashboard-panel">

            <div
                class="dashboard-panel-header">

                <h3
                    class="dashboard-panel-title">

                    <i class="bi bi-graph-up"></i>

                    Production Trends

                </h3>

                <div
                    class="period-switcher">

                    <button
                        type="button"
                        class="period-btn active"
                        data-days="7">

                        7D

                    </button>

                    <button
                        type="button"
                        class="period-btn"
                        data-days="30">

                        30D

                    </button>

                    <button
                        type="button"
                        class="period-btn"
                        data-days="90">

                        90D

                    </button>

                </div>

            </div>

            <div class="chart-wrap">

                <canvas
                    id="productionTrendChart">
                </canvas>

            </div>

        </article>


        <!-- STATUS DONUT -->

        <article class="dashboard-panel">

            <div
                class="dashboard-panel-header">

                <h3
                    class="dashboard-panel-title">

                    <i
                        class="bi bi-speedometer2">
                    </i>

                    Machine Status

                </h3>

            </div>

            <div class="status-chart-wrap">

                <canvas
                    id="machineStatusChart">
                </canvas>

            </div>

            <div class="status-grid">

                <div class="status-box">

                    <div>

                        <strong
                            class="text-run">

                            <?= $number(
                                $machineStatus['running']
                                    ?? 0
                            ) ?>

                        </strong>

                        <span>
                            Running
                        </span>

                    </div>

                </div>

                <div class="status-box">

                    <div>

                        <strong
                            class="text-idle">

                            <?= $number(
                                $machineStatus['idle']
                                    ?? 0
                            ) ?>

                        </strong>

                        <span>
                            Idle
                        </span>

                    </div>

                </div>

                <div class="status-box">

                    <div>

                        <strong
                            class="text-alarm">

                            <?= $number(
                                $machineStatus['alarm']
                                    ?? 0
                            ) ?>

                        </strong>

                        <span>
                            Alarm
                        </span>

                    </div>

                </div>

                <div class="status-box">

                    <div>

                        <strong
                            class="text-offline">

                            <?= $number(
                                $machineStatus['offline']
                                    ?? 0
                            ) ?>

                        </strong>

                        <span>
                            Offline
                        </span>

                    </div>

                </div>

            </div>

        </article>

    </section>


    <!-- TOP PRODUCTION / ACTIVE RACKTOOLS -->

    <section class="two-panel-grid">

        <!-- TOP PRODUCTION -->

        <article class="dashboard-panel">

            <div
                class="dashboard-panel-header">

                <h3
                    class="dashboard-panel-title">

                    <i class="bi bi-trophy"></i>

                    Top Production Today

                </h3>

                <a
                    class="panel-link"
                    href="<?= esc(
                                site_url('production'),
                                'attr'
                            ) ?>">

                    View All

                    <i
                        class="bi bi-arrow-right">
                    </i>

                </a>

            </div>

            <?php if ($topProductionToday): ?>

                <ol class="rank-list">

                    <?php foreach (
                        $topProductionToday
                        as $row
                    ): ?>

                        <?php

                        $actual =
                            (int) (
                                $row['actual_qty']
                                ?? 0
                            );

                        $target =
                            (int) (
                                $row['target_qty']
                                ?? 0
                            );

                        $progress =
                            $target > 0
                            ? min(
                                100,
                                (
                                    $actual
                                    / $target
                                ) * 100
                            )
                            : 0;

                        ?>

                        <li class="rank-item">

                            <div class="rank-main">

                                <div
                                    class="rank-title">

                                    <span>

                                        Slot
                                        <?= esc(
                                            $row['slot_no']
                                        ) ?>

                                        ·

                                        <?= esc(
                                            $row['machine_name']
                                        ) ?>

                                    </span>

                                    <span
                                        class="part-chip">

                                        <i
                                            class="bi bi-box">
                                        </i>

                                        <span>

                                            <?= esc(
                                                $row['part_number']
                                            ) ?>

                                        </span>

                                    </span>

                                </div>

                                <div
                                    class="rank-subtitle">

                                    <?= esc(
                                        $row['part_name']
                                    ) ?>

                                    ·

                                    <?= esc(
                                        $row['customer_name']
                                    ) ?>

                                </div>

                                <div
                                    class="tiny-progress">

                                    <span
                                        style="
                                            width:
                                            <?= $progress ?>%
                                        ">
                                    </span>

                                </div>

                            </div>

                            <div class="rank-value">

                                <?= $number(
                                    $actual
                                ) ?>

                                <small>

                                    of

                                    <?= $number(
                                        $target
                                    ) ?>

                                </small>

                            </div>

                        </li>

                    <?php endforeach; ?>

                </ol>

            <?php else: ?>

                <div class="empty-state">

                    <div>

                        <i
                            class="bi bi-bar-chart">
                        </i>

                        No production data yet

                    </div>

                </div>

            <?php endif; ?>

        </article>


        

    </section>


    <!-- RECENT MACHINES -->

    <section
        class="
            dashboard-panel
            recent-panel
        ">

        <div
            class="dashboard-panel-header">

            <h3
                class="dashboard-panel-title">

                <i class="bi bi-list-ul"></i>

                Recent Machines

            </h3>

            <a
                class="panel-link"
                href="<?= esc(
                            site_url(
                                'master-data/machines'
                            ),
                            'attr'
                        ) ?>">

                View All

                <i
                    class="bi bi-arrow-right">
                </i>

            </a>

        </div>

        <div class="table-responsive">

            <table
                class="
                    table
                    recent-table
                    align-middle
                ">

                <thead>

                    <tr>

                        <th>
                            Slot / Machine
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Active Part
                        </th>

                        

                        <th>
                            Updated
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if ($recentMachines): ?>

                        <?php foreach (
                            $recentMachines
                            as $row
                        ): ?>

                            <?php

                            $status =
                                $row['dashboard_status']
                                ?? 'offline';

                            $meta =
                                $statusMeta[$status]
                                ?? $statusMeta['offline'];

                            $updated =
                                $row['last_seen_at']
                                ?: (
                                    $row['machine_updated_at']
                                    ?: $row['slot_updated_at']
                                );

                            ?>

                            <tr>

                                <td>

                                    <div
                                        class="machine-cell">

                                        <span
                                            class="machine-icon">

                                            <i
                                                class="
                                                bi
                                                bi-buildings
                                            ">
                                            </i>

                                        </span>

                                        <span>

                                            <strong>

                                                Slot
                                                <?= esc(
                                                    $row['slot_no']
                                                ) ?>

                                                ·

                                                <?= esc(
                                                    $row['machine_name']
                                                ) ?>

                                            </strong>

                                            <small>

                                                <?= esc(
                                                    $row['machine_code']
                                                        ?: '—'
                                                ) ?>

                                            </small>

                                        </span>

                                    </div>

                                </td>

                                <td>

                                    <span
                                        class="
                                        badge-soft
                                        <?= esc(
                                            $meta['class'],
                                            'attr'
                                        ) ?>
                                    ">

                                        <?= esc(
                                            $meta['label']
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if (
                                        ! empty($row['part_number'])
                                    ): ?>

                                        <span
                                            class="part-chip">

                                            <i
                                                class="bi bi-box">
                                            </i>

                                            <span>

                                                <?= esc(
                                                    $row['part_number']
                                                ) ?>

                                                ·

                                                <?= esc(
                                                    $row['part_name']
                                                ) ?>

                                            </span>

                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="text-secondary">

                                            —

                                        </span>

                                    <?php endif; ?>

                                </td>

                                

                                <td
                                    class="
                                    text-secondary
                                    small
                                ">

                                    <?= esc(
                                        $formatDate(
                                            $updated
                                        )
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>

                            <td
                                colspan="5"
                                class="
                                text-center
                                text-secondary
                                py-5
                            ">

                                Belum ada data mesin.

                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>


<script
    src="
        https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js
    ">
</script>

<script>
    (() => {

        const fullTrend =
            <?= $trendJson ?: '[]' ?>;

        const productionCanvas =
            document.getElementById(
                'productionTrendChart'
            );

        const machineCanvas =
            document.getElementById(
                'machineStatusChart'
            );

        if (
            !window.Chart ||
            !productionCanvas ||
            !machineCanvas
        ) {
            return;
        }


        /*
         * CHART DEFAULT
         */

        Chart.defaults.font.family =
            'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';

        Chart.defaults.color =
            '#66758d';


        /*
         * PRODUCTION TREND
         */

        const trendChart =
            new Chart(
                productionCanvas, {
                    type: 'line',

                    data: {
                        labels: [],

                        datasets: [{
                                label: 'Production',

                                data: [],

                                borderColor: '#377cf6',

                                backgroundColor: '#377cf6',

                                borderWidth: 3,

                                pointRadius: 3,

                                pointHoverRadius: 5,

                                tension: .32,
                            },

                            {
                                label: 'Plan',

                                data: [],

                                borderColor: '#f59e0b',

                                backgroundColor: '#f59e0b',

                                borderWidth: 2,

                                borderDash: [7, 6],

                                pointRadius: 0,

                                pointHoverRadius: 4,

                                tension: .25,
                            }
                        ],
                    },

                    options: {

                        responsive: true,

                        maintainAspectRatio: false,

                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },

                        plugins: {

                            legend: {

                                position: 'top',

                                align: 'end',

                                labels: {

                                    usePointStyle: true,

                                    boxWidth: 8,

                                    boxHeight: 8,

                                    padding: 18,

                                    font: {
                                        weight: 700,
                                    },
                                },
                            },

                            tooltip: {
                                padding: 12,
                            },
                        },

                        scales: {

                            x: {

                                grid: {
                                    display: false,
                                },

                                border: {
                                    display: false,
                                },

                                ticks: {
                                    maxRotation: 0,
                                    autoSkip: true,
                                    maxTicksLimit: 12,
                                },
                            },

                            y: {

                                beginAtZero: true,

                                border: {
                                    display: false,
                                },

                                grid: {
                                    color: '#edf1f6',
                                },

                                ticks: {
                                    precision: 0,
                                },
                            },
                        },
                    },
                }
            );


        /*
         * PERIOD SWITCH
         */

        const applyTrendPeriod =
            (days) => {

                const rows =
                    fullTrend.slice(
                        -days
                    );

                trendChart.data.labels =
                    rows.map(
                        row =>
                        row.label
                    );

                trendChart
                    .data
                    .datasets[0]
                    .data =
                    rows.map(
                        row =>
                        Number(
                            row.actual ||
                            0
                        )
                    );

                trendChart
                    .data
                    .datasets[1]
                    .data =
                    rows.map(
                        row =>
                        Number(
                            row.target ||
                            0
                        )
                    );

                trendChart.update();
            };


        document
            .querySelectorAll(
                '.period-btn'
            )
            .forEach(
                button => {

                    button
                        .addEventListener(
                            'click',
                            () => {

                                document
                                    .querySelectorAll(
                                        '.period-btn'
                                    )
                                    .forEach(
                                        item =>
                                        item
                                        .classList
                                        .remove(
                                            'active'
                                        )
                                    );

                                button
                                    .classList
                                    .add(
                                        'active'
                                    );

                                applyTrendPeriod(
                                    Number(
                                        button
                                        .dataset
                                        .days ||
                                        7
                                    )
                                );
                            }
                        );
                }
            );


        applyTrendPeriod(7);


        /*
         * DONUT CENTER TEXT
         */

        const centerTextPlugin = {

            id: 'centerText',

            afterDraw(chart) {

                if (
                    chart.config.type !==
                    'doughnut'
                ) {
                    return;
                }

                const {
                    ctx,
                    chartArea,
                } = chart;

                const total =
                    chart
                    .data
                    .datasets[0]
                    .data
                    .reduce(
                        (
                            sum,
                            value
                        ) =>
                        sum +
                        Number(
                            value ||
                            0
                        ),
                        0
                    );

                const x =
                    (
                        chartArea.left +
                        chartArea.right
                    ) /
                    2;

                const y =
                    (
                        chartArea.top +
                        chartArea.bottom
                    ) /
                    2;

                ctx.save();

                ctx.textAlign =
                    'center';

                ctx.textBaseline =
                    'middle';

                ctx.fillStyle =
                    '#172033';

                ctx.font =
                    '900 30px Inter, sans-serif';

                ctx.fillText(
                    total.toLocaleString(
                        'id-ID'
                    ),
                    x,
                    y - 8
                );

                ctx.fillStyle =
                    '#93a0b4';

                ctx.font =
                    '700 12px Inter, sans-serif';

                ctx.fillText(
                    'Total',
                    x,
                    y + 18
                );

                ctx.restore();
            },
        };


        /*
         * MACHINE STATUS
         */

        new Chart(
            machineCanvas, {
                type: 'doughnut',

                data: {

                    labels: [
                        'Running',
                        'Idle',
                        'Alarm',
                        'Offline',
                        'Setting',
                    ],

                    datasets: [{
                        data: [

                            <?= (int) (
                                $machineStatus['running']
                                ?? 0
                            ) ?>,

                            <?= (int) (
                                $machineStatus['idle']
                                ?? 0
                            ) ?>,

                            <?= (int) (
                                $machineStatus['alarm']
                                ?? 0
                            ) ?>,

                            <?= (int) (
                                $machineStatus['offline']
                                ?? 0
                            ) ?>,
                            <?= (int)($machineStatus['setting']??0) ?>

                        ],

                        backgroundColor: [
                            '#10b981',
                            '#f59e0b',
                            '#ef4444',
                            '#778195',
                            '#0284c7',
                        ],

                        borderColor: '#ffffff',

                        borderWidth: 4,

                        hoverOffset: 4,
                    }],
                },

                options: {

                    responsive: true,

                    maintainAspectRatio: false,

                    cutout: '68%',

                    plugins: {

                        legend: {
                            display: false,
                        },

                        tooltip: {
                            padding: 10,
                        },
                    },
                },

                plugins: [
                    centerTextPlugin,
                ],
            }
        );

    })();
</script>

<?= $this->endSection() ?>