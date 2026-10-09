<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Production Monitoring') ?></title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <link
        rel="stylesheet"
        href="<?= esc(base_url('assets/css/monitoring.css'), 'attr') ?>">
</head>
<body>
<div class="monitoring-container">
    <div class="d-flex justify-content-between align-items-center mb-4 gap-3 flex-wrap">
        <div>
            <h4 class="fw-bold mb-1">Production Monitoring</h4>
            <div class="text-muted small">Real-time machine production &amp; tool lifetime</div>
            <div class="text-muted small mt-1" id="lastUpdated">Waiting for monitoring API...</div>
        </div>

        <div class="d-flex align-items-center gap-2">
            <span class="badge text-bg-secondary" id="systemStatus">● Connecting...</span>
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary theme-toggle"
                id="themeToggle"
                title="Toggle Night Mode">
                <span id="themeIcon">🌙</span>
            </button>
        </div>
    </div>

    <div class="row g-2 mb-3" id="monitoringSummary">
        <div class="col-auto"><span class="badge text-bg-secondary">Loading summary...</span></div>
    </div>

    <div class="row g-3" id="machineGrid">
        <div class="col-12 py-5 text-center text-muted">
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
                return { badge: 'text-bg-success', dot: '#198754', label: 'Running' };
            case 'paused':
                return { badge: 'text-bg-warning', dot: '#ffc107', label: 'Paused' };
            case 'alarm':
                return { badge: 'text-bg-danger', dot: '#dc3545', label: 'Alarm / Service' };
            case 'offline':
                return { badge: 'text-bg-secondary', dot: '#6c757d', label: 'Offline' };
            default:
                return { badge: 'text-bg-primary', dot: '#0d6efd', label: 'Idle' };
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

        const toolHtml = topTools.length
            ? topTools.map(toolCard).join('')
            : '<div class="text-muted small mb-3">No active tools.</div>';

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
            machineGrid.innerHTML = '<div class="col-12 py-5 text-center text-muted">No production machines found.</div>';
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
        const item = (label, value, badge) => `
            <div class="col-auto">
                <span class="badge ${badge}">${escapeHtml(label)}: ${fmt(value)}</span>
            </div>
        `;

        summaryEl.innerHTML = [
            item('Total', summary.total, 'text-bg-dark'),
            item('Running', summary.running, 'text-bg-success'),
            item('Paused', summary.paused, 'text-bg-warning'),
            item('Alarm', summary.alarm, 'text-bg-danger'),
            item('Idle', summary.idle, 'text-bg-primary'),
            item('Offline', summary.offline, 'text-bg-secondary')
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
                            <div class="border rounded p-2">
                                <div class="detail-label">Actual</div>
                                <div class="detail-value">${fmt(machine.production_actual)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2">
                                <div class="detail-label">Target</div>
                                <div class="detail-value">${fmt(machine.production_target)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2">
                                <div class="detail-label">Good</div>
                                <div class="detail-value">${fmt(machine.good_qty)}</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="border rounded p-2">
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
        systemStatus.className = 'badge text-bg-success';
        systemStatus.textContent = '● System Online';
        lastUpdated.textContent = `Last update: ${serverTime || new Date().toLocaleString('id-ID')}`;
        consecutiveErrors = 0;
    }

    function setOffline(message) {
        systemStatus.className = 'badge text-bg-danger';
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
        themeIcon.textContent = dark ? '☀️' : '🌙';
        themeToggle.setAttribute('title', dark ? 'Switch to Light Mode' : 'Switch to Night Mode');
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
