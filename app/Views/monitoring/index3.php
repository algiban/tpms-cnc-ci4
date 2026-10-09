<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Production Monitoring') ?></title>
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <style>
        :root {
            color-scheme: light;
            --surface: #fff;
            --canvas: #f5f7fb;
            --ink: #182238;
            --subtle: #67758b;
            --line: #e5eaf2;
            --primary: #345be8;
            --primary-soft: #ebf0ff;
            --shadow: 0 12px 34px rgba(20, 38, 76, .055)
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            background: var(--canvas);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif
        }

        .monitoring-container {
            max-width: 1880px;
            margin: auto;
            padding: clamp(16px, 2.4vw, 36px)
        }

        .monitoring-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 26px
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary);
            font-size: .76rem;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 8px
        }

        .eyebrow::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor
        }

        .monitoring-title {
            font-size: clamp(1.65rem, 2.4vw, 2.45rem);
            letter-spacing: -.045em;
            font-weight: 800;
            margin: 0 0 6px
        }

        .monitoring-title span {
            color: var(--primary)
        }

        .monitoring-subtitle,
        .monitoring-container .text-muted,
        .modal-content .text-muted {
            color: var(--subtle) !important
        }

        .monitoring-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px
        }

        .update-label {
            font-size: .82rem;
            color: var(--subtle)
        }

        .system-pill {
            border-radius: 999px;
            padding: 9px 13px;
            font-size: .77rem;
            font-weight: 700
        }

        .theme-toggle {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            border-color: var(--line);
            background: var(--surface)
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 28px
        }

        .summary-tile {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 18px;
            background: var(--surface);
            box-shadow: var(--shadow);
            padding: 17px 19px;
            min-height: 98px
        }

        .summary-tile::before {
            content: "";
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            background: var(--tone)
        }

        .summary-label {
            color: var(--subtle);
            font-size: .78rem;
            font-weight: 650
        }

        .summary-value {
            color: var(--ink);
            font-size: 1.85rem;
            line-height: 1.25;
            font-weight: 800;
            letter-spacing: -.04em;
            margin-top: 7px
        }

        .summary-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--tone);
            position: absolute;
            right: 19px;
            top: 21px;
            box-shadow: 0 0 0 5px color-mix(in srgb, var(--tone) 14%, transparent)
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin: 0 0 16px
        }

        .section-heading h2 {
            font-size: 1.13rem;
            font-weight: 750;
            letter-spacing: -.025em;
            margin: 0
        }

        .section-heading p {
            color: var(--subtle);
            font-size: .85rem;
            margin: 3px 0 0
        }

        .machine-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 16px
        }

        .machine-column {
            width: 100%;
            min-width: 0
        }

        .machine-card {
            display: flex;
            flex-direction: column;
            min-height: 400px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: var(--surface);
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease
        }

        .machine-card:hover {
            transform: translateY(-4px);
            border-color: #b7c8fb;
            box-shadow: 0 18px 45px rgba(20, 38, 76, .12)
        }

        .machine-card:focus-visible {
            outline: 3px solid var(--primary);
            outline-offset: 3px
        }

        .slot-badge {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            padding: 6px 9px;
            border-radius: 9px;
            background: var(--primary-soft);
            color: var(--primary);
            font-size: .74rem;
            font-weight: 800;
            white-space: nowrap
        }

        .machine-name {
            color: var(--ink);
            font-size: .95rem;
            font-weight: 800
        }

        .part-name {
            color: var(--subtle);
            font-size: .77rem
        }

        .machine-card .alert {
            border-radius: 12px;
            font-size: .76rem
        }

        .production-circle {
            width: 134px;
            height: 134px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: conic-gradient(var(--primary) calc(var(--progress)*1%), var(--line) 0);
            flex-shrink: 0
        }

        .production-circle::before {
            content: none
        }

        .production-circle-content {
            position: relative;
            z-index: 1;
            width: calc(100% - 18px);
            height: calc(100% - 18px);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: var(--surface)
        }

        .production-percentage {
            font-size: 1.65rem;
            line-height: 1.1;
            letter-spacing: -.05em;
            font-weight: 800;
            color: var(--ink)
        }

        .production-text {
            color: var(--subtle);
            font-weight: 800;
            font-size: .57rem;
            letter-spacing: .12em;
            margin-top: 4px
        }

        .production-number {
            text-align: center;
            color: var(--subtle);
            font-size: .8rem
        }

        .production-number strong {
            color: var(--ink);
            font-size: 1rem
        }

        .tool-section-title {
            border-top: 1px solid var(--line);
            padding-top: 14px;
            color: var(--ink);
            font-size: .77rem;
            font-weight: 800
        }

        .tool-item {
            margin-bottom: 13px
        }

        .tool-name {
            color: var(--ink);
            font-size: .76rem;
            font-weight: 700
        }

        .tool-position {
            flex: none;
            border-radius: 6px;
            background: var(--canvas);
            color: var(--subtle);
            padding: 2px 6px;
            font-size: .65rem
        }

        .tool-lifetime {
            color: var(--subtle);
            font-size: .69rem
        }

        .progress {
            height: 7px;
            border-radius: 999px;
            background: var(--line)
        }

        .progress-bar {
            border-radius: inherit
        }

        .card-footer-status {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            font-size: .73rem;
            color: var(--ink);
            font-weight: 700
        }

        .card-footer-status .text-muted {
            font-weight: 500;
            font-size: .7rem
        }

        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 5px;
            box-shadow: 0 0 0 4px var(--canvas)
        }

        .modal-content {
            background: var(--surface);
            color: var(--ink);
            border-radius: 20px !important
        }

        .modal-header,
        .modal-footer {
            border-color: var(--line);
            padding: 18px 22px
        }

        .modal-body {
            padding: 22px
        }

        .detail-label {
            font-size: .75rem;
            color: var(--subtle);
            margin-bottom: 5px
        }

        .detail-value {
            font-weight: 750;
            color: var(--ink);
            overflow-wrap: anywhere
        }

        .detail-metric {
            background: var(--canvas);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 11px
        }

        .tool-detail-card {
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 14px;
            background: var(--canvas);
            margin-bottom: 10px
        }

        .modal-content .btn-light {
            background: var(--canvas);
            color: var(--ink);
            border-color: var(--line)
        }

        @media(max-width:1450px) {
            .machine-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr))
            }

            .summary-grid {
                grid-template-columns: repeat(6, minmax(0, 1fr))
            }
        }

        @media(max-width:1080px) {
            .machine-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr))
            }
        }

        @media(max-width:760px) {
            .machine-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }

            .summary-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr))
            }
        }

        @media(max-width:540px) {
            .monitoring-container {
                padding: 16px
            }

            .machine-grid {
                grid-template-columns: 1fr
            }

            .summary-grid {
                gap: 8px
            }

            .summary-tile {
                min-height: 80px;
                padding: 12px;
                border-radius: 13px
            }

            .summary-value {
                font-size: 1.45rem
            }

            .machine-card {
                min-height: auto
            }
        }

        body.dark-mode {
            color-scheme: dark;
            --surface: #172238;
            --canvas: #0e1728;
            --ink: #eff4fc;
            --subtle: #a6b5ca;
            --line: #293850;
            --primary: #88a9ff;
            --primary-soft: #223658;
            --shadow: 0 16px 38px rgba(0, 0, 0, .16)
        }

        .dark-mode .btn-close {
            filter: invert(1) grayscale(1)
        }

        .dark-mode .theme-toggle {
            color: var(--ink)
        }

        .dark-mode .modal-content .border {
            border-color: var(--line) !important
        }

        .dark-mode .alert-warning {
            background: #3d311d;
            color: #ffe2a0;
            border-color: #594527
        }

        .dark-mode .alert-danger {
            background: #421e2a;
            color: #ffc5ce;
            border-color: #61303d
        }

        @media(prefers-reduced-motion:reduce) {
            .machine-card {
                transition: none
            }
        }
    </style>
</head>

<body>
    <div class="monitoring-container">
        <header class="monitoring-header">
            <div>
                <div class="eyebrow">TPMS Live Dashboard</div>
                <h1 class="monitoring-title">Production <span>Monitoring</span></h1>
                <p class="monitoring-subtitle mb-0">Pantau produksi mesin dan umur pakai tools secara langsung.</p>
                <div class="update-label mt-1" id="lastUpdated" aria-live="polite">Menghubungkan ke monitoring...</div>
            </div>
            <div class="monitoring-meta">
                <span class="badge system-pill text-bg-secondary" id="systemStatus" role="status">● Connecting...</span>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary theme-toggle"
                    id="themeToggle"
                    title="Toggle Night Mode"
                    aria-label="Ubah tampilan terang atau gelap">
                    <span id="themeIcon">🌙</span>
                </button>
            </div>
        </header>
        <div class="summary-grid" id="monitoringSummary" aria-label="Ringkasan status mesin">
            <div class="summary-tile" style="--tone:#64748b">
                <div class="summary-label">Ringkasan</div>
                <div class="summary-value">—</div>
            </div>
        </div>
        <div class="section-heading">
            <div>
                <h2>Mesin Produksi</h2>
                <p>Klik kartu mesin untuk melihat rincian produksi dan tools.</p>
            </div>
        </div>
        <div class="machine-grid" id="machineGrid">
            <div class="py-5 text-center text-muted">
                Loading production monitoring...
            </div>
        </div>
    </div>
    <!-- One reusable modal. It stays open while cards are refreshed. -->
    <div class="modal fade" id="machineModal" tabindex="-1" aria-labelledby="machineModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow">
                <div class="modal-header">
                    <div>
                        <div class="d-flex gap-2 align-items-center mb-1">
                            <span class="slot-badge" id="modalSlot">-</span>
                            <h5 class="modal-title fw-bold mb-0" id="machineModalTitle">Machine</h5>
                        </div>
                        <div class="text-muted small" id="modalPart">-</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="machineModalBody">
                    <div class="text-center text-muted py-5">No machine selected.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            'use strict';
            const apiUrl = <?= json_encode(site_url('api/monitoring'), JSON_UNESCAPED_SLASHES) ?>;
            const defaultPollMs = 2000;
            const machineGrid = document.getElementById('machineGrid');
            const summaryEl = document.getElementById('monitoringSummary');
            const systemStatus = document.getElementById('systemStatus');
            const lastUpdated = document.getElementById('lastUpdated');
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const modalElement = document.getElementById('machineModal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            const modalSlot = document.getElementById('modalSlot');
            const modalTitle = document.getElementById('machineModalTitle');
            const modalPart = document.getElementById('modalPart');
            const modalBody = document.getElementById('machineModalBody');
            const numberFormatter = new Intl.NumberFormat('id-ID');
            const machineCache = new Map();
            let selectedMachineId = null;
            let pollTimer = null;
            let activeRequest = null;
            let pollMs = defaultPollMs;
            let consecutiveErrors = 0;

            function escapeHtml(value) {
                return String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function fmt(value) {
                return numberFormatter.format(Number(value || 0));
            }

            function clampPercentage(value) {
                const number = Number(value || 0);
                return Math.max(0, Math.min(100, number));
            }

            function statusMeta(code) {
                switch (code) {
                    case 'running':
                        return {
                            badge: 'text-bg-success', dot: '#198754', label: 'Running'
                        };
                    case 'paused':
                        return {
                            badge: 'text-bg-warning', dot: '#ffc107', label: 'Paused'
                        };
                    case 'alarm':
                        return {
                            badge: 'text-bg-danger', dot: '#dc3545', label: 'Alarm / Service'
                        };
                    case 'offline':
                        return {
                            badge: 'text-bg-secondary', dot: '#6c757d', label: 'Offline'
                        };
                    default:
                        return {
                            badge: 'text-bg-primary', dot: '#0d6efd', label: 'Idle'
                        };
                }
            }

            function toolMeta(tool) {
                const remaining = Number(tool.remaining || 0);
                const status = String(tool.status || '').toLowerCase();
                if (status === 'broken' || remaining <= 3) {
                    return {
                        progress: 'bg-danger',
                        badge: 'text-bg-danger',
                        label: status === 'broken' ? 'Broken' : 'Critical'
                    };
                }
                if (remaining <= 10) {
                    return {
                        progress: 'bg-warning',
                        badge: 'text-bg-warning',
                        label: 'Warning'
                    };
                }
                return {
                    progress: 'bg-success',
                    badge: 'text-bg-success',
                    label: 'Normal'
                };
            }

            function alarmBanner(machine) {
                const alarms = Array.isArray(machine.alarms) ? machine.alarms : [];
                if (!alarms.length) {
                    return '';
                }
                const critical = alarms.find(alarm => alarm.severity === 'critical' || alarm.effect === 'service_stop');
                const alarm = critical || alarms[0];
                const style = critical ? 'alert-danger' : 'alert-warning';
                return `
            <div class="alert ${style} py-2 px-2 mb-3 small">
                <strong>${escapeHtml(alarm.kind || 'Alarm')}</strong><br>
                ${escapeHtml(alarm.message || '')}
            </div>
        `;
            }

            function toolCard(tool) {
                const percentage = clampPercentage(tool.percentage);
                const meta = toolMeta(tool);
                return `
            <div class="tool-item">
                <div class="d-flex justify-content-between align-items-center mb-1 gap-2">
                    <div class="tool-name text-truncate" title="${escapeHtml(tool.name)}">
                        ${escapeHtml(tool.name || tool.code || '-')}
                    </div>
                    <div class="tool-position">${escapeHtml(tool.position || '-')}</div>
                </div>
                <div class="progress">
                    <div class="progress-bar ${meta.progress}" style="width:${percentage}%"></div>
                </div>
                <div class="d-flex justify-content-between mt-1 gap-2">
                    <span class="tool-lifetime">${fmt(tool.actual_lifetime)} / ${fmt(tool.max_lifetime)}</span>
                    <span class="tool-lifetime">${percentage.toFixed(0)}%</span>
                </div>
            </div>
        `;
            }

            function machineCard(machine) {
                const percentage = clampPercentage(machine.production_percentage);
                const status = statusMeta(machine.status_code);
                const topTools = Array.isArray(machine.top_tools) ? machine.top_tools : [];
                const toolHtml = topTools.length ?
                    topTools.map(toolCard).join('') :
                    '<div class="text-muted small mb-3">No active tools.</div>';
                return `
            <div class="machine-column">
                <div
                    class="machine-card p-3 h-100"
                    data-machine-id="${Number(machine.machine_id)}"
                    role="button"
                    tabindex="0"
                    aria-label="View ${escapeHtml(machine.machine_name)} detail">
                    <div class="d-flex justify-content-between align-items-start mb-3 gap-2">
                        <div>
                            <span class="slot-badge">${escapeHtml(machine.slot)}</span>
                        </div>
                        <div class="text-end overflow-hidden">
                            <h6 class="machine-name mb-1 text-truncate">${escapeHtml(machine.machine_name)}</h6>
                            <div class="part-name text-truncate">${escapeHtml(machine.part_name)}</div>
                        </div>
                    </div>
                    ${alarmBanner(machine)}
                    <div class="d-flex flex-column align-items-center mb-4">
                        <div class="production-circle" style="--progress:${percentage}">
                            <div class="production-circle-content">
                                <div class="production-percentage">${percentage.toFixed(0)}%</div>
                                <div class="production-text">PRODUCTION</div>
                            </div>
                        </div>
                        <div class="production-number mt-2">
                            <strong>${fmt(machine.production_actual)}</strong>
                            / ${fmt(machine.production_target)} pcs
                        </div>
                    </div>
                    <div class="tool-section-title mb-3">Tool Lifetime</div>
                    ${toolHtml}
                    <div class="card-footer-status d-flex justify-content-between align-items-center gap-2">
                        <span>
                            <span class="status-dot" style="background:${status.dot}"></span>
                            ${escapeHtml(status.label)}
                        </span>
                        <span class="text-muted text-end">${escapeHtml(machine.shift || '-')}</span>
                    </div>
                </div>
            </div>
        `;
            }

            function renderMachines(machines) {
                machineCache.clear();
                for (const machine of machines) {
                    machineCache.set(Number(machine.machine_id), machine);
                }
                if (!machines.length) {
                    machineGrid.innerHTML = '<div class="py-5 text-center text-muted">No production machines found.</div>';
                    return;
                }
                machineGrid.innerHTML = machines.map(machineCard).join('');
                if (selectedMachineId !== null && modalElement.classList.contains('show')) {
                    const selected = machineCache.get(Number(selectedMachineId));
                    if (selected) {
                        renderModal(selected);
                    }
                }
            }

            function renderSummary(summary) {
                const item = (label, value, tone) => `
            <div class="summary-tile" style="--tone:${tone}">
                <span class="summary-dot" aria-hidden="true"></span>
                <div class="summary-label">${escapeHtml(label)}</div>
                <div class="summary-value">${fmt(value)}</div>
            </div>
        `;
                summaryEl.innerHTML = [
                    item('Total', summary.total, '#345be8'),
                    item('Running', summary.running, '#1ba784'),
                    item('Paused', summary.paused, '#e7a12b'),
                    item('Alarm', summary.alarm, '#e75f72'),
                    item('Idle', summary.idle, '#5670ed'),
                    item('Offline', summary.offline, '#8995a7')
                ].join('');
            }

            function renderToolDetail(tool) {
                const percentage = clampPercentage(tool.percentage);
                const meta = toolMeta(tool);
                return `
            <div class="tool-detail-card">
                <div class="d-flex justify-content-between mb-2 gap-3">
                    <div>
                        <strong>${escapeHtml(tool.name || tool.code || '-')}</strong>
                        <div class="text-muted small">
                            ${escapeHtml(tool.code || '')}
                            ${tool.code ? ' · ' : ''}
                            Position: ${escapeHtml(tool.position || '-')}
                        </div>
                    </div>
                    <span class="badge ${meta.badge} align-self-start">${escapeHtml(meta.label)}</span>
                </div>
                <div class="progress mb-2">
                    <div class="progress-bar ${meta.progress}" style="width:${percentage}%"></div>
                </div>
                <div class="d-flex justify-content-between small gap-3 flex-wrap">
                    <span>Lifetime: ${fmt(tool.actual_lifetime)} / ${fmt(tool.max_lifetime)}</span>
                    <span>Remaining: <strong>${fmt(Math.max(0, Number(tool.remaining || 0)))}</strong></span>
                </div>
            </div>
        `;
            }

            function renderAlarmDetails(machine) {
                const alarms = Array.isArray(machine.alarms) ? machine.alarms : [];
                if (!alarms.length) {
                    return '';
                }
                return `
            <div class="col-12">
                <h6 class="fw-bold mb-3">Active Alarm</h6>
                ${alarms.map(alarm => {
                    const critical = alarm.severity === 'critical' || alarm.effect === 'service_stop';
                    return `
                        <div class="alert ${critical ? 'alert-danger' : 'alert-warning'} mb-2">
                            <div class="d-flex justify-content-between gap-3 flex-wrap">
                                <strong>${escapeHtml(alarm.kind || 'alarm')}</strong>
                                <span>${escapeHtml(alarm.severity || '-')} · ${escapeHtml(alarm.effect || '-')}</span>
                            </div>
                            <div>${escapeHtml(alarm.message || '')}</div>
                            <div class="small mt-1 opacity-75">${escapeHtml(alarm.created_at || '')}</div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
            }

            function renderModal(machine) {
                const percentage = clampPercentage(machine.production_percentage);
                const status = statusMeta(machine.status_code);
                const tools = Array.isArray(machine.tools) ? machine.tools : [];
                modalSlot.textContent = machine.slot || '-';
                modalTitle.textContent = machine.machine_name || 'Machine';
                modalPart.textContent = `${machine.part_number || ''}${machine.part_number ? ' · ' : ''}${machine.part_name || '-'}`;
                modalBody.innerHTML = `
            <div class="row g-4">
                ${renderAlarmDetails(machine)}
                <div class="col-md-4">
                    <div class="d-flex justify-content-center mb-3">
                        <div class="production-circle" style="--progress:${percentage}">
                            <div class="production-circle-content">
                                <div class="production-percentage">${percentage.toFixed(0)}%</div>
                                <div class="production-text">PRODUCTION</div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <div class="detail-metric">
                                <div class="detail-label">Actual</div>
                                <div class="detail-value">${fmt(machine.production_actual)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="detail-metric">
                                <div class="detail-label">Target</div>
                                <div class="detail-value">${fmt(machine.production_target)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="detail-metric">
                                <div class="detail-label">Good</div>
                                <div class="detail-value">${fmt(machine.good_qty)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="detail-metric">
                                <div class="detail-label">Reject</div>
                                <div class="detail-value">${fmt(machine.reject_qty)}</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <div class="detail-label">Machine</div>
                            <div class="detail-value">${escapeHtml(machine.machine_name)}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Product / Part</div>
                            <div class="detail-value">${escapeHtml(machine.part_name)}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Operator</div>
                            <div class="detail-value">${escapeHtml(machine.operator || '-')}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Shift</div>
                            <div class="detail-value">${escapeHtml(machine.shift || '-')}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Start Production</div>
                            <div class="detail-value">${escapeHtml(machine.start_time || '-')}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Status</div>
                            <div><span class="badge ${status.badge}">${escapeHtml(status.label)}</span></div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Device</div>
                            <div class="detail-value">${machine.device?.online ? 'Online' : 'Offline'}</div>
                        </div>
                        <div class="col-md-6">
                            <div class="detail-label">Last Seen</div>
                            <div class="detail-value">${escapeHtml(machine.device?.last_seen_at || '-')}</div>
                        </div>
                    </div>
                    <h6 class="fw-bold mb-3">Tools</h6>
                    ${tools.length
                        ? tools.map(renderToolDetail).join('')
                        : '<div class="text-muted">No tool snapshot for the active shift.</div>'}
                </div>
            </div>
        `;
            }

            function openMachine(machineId) {
                const machine = machineCache.get(Number(machineId));
                if (!machine) {
                    return;
                }
                selectedMachineId = Number(machineId);
                renderModal(machine);
                modal.show();
            }
            machineGrid.addEventListener('click', event => {
                const card = event.target.closest('[data-machine-id]');
                if (card) {
                    openMachine(card.dataset.machineId);
                }
            });
            machineGrid.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                const card = event.target.closest('[data-machine-id]');
                if (card) {
                    event.preventDefault();
                    openMachine(card.dataset.machineId);
                }
            });
            modalElement.addEventListener('hidden.bs.modal', () => {
                selectedMachineId = null;
            });

            function setOnline(serverTime) {
                systemStatus.className = 'badge system-pill text-bg-success';
                systemStatus.textContent = '● System Online';
                lastUpdated.textContent = `Last update: ${serverTime || new Date().toLocaleString('id-ID')}`;
                consecutiveErrors = 0;
            }

            function setOffline(message) {
                systemStatus.className = 'badge system-pill text-bg-danger';
                systemStatus.textContent = '● Monitoring API Offline';
                lastUpdated.textContent = message || 'Unable to refresh monitoring data.';
            }

            function scheduleNext() {
                clearTimeout(pollTimer);
                pollTimer = setTimeout(loadMonitoring, pollMs);
            }
            async function loadMonitoring() {
                if (document.hidden) {
                    scheduleNext();
                    return;
                }
                if (activeRequest) {
                    scheduleNext();
                    return;
                }
                activeRequest = new AbortController();
                try {
                    const response = await fetch(apiUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        cache: 'no-store',
                        signal: activeRequest.signal
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const payload = await response.json();
                    if (!payload.ok || !payload.data) {
                        throw new Error(payload.message || 'Invalid monitoring response');
                    }
                    const machines = Array.isArray(payload.data.machines) ? payload.data.machines : [];
                    renderMachines(machines);
                    renderSummary(payload.data.summary || {});
                    pollMs = Math.max(1000, Number(payload.data.refresh_after_ms || defaultPollMs));
                    setOnline(payload.data.generated_at);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        consecutiveErrors++;
                        setOffline(`Refresh gagal (${error.message}). Data terakhir tetap ditampilkan.`);
                        // Back off while the server/network is failing, max 10 seconds.
                        pollMs = Math.min(10000, defaultPollMs * Math.max(1, consecutiveErrors));
                    }
                } finally {
                    activeRequest = null;
                    scheduleNext();
                }
            }

            function applyTheme(theme) {
                const dark = theme === 'dark';
                document.body.classList.toggle('dark-mode', dark);
                document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
                themeIcon.textContent = dark ? '☀️' : '🌙';
                themeToggle.setAttribute('title', dark ? 'Switch to Light Mode' : 'Switch to Night Mode');
                themeToggle.setAttribute('aria-pressed', String(dark));
            }
            const savedTheme = localStorage.getItem('monitoring-theme');
            if (savedTheme) {
                applyTheme(savedTheme);
            } else {
                applyTheme(window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            }
            themeToggle.addEventListener('click', () => {
                const newTheme = document.body.classList.contains('dark-mode') ? 'light' : 'dark';
                applyTheme(newTheme);
                localStorage.setItem('monitoring-theme', newTheme);
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    clearTimeout(pollTimer);
                    loadMonitoring();
                }
            });
            window.addEventListener('beforeunload', () => {
                clearTimeout(pollTimer);
                activeRequest?.abort();
            });
            loadMonitoring();
        })();
    </script>
</body>

</html>