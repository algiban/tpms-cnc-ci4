<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>TPMS Monitoring</title>
    <link rel="stylesheet" href="<?= base_url() ?>assets/css/monitoring.css">
</head>

<body>
    <header class="monitor-header">
        <div class="monitor-topbar">
            <div class="monitor-brand">
                <div class="monitor-logo">T</div>
                <div class="monitor-title">
                    <h1>TPMS Production Monitoring</h1><span>Live Machine & Production Status</span>
                </div>
            </div>
            <div class="monitor-nav"><a href="<?= esc(site_url('dashboard'), 'attr') ?>">Dashboard</a><span id="updated">Loading...</span></div>
        </div>
        <div class="monitor-header-grid">
            <section class="header-block shift-card">
                <div>
                    <div class="shift-eyebrow">Current Shift</div>
                    <div class="shift-name" id="currentShiftName">-</div>
                    <div class="shift-time" id="currentShiftTime">-</div>
                </div>
                <div class="shift-bottom">
                    <div class="shift-machine-count"><strong id="shiftMachineCount">0</strong><span>machines in shift</span></div>
                    <div class="shift-clock" id="currentClock">--:--</div>
                </div>
            </section>
            <section class="header-block status-zone">
                <div class="status-zone-title"><span>Machine Status</span><span id="totalMachinesLabel">0 Machines</span></div>
                <div class="status-cards">
                    <button type="button" class="status-card" data-status-filter="running" style="--status-color:var(--running)">
                        <div class="status-card-top"><span class="status-name"><i class="status-dot"></i>Running</span><span class="status-percent" id="runningPct">0%</span></div>
                        <div class="status-count"><span id="runningCount">0</span><small>machine</small></div>
                        <div class="status-bar"><span id="runningBar" style="width:0%"></span></div>
                    </button>
                    <button type="button" class="status-card" data-status-filter="paused" style="--status-color:var(--paused)">
                        <div class="status-card-top"><span class="status-name"><i class="status-dot"></i>Paused</span><span class="status-percent" id="pausedPct">0%</span></div>
                        <div class="status-count"><span id="pausedCount">0</span><small>machine</small></div>
                        <div class="status-bar"><span id="pausedBar" style="width:0%"></span></div>
                    </button>
                    <button type="button" class="status-card" data-status-filter="alarm" style="--status-color:var(--alarm)">
                        <div class="status-card-top"><span class="status-name"><i class="status-dot"></i>Alarm</span><span class="status-percent" id="alarmPct">0%</span></div>
                        <div class="status-count"><span id="alarmCount">0</span><small>machine</small></div>
                        <div class="status-bar"><span id="alarmBar" style="width:0%"></span></div>
                    </button>
                    <button type="button" class="status-card" data-status-filter="idle" style="--status-color:var(--idle)">
                        <div class="status-card-top"><span class="status-name"><i class="status-dot"></i>Idle</span><span class="status-percent" id="idlePct">0%</span></div>
                        <div class="status-count"><span id="idleCount">0</span><small>machine</small></div>
                        <div class="status-bar"><span id="idleBar" style="width:0%"></span></div>
                    </button>
                    <button type="button" class="status-card" data-status-filter="offline" style="--status-color:var(--offline)">
                        <div class="status-card-top"><span class="status-name"><i class="status-dot"></i>Offline</span><span class="status-percent" id="offlinePct">0%</span></div>
                        <div class="status-count"><span id="offlineCount">0</span><small>machine</small></div>
                        <div class="status-bar"><span id="offlineBar" style="width:0%"></span></div>
                    </button>
                </div>
            </section>
            <section class="header-block avg-card">
                <div class="avg-title">AVG Total Production</div>
                <div class="avg-ring" id="avgRing" style="--avg:0">
                    <div class="avg-ring-value" id="avgValue">0%</div>
                </div>
                <div class="avg-sub" id="avgSub">0 good / 0 target</div>
            </section>
        </div>
    </header>
    <main class="monitor-main">
        <div class="machine-toolbar">
            <div class="machine-toolbar-left"><span class="machine-toolbar-title">Machine Monitoring</span><span class="machine-toolbar-count" id="machineShownCount">0 / 0</span></div>
            <button type="button" class="filter-active" id="clearFilter">Clear status filter ×</button>
        </div>
        <div id="loadError" role="status"></div>
        <div class="machine-grid" id="machines"></div>
    </main>
    <dialog id="detail">
        <div class="detail-head">
            <div class="detail-head-main">
                <div class="detail-title" id="detailTitle">Machine Detail</div>
                <div class="detail-subtitle" id="detailSubtitle"></div>
            </div>
            <button class="detail-close" id="closeDetail" type="button">×</button>
        </div>
        <div class="detail-scroll" id="detailBody"></div>
    </dialog>
    <script>
        window.TPMS_MONITORING = <?= json_encode(['data' => site_url('api/monitoring'), 'history' => site_url('api/monitoring/machines')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script>
        (() => {
            const config = window.TPMS_MONITORING || {};
            const statusMeta = {
                running: {
                    label: 'Running',
                    color: '#16a34a'
                },
                paused: {
                    label: 'Paused',
                    color: '#8b5cf6'
                },
                alarm: {
                    label: 'Alarm',
                    color: '#ef4444'
                },
                idle: {
                    label: 'Idle',
                    color: '#f59e0b'
                },
                offline: {
                    label: 'Offline',
                    color: '#94a3b8'
                }
            };
            const state = {
                machines: [],
                summary: {},
                filter: null,
                refreshTimer: null,
                polling: false
            };
            const el = {
                machines: document.getElementById('machines'),
                loadError: document.getElementById('loadError'),
                updated: document.getElementById('updated'),
                currentShiftName: document.getElementById('currentShiftName'),
                currentShiftTime: document.getElementById('currentShiftTime'),
                shiftMachineCount: document.getElementById('shiftMachineCount'),
                currentClock: document.getElementById('currentClock'),
                totalMachinesLabel: document.getElementById('totalMachinesLabel'),
                machineShownCount: document.getElementById('machineShownCount'),
                clearFilter: document.getElementById('clearFilter'),
                avgRing: document.getElementById('avgRing'),
                avgValue: document.getElementById('avgValue'),
                avgSub: document.getElementById('avgSub'),
                detail: document.getElementById('detail'),
                detailTitle: document.getElementById('detailTitle'),
                detailSubtitle: document.getElementById('detailSubtitle'),
                detailBody: document.getElementById('detailBody'),
                closeDetail: document.getElementById('closeDetail')
            };
            const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [char]));
            const num = value => Number(value || 0);
            const fmt = value => new Intl.NumberFormat('id-ID').format(num(value));
            const percent = value => Math.max(0, Math.min(100, num(value)));
            const statusColor = status => (statusMeta[status] || statusMeta.offline).color;
            const statusLabel = status => (statusMeta[status] || statusMeta.offline).label;
            const getShiftFallback = () => {
                const hour = new Date().getHours();
                if (hour >= 7 && hour < 15) return {
                    name: 'Day Shift',
                    time: '07:00 – 15:00'
                };
                if (hour >= 15 && hour < 23) return {
                    name: 'Mid Shift',
                    time: '15:00 – 23:00'
                };
                return {
                    name: 'Night Shift',
                    time: '23:00 – 07:00'
                };
            };
            const shiftTimeFromName = name => {
                const text = String(name || '').toLowerCase();
                if (text.includes('day') || text.includes('shift 1') || text === '1') return '07:00 – 15:00';
                if (text.includes('mid') || text.includes('shift 2') || text === '2') return '15:00 – 23:00';
                if (text.includes('night') || text.includes('shift 3') || text === '3') return '23:00 – 07:00';
                return '-';
            };
            const resolveCurrentShift = machines => {
                const counts = new Map();
                machines.forEach(machine => {
                    const shift = String(machine.shift || '').trim();
                    if (!shift || shift === '-') return;
                    counts.set(shift, (counts.get(shift) || 0) + 1);
                });
                if (!counts.size) {
                    const fallback = getShiftFallback();
                    return {
                        name: fallback.name,
                        time: fallback.time,
                        count: 0
                    };
                }
                const sorted = [...counts.entries()].sort((a, b) => b[1] - a[1]);
                const [name, count] = sorted[0];
                return {
                    name,
                    time: shiftTimeFromName(name),
                    count
                };
            };
            const updateClock = () => {
                el.currentClock.textContent = new Date().toLocaleTimeString('id-ID', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };
            const updateHeader = data => {
                const machines = state.machines;
                const total = machines.length;
                const summary = data.summary || {};
                el.totalMachinesLabel.textContent = `${fmt(total)} Machines`;
                ['running', 'paused', 'alarm', 'idle', 'offline'].forEach(status => {
                    const count = num(summary[status] ?? machines.filter(machine => (machine.status_code || 'idle') === status).length);
                    const pct = total > 0 ? (count / total) * 100 : 0;
                    document.getElementById(`${status}Count`).textContent = fmt(count);
                    document.getElementById(`${status}Pct`).textContent = `${pct.toFixed(0)}%`;
                    document.getElementById(`${status}Bar`).style.width = `${pct}%`;
                });
                const apiShift = data.current_shift || null;
                const shift = apiShift ? {
                    id: num(apiShift.id),
                    name: apiShift.name || apiShift.code || '-',
                    time: `${String(apiShift.start_time || '').slice(0,5)} – ${String(apiShift.end_time || '').slice(0,5)}`,
                    count: machines.filter(machine => num(machine.shift_id) === num(apiShift.id)).length
                } : resolveCurrentShift(machines);
                el.currentShiftName.textContent = shift.name;
                el.currentShiftTime.textContent = shift.time;
                const shiftCount = apiShift
                    ? machines.filter(machine => num(machine.shift_id) === num(apiShift.id)).length
                    : machines.filter(machine => String(machine.shift || '') === String(shift.name)).length;
                el.shiftMachineCount.textContent = fmt(shiftCount || shift.count || 0);

                const shiftProduction = summary.shift_production || {};
                const shiftPct = percent(shiftProduction.percentage || 0);
                const shiftGood = num(shiftProduction.good_qty);
                const shiftTarget = num(shiftProduction.target_qty);
                el.avgRing.style.setProperty('--avg', shiftPct.toFixed(2));
                el.avgValue.textContent = `${shiftPct.toFixed(1)}%`;
                el.avgSub.textContent = `${shift.name} · ${fmt(shiftGood)} good / ${fmt(shiftTarget)} target`;
                el.updated.textContent = data.generated_at ? `Updated ${data.generated_at}` : 'Updated now';
            };
            const machineCard = machine => {
                const status = machine.status_code || 'offline';
                const color = statusColor(status);
                const progress = percent(machine.production_percentage);
                const good = num(machine.production_good ?? machine.production_actual);
                const target = num(machine.production_target);
                const totalGood = num(machine.production_total_good);
                const totalTarget = num(machine.production_total_target);
                const hasProduction = machine.production_id !== null && machine.production_id !== undefined;
                const alarmCount = num(machine.active_alarm_count);
                const process = hasProduction ? `P${num(machine.process_no)||1} · ${machine.process_name||'Process'}` : 'No active production';
                const footerClass = alarmCount > 0 ? 'machine-footer alarm' : 'machine-footer';
                const footerText = alarmCount > 0 ? `${alarmCount} active alarm` : machine.operator && machine.operator !== '-' ? machine.operator : 'No operator';
                return `<article class="machine-card" data-machine-id="${num(machine.machine_id||machine.id)}" style="--status-color:${color}" tabindex="0" role="button">
<div class="machine-head">
<span class="machine-code">${esc(machine.machine_name||`Machine ${machine.machine_id||machine.id}`)}</span>
<span class="machine-slot">${esc(machine.slot||`SLOT ${machine.slot_no??'-'}`)}</span>
</div>
<div class="machine-body">
<div class="machine-name">${esc(machine.production_code||'No production')}</div>
<div class="machine-part">
<strong>${esc(machine.part_number||machine.part_name||'No Active Production')}</strong>
<span>${esc(process)}</span>
</div>
<div class="machine-progress">
<div class="machine-ring" style="--progress:${progress};--status-color:${color}">
<div class="machine-ring-value">${progress.toFixed(0)}%</div>
</div>
<div class="machine-production-info">
<div class="machine-production-main">${fmt(good)} / ${fmt(target)}</div>
<div class="machine-production-label">GOOD / SHIFT TARGET</div>
<div class="machine-production-total">TOTAL <strong>${fmt(totalGood)} / ${fmt(totalTarget)}</strong></div>
</div>
</div>
</div>
<div class="${footerClass}">
<span>${esc(footerText)}</span>
<strong>${esc(statusLabel(status))}</strong>
</div>
</article>`;
            };
            const renderMachines = () => {
                const filtered = state.filter ? state.machines.filter(machine => (machine.status_code || 'idle') === state.filter) : state.machines;
                el.machineShownCount.textContent = `${fmt(filtered.length)} / ${fmt(state.machines.length)}`;
                el.clearFilter.classList.toggle('show', Boolean(state.filter));
                if (!filtered.length) {
                    el.machines.innerHTML = `<div class="empty-state">Tidak ada machine${state.filter?` dengan status ${esc(statusLabel(state.filter))}`:''}.</div>`;
                    return;
                }
                el.machines.innerHTML = filtered.map(machineCard).join('');
            };
            const setFilter = status => {
                state.filter = state.filter === status ? null : status;
                document.querySelectorAll('[data-status-filter]').forEach(card => card.classList.toggle('active', card.dataset.statusFilter === state.filter));
                renderMachines();
            };
            const toolRows = tools => {
                if (!Array.isArray(tools) || !tools.length) return '<div class="history-loading">Tidak ada tools aktif pada production ini.</div>';
                return `<div class="detail-table-wrap"><table class="detail-table"><thead><tr><th>Tool</th><th>Position</th><th>Side</th><th>Lifetime</th><th>Remaining</th><th>Status</th></tr></thead><tbody>${tools.map(tool=>{
const max=num(tool.max_lifetime);
const actual=num(tool.actual_lifetime);
const pct=percent(tool.percentage??(max>0?(actual/max)*100:0));
return`<tr><td><strong>${esc(tool.code||'-')}</strong><br><small>${esc(tool.name||'')}</small></td><td>${esc(tool.position||'-')}</td><td>${fmt(tool.current_side||1)} / ${fmt(tool.total_side||1)}</td><td class="tool-life">${fmt(actual)} / ${fmt(max)}<div class="tool-life-bar"><span style="width:${pct}%"></span></div></td><td>${fmt(tool.remaining)}</td><td>${esc(tool.status||'-')}</td></tr>`;
}).join('')}</tbody></table></div>`;
            };
            const alarmRows = alarms => {
                if (!Array.isArray(alarms) || !alarms.length) return '<div class="history-loading">Tidak ada alarm aktif.</div>';
                return alarms.map(alarm => `<div class="detail-alarm"><strong>${esc((alarm.severity||'alarm').toUpperCase())}</strong> · ${esc(alarm.message||alarm.kind||'Alarm')} ${alarm.tool_code?`· ${esc(alarm.tool_code)}`:''}</div>`).join('');
            };
            const renderDetail = machine => {
                const status = machine.status_code || 'offline';
                const progress = percent(machine.production_percentage);
                el.detailTitle.textContent = `${machine.machine_name||`Machine ${machine.machine_id}`} · ${machine.slot||''}`;
                el.detailSubtitle.textContent = `${statusLabel(status)} · ${machine.shift||'-'} · Last seen ${machine.device?.last_seen_at||'-'}`;
                el.detailBody.innerHTML = `<div class="detail-status-row">
<div class="detail-kpi"><div class="detail-kpi-label">Shift Progress</div><div class="detail-kpi-value">${progress.toFixed(1)}%</div></div>
<div class="detail-kpi"><div class="detail-kpi-label">Good / Target</div><div class="detail-kpi-value">${fmt(machine.production_good)} / ${fmt(machine.production_target)}</div></div>
<div class="detail-kpi"><div class="detail-kpi-label">Reject</div><div class="detail-kpi-value" style="color:#dc2626">${fmt(machine.shift_reject)}</div></div>
<div class="detail-kpi"><div class="detail-kpi-label">Total Production</div><div class="detail-kpi-value">${fmt(machine.production_total_good)} / ${fmt(machine.production_total_target)}</div></div>
</div>
<div class="detail-grid">
<section class="detail-box"><h3>Machine & Device</h3><dl>
<dt>Machine</dt><dd>${esc(machine.machine_name||'-')}</dd>
<dt>Slot</dt><dd>${esc(machine.slot||'-')}</dd>
<dt>Status</dt><dd style="color:${statusColor(status)};font-weight:900">${esc(statusLabel(status))}</dd>
<dt>Device</dt><dd>${machine.device?.online?'Online':'Offline'}</dd>
<dt>Device Status</dt><dd>${esc(machine.device?.device_status||'-')}</dd>
<dt>Last Seen</dt><dd>${esc(machine.device?.last_seen_at||'-')}</dd>
</dl></section>
<section class="detail-box"><h3>Current Production</h3><dl>
<dt>Production</dt><dd>${esc(machine.production_code||'-')}</dd>
<dt>Part</dt><dd>${esc(machine.part_number||'-')} · ${esc(machine.part_name||'-')}</dd>
<dt>Process</dt><dd>P${fmt(machine.process_no||1)} · ${esc(machine.process_name||'-')}</dd>
<dt>Mode</dt><dd>${esc(machine.process_mode||'-')}</dd>
<dt>Shift</dt><dd>${esc(machine.shift||'-')}</dd>
<dt>${esc(machine.personnel_role||'Operator')}</dt><dd>${esc(machine.operator||'-')}</dd>
<dt>Gross</dt><dd>${fmt(machine.shift_gross)}</dd>
<dt>Good</dt><dd>${fmt(machine.shift_good)}</dd>
<dt>Reject</dt><dd>${fmt(machine.shift_reject)}</dd>
<dt>Remaining Total</dt><dd>${fmt(machine.production_remaining)}</dd>
</dl></section>
</div>
<section class="detail-box" style="margin-bottom:10px"><h3>Active Tools</h3>${toolRows(machine.tools)}</section>
<section class="detail-box" style="margin-bottom:10px"><h3>Active Alarms</h3>${alarmRows(machine.alarms)}</section>
<section class="detail-box"><h3>Recent Production History · Per Shift</h3><div id="historyContent" class="history-loading">Loading history...</div></section>`;
                el.detail.showModal();
                loadHistory(machine.machine_id || machine.id);
            };
            const loadHistory = async machineId => {
                const target = document.getElementById('historyContent');
                if (!target) return;
                try {
                    const response = await fetch(`${config.history}/${machineId}/history`, {
                        headers: {
                            Accept: 'application/json'
                        },
                        cache: 'no-store'
                    });
                    const payload = await response.json();
                    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
                    const rows = payload.data?.history || [];
                    if (!rows.length) {
                        target.innerHTML = 'Tidak ada riwayat production.';
                        return;
                    }
                    target.innerHTML = `<div class="detail-table-wrap"><table class="detail-table"><thead><tr><th>Date</th><th>Shift</th><th>Production</th><th>Part</th><th>Process</th><th>Operator</th><th>Good</th><th>NC</th><th>Target</th><th>Progress</th><th>Status</th></tr></thead><tbody>${rows.map(row=>`<tr><td>${esc(row.work_date||row.production_date||'-')}</td><td><strong>${esc(row.shift_name||'-')}</strong></td><td>${esc(row.production_code||'-')}</td><td>${esc(row.part_number||'-')}</td><td>P${fmt(row.process_no)} · ${esc(row.process_name||'-')}</td><td>${esc(row.operator_name||'-')}</td><td style="color:#16a34a;font-weight:800">${fmt(row.good_qty)}</td><td style="color:#dc2626">${fmt(row.reject_qty)}</td><td>${fmt(row.target_qty)}</td><td>${num(row.progress).toFixed(1)}%</td><td>${esc(row.status||'-')}</td></tr>`).join('')}</tbody></table></div>`;
                } catch (error) {
                    target.innerHTML = `<span style="color:#b91c1c">${esc(error.message||error)}</span>`;
                }
            };
            const loadMonitoring = async () => {
                if (state.polling) return;
                state.polling = true;
                try {
                    const response = await fetch(config.data, {
                        headers: {
                            Accept: 'application/json'
                        },
                        cache: 'no-store'
                    });
                    const payload = await response.json();
                    if (!response.ok || payload.ok === false) throw new Error(payload.message || `HTTP ${response.status}`);
                    const data = payload.data || {};
                    state.machines = Array.isArray(data.machines) ? data.machines : [];
                    state.summary = data.summary || {};
                    el.loadError.textContent = '';
                    updateHeader(data);
                    renderMachines();
                    clearTimeout(state.refreshTimer);
                    state.refreshTimer = setTimeout(loadMonitoring, Math.max(1000, num(data.refresh_after_ms) || 2000));
                } catch (error) {
                    el.loadError.textContent = `Monitoring gagal dimuat: ${error.message||error}`;
                    clearTimeout(state.refreshTimer);
                    state.refreshTimer = setTimeout(loadMonitoring, 5000);
                } finally {
                    state.polling = false;
                }
            };
            document.querySelectorAll('[data-status-filter]').forEach(card => card.addEventListener('click', () => setFilter(card.dataset.statusFilter)));
            el.clearFilter.addEventListener('click', () => setFilter(state.filter));
            el.machines.addEventListener('click', event => {
                const card = event.target.closest('[data-machine-id]');
                if (!card) return;
                const id = num(card.dataset.machineId);
                const machine = state.machines.find(item => num(item.machine_id || item.id) === id);
                if (machine) renderDetail(machine);
            });
            el.machines.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                const card = event.target.closest('[data-machine-id]');
                if (!card) return;
                event.preventDefault();
                card.click();
            });
            el.closeDetail.addEventListener('click', () => el.detail.close());
            el.detail.addEventListener('click', event => {
                if (event.target === el.detail) el.detail.close();
            });
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) {
                    clearTimeout(state.refreshTimer);
                    loadMonitoring();
                }
            });
            setInterval(updateClock, 1000);
            updateClock();
            loadMonitoring();
        })();
    </script>
</body>

</html>