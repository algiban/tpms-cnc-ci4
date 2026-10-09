<?= $this->extend('layouts/app') ?>
<?= $this->section('content') ?>
<?= $this->include('logs/_style') ?>

<?php
$number = static fn($v) => number_format((int) $v, 0, ',', '.');

$badgeClass = static function (?string $event): string {
    return match ($event) {
        'lifetime_warning' => 'warning',
        'lifetime_critical' => 'danger',
        'lifetime_reset' => 'info',
        'side_change' => 'info',
        'created', 'updated' => 'purple',
        default => 'muted',
    };
};

$eventChart = [
    'labels' => array_map(static fn($row) => $row['event_type'], $eventRows),
    'data'   => array_map(static fn($row) => (int) $row['total'], $eventRows),
];

$topToolChart = [
    'labels' => array_map(static fn($row) => $row['code'] ?: '-', $topToolRows),
    'data'   => array_map(static fn($row) => (int) $row['total'], $topToolRows),
];
?>

<div class="page-heading">
    <div>
        <h1>Tools <span>Activity Logs</span></h1>
        <div class="page-subtitle"><?= $number($totalLogs) ?> tools log records</div>
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
        <div class="stat-meta text-primary">Recorded events</div>
        <div class="stat-icon text-primary"><i class="bi bi-tools"></i></div>
    </div>

    <div class="stat-card green">
        <div class="stat-label">Logged Tools</div>
        <div class="stat-value text-success"><?= $number($loggedTools) ?></div>
        <div class="stat-meta text-success">Distinct tool in logs</div>
        <div class="stat-icon text-success"><i class="bi bi-check-circle"></i></div>
    </div>

    <div class="stat-card orange">
        <div class="stat-label">Warning / Critical</div>
        <div class="stat-value text-warning"><?= $number($warningCritical) ?></div>
        <div class="stat-meta text-warning">Threshold events</div>
        <div class="stat-icon text-warning"><i class="bi bi-exclamation-triangle"></i></div>
    </div>

    <div class="stat-card purple">
        <div class="stat-label">Reset (30d)</div>
        <div class="stat-value"><?= $number($reset30d) ?></div>
        <div class="stat-meta text-primary">Lifetime reset</div>
        <div class="stat-icon text-primary"><i class="bi bi-arrow-repeat"></i></div>
    </div>

    <div class="stat-card blue">
        <div class="stat-label">Side Change (30d)</div>
        <div class="stat-value text-primary"><?= $number($sideChange30d ?? 0) ?></div>
        <div class="stat-meta text-primary">Side only · not tool replacement</div>
        <div class="stat-icon text-primary"><i class="bi bi-arrow-left-right"></i></div>
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
            <label class="filter-label">Tool</label>
            <select class="form-select" name="tool_id">
                <option value="0">All</option>
                <?php foreach ($toolOptions as $tool): ?>
                    <option value="<?= esc($tool['id']) ?>" <?= $filters['tool_id'] === (int) $tool['id'] ? 'selected' : '' ?>>
                        <?= esc($tool['code']) ?> - <?= esc($tool['name']) ?>
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
            <a class="btn btn-light border flex-fill" href="<?= site_url('logs/tools') ?>">Reset</a>
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
                <h2 class="panel-title"><i class="bi bi-pie-chart me-2 text-success"></i>Event Distribution (7D)</h2>
            </div>
            <div class="log-chart-box">
                <div class="log-chart-wrap sm">
                    <canvas id="toolEventChart"></canvas>
                </div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title"><i class="bi bi-bar-chart me-2 text-primary"></i>Top Tools (7D)</h2>
            </div>
            <div class="log-chart-box">
                <div class="log-chart-wrap sm">
                    <canvas id="topToolChart"></canvas>
                </div>
            </div>
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
                        <th>Tool</th>
                        <th>Part · Material</th>
                        <th>Event</th>
                        <th>Lifetime</th>
                        <th>Qty</th>
                        <th>Remarks</th>
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
                                    <span class="code-chip"><?= esc($row['tool_code'] ?: $row['tool_code_name'] ?: '-') ?></span>
                                    <div class="small-muted"><?= esc($row['tool_name'] ?: '-') ?></div>
                                </td>
                                <td>
                                    <strong><?= esc($row['part_number'] ?: '-') ?></strong>
                                    <div class="small-muted"><?= esc($row['material_name'] ?: '-') ?></div>
                                </td>
                                <td>
                                    <span class="badge-soft <?= $badgeClass($row['event_type'] ?? null) ?>">
                                        <?= esc($row['event_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['previous_lifetime'] !== null || $row['new_lifetime'] !== null): ?>
                                        <div class="log-path">
                                            <strong><?= $number($row['previous_lifetime'] ?? 0) ?></strong>
                                            <span class="arrow">→</span>
                                            <strong><?= $number($row['new_lifetime'] ?? 0) ?></strong>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['quantity'] !== null ? $number($row['quantity']) : '-' ?></td>
                                <td class="log-message"><?= esc($row['message'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center text-secondary py-5">No records found.</td>
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
        const topToolData = <?= json_encode($topToolChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        Chart.defaults.font.family = 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
        Chart.defaults.color = '#667085';

        new Chart(document.getElementById('toolEventChart'), {
            type: 'doughnut',
            data: {
                labels: eventData.labels,
                datasets: [{
                    data: eventData.data,
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#4f46e5', '#06b6d4', '#8b5cf6', '#94a3b8', '#f97316'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        new Chart(document.getElementById('topToolChart'), {
            type: 'bar',
            data: {
                labels: topToolData.labels,
                datasets: [{
                    label: 'Logs',
                    data: topToolData.data,
                    backgroundColor: '#4f46e5',
                    borderRadius: 8
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