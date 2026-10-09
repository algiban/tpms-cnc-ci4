(() => {
    'use strict';
    const boot = () => {
        const source = document.getElementById('planningData');
        if (!source) return;
        const data = JSON.parse(source.textContent);
        const app = document.getElementById('planningApp');
        const get = id => document.getElementById(id);
        const shifts = data.shifts;
        const employees = new Map(data.employees.map(employee => [Number(employee.id), employee]));
        const machines = new Map(data.machines.map(machine => [Number(machine.id), machine]));
        const cells = new Map();
        const drafts = new Map();
        const baseline = new Map();
        const storageKey = `tpms-planning:${location.pathname}:${data.viewer}:${data.month}:${data.currentRole}`;
        const editable = data.canEdit && shifts.length === 3;
        let csrf = data.csrf;
        let selected = [];
        let anchor = null;
        let drag = null;
        let frame = 0;
        let busy = false;
        let leaving = false;
        let editorTouched = false;
        let pickerStates = [];
        let restoreNote = '';
        const pad = value => String(value).padStart(2, '0');
        const labelDate = value => new Date(`${value}T12:00:00`).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
        const keyOf = (machine, date) => `${machine}|${date}`;
        const normalize = value => Object.fromEntries(shifts.map(shift => [String(shift.id), value?.[shift.id] ? Number(value[shift.id]) : null]));
        const same = (a, b) => shifts.every(shift => a[shift.id] === b[shift.id]);
        const current = key => drafts.get(key)?.shifts || baseline.get(key);
        const el = (tag, className, text) => {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        };
        function message(text, type = '') {
            const node = get('peMessage');
            node.textContent = text;
            node.className = `pe-message ${type}`;
            node.hidden = !text;
        }
        function persist() {
            try {
                if (!drafts.size) sessionStorage.removeItem(storageKey);
                else sessionStorage.setItem(storageKey, JSON.stringify({ version: 1, shifts: shifts.map(s => s.id), cells: [...drafts] }));
            } catch (_) {
                message('Draft tersedia di halaman ini, tetapi browser tidak dapat menyimpan pemulihan draft. Save All sebelum menutup halaman.', 'error');
            }
        }
        function summary() {
            const count = drafts.size;
            get('peDraftCount').hidden = count === 0;
            get('peDraftCount').textContent = `${count} not saved yet`;
            get('peSave').disabled = !editable || !count || busy;
            get('peDiscard').disabled = !count || busy;
            get('peSummary').textContent = `${data.currentRoleLabel} · ${data.machines.length} mesin · ${data.dates.length} hari · ${count ? `${count} sel draft` : 'Semua perubahan tersimpan'}`;
        }
        function paint(key) {
            const td = cells.get(key);
            if (!td) return;
            const values = current(key);
            td.classList.toggle('pe-dirty', drafts.has(key));
            td.querySelectorAll('[data-shift-value]').forEach(node => {
                const id = values[node.dataset.shiftValue];
                const employee = employees.get(id);
                const text = employee ? employee.name : (id ? `ID ${id}` : '');
                node.textContent = text;
                node.title = employee ? `${employee.name} · ${employee.nik}${employee.status !== 'active' ? ' (nonaktif)' : ''}` : text;
            });
            const [machineId, date] = key.split('|');
            td.setAttribute('aria-label', `${machines.get(Number(machineId)).name}, ${labelDate(date)}${drafts.has(key) ? ', draft belum disimpan' : ''}`);
        }
        function buildGrid() {
            const row = el('tr');
            const machineHead = el('th', 'pe-machine-col pe-machine-head'); machineHead.scope = 'col'; machineHead.append(el('span', 'pe-machine-head-title', 'Machine'), el('small', 'pe-machine-head-sub', 'Name · Code · Maker')); row.append(machineHead);
            const weekdays = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
            data.dates.forEach(date => {
                const head = el('th', `pe-date-head${date.weekday > 5 ? ' pe-weekend' : ''}${date.date === data.today ? ' pe-today' : ''}`, String(date.day));
                head.scope = 'col';
                head.append(el('small', '', weekdays[date.weekday - 1]));
                row.append(head);
            });
            get('peHead').append(row);
            data.machines.forEach(machine => {
                const row = el('tr');
                const name = el('th', 'pe-machine-col'); name.scope = 'row';
                const meta = [machine.slot_no ? `Slot ${machine.slot_no}` : null, machine.code || null, machine.maker || null].filter(Boolean).join(' · '); name.append(el('span', 'pe-machine-name', machine.name), el('div', 'pe-machine-code', meta || '-'));
                row.append(name);
                data.dates.forEach((date, index) => {
                    const key = keyOf(machine.id, date.date);
                    baseline.set(key, normalize(data.schedule[key]));
                    const td = el('td', 'pe-cell');
                    td.dataset.key = key; td.dataset.day = String(index); td.dataset.machine = String(machine.id);
                    td.tabIndex = 0; td.setAttribute('role', 'button'); td.setAttribute('aria-haspopup', 'dialog');
                    if (!editable) td.setAttribute('aria-disabled', 'true');
                    const inner = el('div', 'pe-cell-inner');
                    shifts.forEach((shift, shiftIndex) => {
                        const strip = el('div', `pe-shift pe-shift-${shiftIndex}`);
                        const label = el('span', 'pe-shift-code', `S${shiftIndex + 1}`); label.title = shift.name;
                        const value = el('span', 'pe-employee-name'); value.dataset.shiftValue = String(shift.id);
                        strip.append(label, value); inner.append(strip);
                    });
                    td.append(inner); row.append(td); cells.set(key, td);
                });
                get('peBody').append(row);
            });
            if (!data.machines.length) {
                const tr = el('tr'); const td = el('td', 'pe-empty', 'Belum ada mesin aktif.');
                td.colSpan = data.dates.length + 1; tr.append(td); get('peBody').append(tr);
            }
            try {
                const restored = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
                if (restored && restored.version === 1 && JSON.stringify(restored.shifts) === JSON.stringify(shifts.map(s => s.id))) {
                    for (const [key, item] of restored.cells) {
                        if (cells.has(key) && item && typeof item.expected === 'object' && typeof item.shifts === 'object') {
                            const desired = normalize(item.shifts);
                            if (!same(desired, baseline.get(key))) drafts.set(key, { expected: normalize(item.expected), shifts: desired });
                        }
                    }
                    if (drafts.size) restoreNote = `${drafts.size} draft dipulihkan. Periksa sebelum Save All.`;
                }
            } catch (_) { restoreNote = 'Draft lokal tidak dapat dipulihkan. Data server tetap ditampilkan.'; }
            cells.forEach((_, key) => paint(key));
            summary();
        }
        function selectRange(machineId, from, to) {
            selected.forEach(key => cells.get(key)?.classList.remove('pe-selected'));
            selected = [];
            for (let index = Math.min(from, to); index <= Math.max(from, to); index++) {
                const key = keyOf(machineId, data.dates[index].date);
                selected.push(key); cells.get(key).classList.add('pe-selected');
            }
        }
        function closeEditor(clearSelection = false) {
            get('peEditor').hidden = true;
            editorTouched = false;
            pickerStates.forEach(p => { p.options.hidden = true; });
            if (clearSelection) {
                selected.forEach(key => cells.get(key)?.classList.remove('pe-selected'));
                selected = [];
            }
        }
        function positionEditor() {
            if (get('peEditor').hidden || !selected.length) return;
            const panel = get('peEditor');
            const rect = cells.get(selected[0]).getBoundingClientRect();
            const width = panel.offsetWidth || 308;
            const height = panel.offsetHeight;
            const x = Math.max(10, Math.min(rect.left - 25, innerWidth - width - 10));
            let y = rect.bottom + 9;
            if (y + height > innerHeight - 10) y = Math.max(10, rect.top - height - 9);
            if (height > innerHeight - 20) { y = 10; panel.style.maxHeight = `${innerHeight - 20}px`; panel.style.overflowY = 'auto'; }
            panel.style.left = `${x}px`; panel.style.top = `${y}px`;
        }
        function setPicker(picker, value) {
            picker.value = value;
            picker.unresolved = false;
            picker.input.value = value === undefined || value === null ? '' : (employees.get(value)?.name || `ID ${value}`);
            picker.input.placeholder = value === undefined ? 'Beragam — tidak diubah' : 'Cari nama / NIK...';
            picker.input.setAttribute('aria-expanded', 'false');
            picker.input.removeAttribute('aria-activedescendant');
            picker.options.hidden = true;
            editorTouched = true;
            positionEditor();
        }
        function options(picker, query = '') {
            pickerStates.forEach(p => {
                if (p !== picker) { p.options.hidden = true; p.input.setAttribute('aria-expanded', 'false'); }
            });
            picker.options.replaceChildren();
            const term = query.toLocaleLowerCase('id-ID').trim();
            const list = data.employees.filter(e => e.status === 'active' && e.role_key === data.currentRole && `${e.name} ${e.nik}`.toLocaleLowerCase('id-ID').includes(term));
            const available = [{ id: null, name: '— Kosongkan shift —', nik: `Tidak ada ${data.currentRoleLabel}`, role: '' }, ...list];
            picker.buttons = [];
            available.slice(0, 100).forEach((employee, index) => {
                const button = el('button', 'pe-option'); button.type = 'button'; button.id = `peOption-${picker.shift.id}-${index}`;
                button.setAttribute('role', 'option'); button.setAttribute('aria-selected', String(employee.id === picker.value));
                const label = el('span'); label.append(el('strong', '', employee.name), el('small', '', employee.nik));
                button.append(label, el('em', '', employee.role || ''));
                button.addEventListener('pointerdown', e => e.preventDefault());
                button.addEventListener('click', () => { setPicker(picker, employee.id); picker.input.focus({ preventScroll: true }); });
                picker.options.append(button); picker.buttons.push(button);
            });
            if (term && !list.length) picker.options.append(el('div', 'pe-no-results', `${data.currentRoleLabel} tidak ditemukan.`));
            if (available.length > 100) picker.options.append(el('div', 'pe-no-results', 'Ketik nama / NIK lebih lengkap untuk mempersempit hasil.'));
            picker.active = -1;
            picker.options.hidden = false;
            picker.input.setAttribute('aria-expanded', 'true');
            positionEditor();
        }
        function openEditor() {
            if (!selected.length || !editable || busy) return;
            const machine = machines.get(Number(selected[0].split('|')[0]));
            get('peEditorTitle').textContent = `${machine.code} · ${machine.name}`;
            const first = selected[0].split('|')[1], last = selected[selected.length - 1].split('|')[1];
            get('peEditorDates').textContent = first === last ? labelDate(first) : `${labelDate(first)} — ${labelDate(last)}`;
            get('peSelectionCount').textContent = `▦ ${selected.length} tanggal dipilih`;
            get('peEditorError').hidden = true;
            get('pePickers').replaceChildren();
            pickerStates = [];
            shifts.forEach((shift, index) => {
                const wrapper = el('div', 'pe-picker'); wrapper.dataset.index = String(index);
                const row = el('div', 'pe-picker-row');
                const label = el('label', 'pe-picker-label', `Shift ${index + 1}`); label.htmlFor = `peSearch-${shift.id}`;
                label.title = `${shift.name} · ${shift.start_time}–${shift.end_time}`;
                const wrap = el('div', 'pe-input-wrap');
                const input = el('input', 'pe-search'); input.id = label.htmlFor; input.type = 'text'; input.autocomplete = 'off';
                input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
                const clear = el('button', 'pe-picker-clear', '×'); clear.type = 'button'; clear.setAttribute('aria-label', `Kosongkan Shift ${index + 1}`);
                const list = el('div', 'pe-options'); list.id = `peOptions-${shift.id}`; list.setAttribute('role', 'listbox'); list.hidden = true;
                input.setAttribute('aria-controls', list.id);
                wrap.append(input, clear); row.append(label, wrap); wrapper.append(row, list); get('pePickers').append(wrapper);
                const values = selected.map(key => current(key)[shift.id]);
                const value = values.every(v => v === values[0]) ? values[0] : undefined;
                const picker = { shift, input, options: list, value, unresolved: false, buttons: [], active: -1 };
                pickerStates.push(picker); setPicker(picker, value);
                input.addEventListener('click', () => options(picker));
                input.addEventListener('input', () => { picker.unresolved = true; editorTouched = true; options(picker, input.value); });
                clear.addEventListener('click', () => setPicker(picker, null));
                input.addEventListener('keydown', event => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (list.hidden) options(picker, picker.unresolved ? input.value : '');
                        picker.active = (picker.active + (event.key === 'ArrowDown' ? 1 : -1) + picker.buttons.length) % picker.buttons.length;
                        picker.buttons.forEach((b, i) => b.classList.toggle('pe-active', i === picker.active));
                        const active = picker.buttons[picker.active];
                        input.setAttribute('aria-activedescendant', active.id); active.scrollIntoView({ block: 'nearest' });
                    } else if (event.key === 'Enter' && !list.hidden && picker.active >= 0) {
                        event.preventDefault(); picker.buttons[picker.active].click();
                    } else if (event.key === 'Escape' && !list.hidden) {
                        event.stopPropagation(); list.hidden = true; input.setAttribute('aria-expanded', 'false'); positionEditor();
                    }
                });
            });
            editorTouched = false;
            get('peEditor').hidden = false;
            positionEditor();
        }
        function editorError(text) {
            get('peEditorError').textContent = text; get('peEditorError').hidden = false; positionEditor();
        }
        function findConflict(proposed) {
            const seen = new Map();
            for (const key of cells.keys()) {
                const values = proposed.get(key) || current(key);
                const [machineId, date] = key.split('|');
                for (const shift of shifts) {
                    const id = values[shift.id];
                    if (id === null) continue;
                    const conflictKey = `${date}|${shift.id}|${id}`;
                    if (seen.has(conflictKey)) {
                        const other = seen.get(conflictKey);
                        return `${employees.get(id)?.name || `Karyawan ${id}`} sudah dipilih pada ${machines.get(Number(other)).name} dan ${machines.get(Number(machineId)).name}, ${labelDate(date)}, ${shift.name}.`;
                    }
                    seen.set(conflictKey, machineId);
                }
            }
            return '';
        }
        function apply() {
            if (pickerStates.some(p => p.unresolved)) { editorError('Pilih karyawan dari hasil pencarian. Teks yang diketik belum menjadi pilihan.'); return; }
            const proposed = new Map();
            selected.forEach(key => {
                const values = { ...current(key) };
                pickerStates.forEach(p => { if (p.value !== undefined) values[p.shift.id] = p.value; });
                proposed.set(key, values);
            });
            const conflict = findConflict(proposed);
            if (conflict) { editorError(conflict); return; }
            proposed.forEach((values, key) => {
                if (same(values, baseline.get(key))) drafts.delete(key);
                else drafts.set(key, { expected: drafts.get(key)?.expected || { ...baseline.get(key) }, shifts: values });
                paint(key);
            });
            persist(); summary(); closeEditor(true); message(`${proposed.size} sel diterapkan ke draft. Klik Save All untuk menyimpan.`);
        }
        function hitDay(x, y) {
            const node = document.elementFromPoint(x, y)?.closest('.pe-cell');
            return node && app.contains(node) ? Number(node.dataset.day) : null;
        }
        function updateDrag(event) {
            if (!drag) return;
            drag.x = event.clientX; drag.y = event.clientY;
            const day = hitDay(event.clientX, event.clientY);
            if (day !== null) selectRange(drag.machine, drag.start, day);
        }
        function autoScroll() {
            if (!drag) return;
            const scroll = get('peScroll'), rect = scroll.getBoundingClientRect();
            const stickyWidth = get('peHead').querySelector('.pe-machine-col').getBoundingClientRect().right;
            if (drag.y > rect.top + 42 && drag.y < rect.bottom) {
                let amount = 0;
                if (drag.x > rect.right - 36) amount = 12;
                else if (drag.x < stickyWidth + 22) amount = -12;
                if (amount) {
                    scroll.scrollLeft += amount;
                    const x = Math.min(rect.right - 5, Math.max(stickyWidth + 5, drag.x));
                    const day = hitDay(x, drag.y);
                    if (day !== null) selectRange(drag.machine, drag.start, day);
                }
            }
            frame = requestAnimationFrame(autoScroll);
        }
        get('peBody').addEventListener('pointerdown', event => {
            const cell = event.target.closest('.pe-cell');
            if (!cell || event.button !== 0 || !editable || busy) return;
            if (event.pointerType !== 'touch') event.preventDefault();
            closeEditor();
            const machine = Number(cell.dataset.machine), day = Number(cell.dataset.day);
            if (!event.shiftKey || !anchor || anchor.machine !== machine) anchor = { machine, day };
            drag = { machine, start: anchor.day, x: event.clientX, y: event.clientY };
            selectRange(machine, anchor.day, day);
            cell.focus({ preventScroll: true });
            frame = requestAnimationFrame(autoScroll);
        });
        document.addEventListener('pointermove', updateDrag);
        document.addEventListener('pointerup', () => {
            if (!drag) return;
            drag = null; cancelAnimationFrame(frame); openEditor();
        });
        document.addEventListener('pointercancel', () => { drag = null; cancelAnimationFrame(frame); closeEditor(true); });
        window.addEventListener('blur', () => { if (drag) { drag = null; cancelAnimationFrame(frame); closeEditor(true); } });
        get('peBody').addEventListener('keydown', event => {
            const cell = event.target.closest('.pe-cell'); if (!cell || !editable || busy) return;
            const machine = Number(cell.dataset.machine), day = Number(cell.dataset.day);
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                if (!selected.includes(cell.dataset.key)) { anchor = { machine, day }; selectRange(machine, day, day); }
                openEditor(); pickerStates[0]?.input.focus({ preventScroll: true });
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                const next = Math.max(0, Math.min(data.dates.length - 1, day + (event.key === 'ArrowRight' ? 1 : -1)));
                if (event.shiftKey) {
                    if (!anchor || anchor.machine !== machine) anchor = { machine, day };
                    selectRange(machine, anchor.day, next);
                } else { anchor = { machine, day: next }; selectRange(machine, next, next); }
                cells.get(keyOf(machine, data.dates[next].date)).focus();
            }
        });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closeEditor(true); });
        get('peScroll').addEventListener('scroll', () => { if (!drag) positionEditor(); });
        window.addEventListener('resize', positionEditor);
        window.addEventListener('scroll', positionEditor, { passive: true });
        get('peClose').addEventListener('click', () => closeEditor(true));
        get('peCancel').addEventListener('click', () => closeEditor(true));
        get('peClear').addEventListener('click', () => pickerStates.forEach(p => setPicker(p, null)));
        get('peApply').addEventListener('click', apply);
        get('peDiscard').addEventListener('click', () => {
            if (!confirm('Buang seluruh draft bulan ini? Data yang sudah disimpan tidak berubah.')) return;
            drafts.clear(); cells.forEach((_, key) => paint(key)); persist(); summary(); closeEditor(true); message('Draft dibuang.');
        });
        get('peSave').addEventListener('click', async () => {
            if (busy || !drafts.size) return;
            if (!get('peEditor').hidden && editorTouched) { editorError('Klik Apply sebelum Save All, atau Cancel untuk membatalkan pilihan panel.'); return; }
            const conflict = findConflict(new Map());
            if (conflict) { message(conflict, 'error'); return; }
            if (drafts.size > 1000) { message('Maksimal 1000 sel dalam satu Save All.', 'error'); return; }
            const batch = [...drafts].map(([key, item]) => {
                const [machine, date] = key.split('|');
                return { machine_id: Number(machine), date, expected: item.expected, shifts: item.shifts };
            });
            const body = new FormData(); body.append(csrf.name, csrf.hash); body.append('planning_role', data.currentRole); body.append('changes', JSON.stringify(batch));
            busy = true; app.classList.add('pe-saving'); closeEditor(true); summary(); get('peSave').textContent = 'Menyimpan…';
            const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 25000);
            try {
                const response = await fetch(data.saveUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal });
                if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Respons bukan JSON. Periksa login/CSRF/route; muat ulang halaman lalu coba lagi. Draft akan dipulihkan.');
                const result = await response.json();
                if (result.csrf?.name && result.csrf?.hash) csrf = result.csrf;
                if (!response.ok || !result.ok) throw new Error(result.message || 'Penyimpanan ditolak.');
                for (const cell of batch) {
                    const key = keyOf(cell.machine_id, cell.date);
                    if (!result.saved?.[key] || !same(normalize(result.saved[key]), cell.shifts)) throw new Error('Konfirmasi server tidak lengkap. Draft dipertahankan; coba Save All lagi.');
                }
                for (const cell of batch) {
                    const key = keyOf(cell.machine_id, cell.date);
                    baseline.set(key, normalize(result.saved[key])); drafts.delete(key); paint(key);
                }
                persist(); message(result.message, 'success');
            } catch (error) {
                message(error.name === 'AbortError' ? 'Respons terlambat. Draft dipertahankan; coba Save All lagi. Server akan memeriksa data yang sudah tersimpan.' : error.message, 'error');
            } finally {
                clearTimeout(timer); busy = false; app.classList.remove('pe-saving'); get('peSave').textContent = '▣ Save All'; summary();
            }
        });
        function navigate(url) {
            if ((drafts.size || editorTouched) && !confirm('Ada draft belum disimpan. Pindah bulan? Draft Apply tetap disimpan di tab ini; pilihan panel yang belum Apply akan dibatalkan.')) return;
            leaving = true;
            location.href = url;
        }
        get('pePrevious').href = data.previousUrl; get('peNext').href = data.nextUrl;
        ['pePrevious', 'peNext'].forEach(id => get(id).addEventListener('click', event => { event.preventDefault(); if (!busy) navigate(get(id).href); }));
        get('peMonth').value = data.month;
        get('peMonthTitle').textContent = new Date(`${data.month}-01T12:00:00`).toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
        get('peMonth').addEventListener('change', event => {
            if (!/^20\d{2}-(0[1-9]|1[0-2])$/.test(event.target.value)) return;
            if (!busy) navigate(`${data.pageUrl}?month=${event.target.value}&role=${encodeURIComponent(data.currentRole)}`);
            event.target.value = data.month;
        });
        document.querySelectorAll('[data-role-tab]').forEach(tab => {
            tab.addEventListener('click', event => {
                if (tab.dataset.roleTab === data.currentRole) { event.preventDefault(); return; }
                event.preventDefault();
                if (!busy) navigate(tab.href);
            });
        });
        window.addEventListener('beforeunload', event => { if (!leaving && (drafts.size || editorTouched || busy)) { event.preventDefault(); event.returnValue = ''; } });
        buildGrid();
        if (!data.canEdit) message('Mode lihat saja. Hanya admin yang dapat mengubah jadwal.');
        else if (shifts.length !== 3) message('Konfigurasikan tepat tiga shift beserta jam mulai dan selesai sebelum mengatur planning.', 'error');
        else if (restoreNote) message(restoreNote);
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
    else boot();
})();
