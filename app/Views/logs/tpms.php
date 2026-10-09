<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?= $this->include('logs/_style') ?>

<?php
$number = static fn($v) => number_format((int) $v, 0, ',', '.');

$badgeClass = static function (?string $severity): string {
    return match ($severity) {
        'success' => 'success',
        'warning' => 'warning',
        'danger', 'critical', 'error' => 'danger',
        'info' => 'info',
        default => 'muted',
    };
};

$eventChart = [
    'labels' => array_map(static fn($row) => $row['event_type'], $eventRows),
    'data'   => array_map(static fn($row) => (int) $row['total'], $eventRows),
];

$trendChart = [
    'labels' => array_map(static fn($row) => $row['label'], $dailyTrend),
    'data'   => array_map(static fn($row) => (int) $row['value'], $dailyTrend),
];
?>

<div class="page-heading">
    <div>
        <h1>TPMS <span>Activity Logs</span></h1>
        <div class="page-subtitle"><?= $number($totalLogs) ?> TPMS log records</div>
    </div>

    <div class="action-row">
        <a class="btn btn-light border" href="<?= current_url() ?>">
            <i class="bi bi-arrow-repeat me-2"></i>Refresh
        </a>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card blue">
        <div class="stat-label">Total Logs</div>
        <div class="stat-value"><?= $number($totalLogs) ?></div>
        <div class="stat-meta text-primary">All time</div>
        <div class="stat-icon text-primary"><i class="bi bi-journal-text"></i></div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Today's Logs</div>
        <div class="stat-value text-success"><?= $number($todayLogs) ?></div>
        <div class="stat-meta text-success">Today</div>
        <div class="stat-icon text-success"><i class="bi bi-calendar-check"></i></div>
    </div>

    <div class="stat-card purple">
        <div class="stat-label">Registered Devices</div>
        <div class="stat-value"><?= $number($registeredDevices) ?></div>
        <div class="stat-meta text-primary">TPMS devices</div>
        <div class="stat-icon text-primary"><i class="bi bi-router"></i></div>
    </div>

    <div class="stat-card orange">
        <div class="stat-label">Most Active Slot (30d)</div>
        <div class="stat-value" style="font-size:24px">
            <?= $mostActiveSlot30 ? 'Slot ' . esc($mostActiveSlot30['slot_no']) : '—' ?>
        </div>
        <div class="stat-meta text-warning">
            <?= $mostActiveSlot30 ? esc($mostActiveSlot30['machine_name'] ?: '-') . ' · ' . $number($mostActiveSlot30['total']) . ' events' : 'No data' ?>
        </div>
        <div class="stat-icon text-warning"><i class="bi bi-trophy"></i></div>
    </div>
</div>

<form class="panel filter-panel mb-4" method="get">
    <div class="row g-3 align-items-end">
        <div class="col-lg-4 col-md-6">
            <label class="filter-label">Slot / Machine</label>
            <select class="form-select" name="scope">
                <?php foreach ($scopeOptions as $option): ?>
                    <option value="<?= esc($option['value']) ?>" <?= $filters['scope'] === $option['value'] ? 'selected' : '' ?>>
                        <?= esc($option['label']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-lg-3 col-md-6">
            <label class="filter-label">Event Type</label>
            <select class="form-select" name="event_type">
                <option value="">All</option>
                <?php foreach ($eventOptions as $event): ?>
                    <option value="<?= esc($event['event_type']) ?>" <?= $filters['event_type'] === $event['event_type'] ? 'selected' : '' ?>>
                        <?= esc($event['event_type']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-lg-3 col-md-6">
            <label class="filter-label">Date</label>
            <input class="form-control" type="date" name="date" value="<?= esc($filters['date'] ?? '') ?>">
        </div>

        <div class="col-lg-2 col-md-6 d-flex gap-2">
            <button class="btn btn-primary flex-fill" type="submit">Apply</button>
            <a class="btn btn-light border flex-fill" href="<?= site_url('logs/tpms') ?>">Reset</a>
        </div>
    </div>

    <div class="filter-foot">
        <div class="log-count"><?= $number($recordCount) ?> record found</div>
    </div>
</form>

<div class="log-grid">
    <div class="log-stack">
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="bi bi-pie-chart me-2 text-primary"></i>Event Distribution (7D)</h2>
            </div>
            <div class="log-chart-box">
                <div class="log-chart-wrap sm">
                    <canvas id="tpmsEventChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="bi bi-graph-up me-2 text-success"></i>Daily Trend (7D)</h2>
            </div>
            <div class="log-chart-box">
                <div class="log-chart-wrap sm">
                    <canvas id="tpmsTrendChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="bi bi-list-stars me-2 text-warning"></i>Top Slot (7D)</h2>
            </div>

            <?php if ($slotRows): ?>
                <ul class="log-mini-list">
                    <?php foreach ($slotRows as $row): ?>
                        <li>
                            <div>
                                <strong>Slot <?= esc($row['slot_no']) ?></strong>
                                <small><?= esc($row['machine_name'] ?: '-') ?></small>
                            </div>
                            <strong><?= $number($row['total']) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <div class="log-empty">No data</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel ms-3 table-card">
        <div class="panel-header">
            <h2 class="panel-title"><i class="bi bi-list-ul me-2 text-primary"></i>Activity Log (latest 50)</h2>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Slot / Machine</th>
                        <th>Event</th>
                        <th>Device</th>
                        <th>Source</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs): ?>
                        <?php foreach ($logs as $row): ?>
                            <tr>
                                <td>
                                    <?= esc(date('d M Y', strtotime($row['occurred_at']))) ?>
                                    <div class="small-muted"><?= esc(date('H:i:s', strtotime($row['occurred_at']))) ?></div>
                                </td>
                                <td>
                                    <strong><?= $row['slot_no'] ? 'Slot ' . esc($row['slot_no']) : '-' ?></strong>
                                    <div class="small-muted"><?= esc($row['machine_name'] ?: '-') ?></div>
                                </td>
                                <td>
                                    <span class="badge-soft <?= $badgeClass($row['severity'] ?? null) ?>">
                                        <?= esc($row['event_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="mono"><?= esc($row['display_mac'] ?: '-') ?></div>
                                    <div class="small-muted"><?= esc($row['ip_address'] ?: '-') ?></div>
                                </td>
                                <td>
                                    <strong><?= esc($row['source'] ?: '-') ?></strong>
                                    <div class="small-muted"><?= esc($row['actor_username'] ?: ucfirst($row['actor_type'] ?: 'system')) ?></div>
                                </td>
                                <td class="log-message"><?= esc($row['message'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-secondary py-5">No records found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.0/dist/chart.umd.min.js"></script>
<script>
    (() => {
        const eventData = <?= json_encode($eventChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const trendData = <?= json_encode($trendChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        Chart.defaults.color = '#667085';

        new Chart(document.getElementById('tpmsEventChart'), {
            type: 'doughnut',
            data: {
                labels: eventData.labels,
                datasets: [{
                    data: eventData.data,
                    backgroundColor: ['#4f46e5', '#10b981', '#f59e0b', '#ef4444', '#06b6d4', '#8b5cf6', '#94a3b8', '#f97316'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        new Chart(document.getElementById('tpmsTrendChart'), {
            type: 'line',
            data: {
                labels: trendData.labels,
                datasets: [{
                    label: 'Events',
                    data: trendData.data,
                    borderColor: '#10b981',
                    backgroundColor: '#10b981',
                    borderWidth: 3,
                    tension: .35,
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    })();
</script>

<?= $this->endSection() ?>