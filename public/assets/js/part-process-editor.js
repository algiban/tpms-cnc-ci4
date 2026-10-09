document.addEventListener('DOMContentLoaded', () => {
    const cfg = window.TPMS_PART_EDITOR;
    const form = document.getElementById('partForm');
    const host = document.getElementById('processEditor');
    const type = document.getElementById('processType');
    const shifts = document.getElementById('shiftsPerDay');
    const grind = document.getElementById('nextGrinding');
    let draft = {};

    function remember() {
        host.querySelectorAll('[data-process]').forEach((section) => {
            const no = section.dataset.process;
            draft[no] = {
                machine_time_target_ms: Number(section.querySelector('[data-machine]').value) * 1000,
                loading_time_target_ms: Number(section.querySelector('[data-loading]').value) * 1000,
                requirements: Array.from(section.querySelectorAll('[data-requirement]')).map((row) => ({
                    tool_type_id: row.querySelector('[data-tool-type]').value,
                    position: row.querySelector('[data-position]').value,
                    set_lifetime: row.querySelector('[data-lifetime]').value,
                })),
            };
        });
    }

    function reindex(list, no) {
        Array.from(list.children).forEach((row, index) => {
            row.querySelector('[data-tool-type]').name = `process_requirements[${no}][${index}][tool_type_id]`;
            row.querySelector('[data-position]').name = `process_requirements[${no}][${index}][position]`;
            row.querySelector('[data-lifetime]').name = `process_requirements[${no}][${index}][set_lifetime]`;
        });
        refreshToolTypeOptions(list);
    }

    // Duplikat Tool Type hanya dilarang dalam process/list yang sama.
    // Process berbeda memiliki list berbeda, jadi TYPE yang sama tetap boleh dipakai.
    function refreshToolTypeOptions(list) {
        const selects = Array.from(list.querySelectorAll('[data-tool-type]'));
        const selected = selects.map((select) => select.value).filter(Boolean);
        selects.forEach((select) => {
            Array.from(select.options).forEach((option) => {
                if (!option.value) return;
                option.disabled = option.value !== select.value && selected.includes(option.value);
            });
        });
    }

    function requirement(list, no, requirement = {}) {
        const row = document.createElement('div');
        row.className = 'row g-2 align-items-end mb-2';
        row.dataset.requirement = '';

        const typeCol = document.createElement('div');
        typeCol.className = 'col-md-4';
        const typeLabel = document.createElement('label');
        typeLabel.className = 'form-label small mb-1';
        typeLabel.textContent = 'Tool Type';
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm';
        select.dataset.toolType = '';
        select.required = true;
        select.add(new Option('Select Tool Type', ''));
        cfg.toolTypes.forEach((toolType) => {
            const label = toolType.name ? `${toolType.code} · ${toolType.name}` : toolType.code;
            select.add(new Option(label, toolType.id));
        });
        select.value = requirement.tool_type_id || '';
        select.addEventListener('change', () => refreshToolTypeOptions(list));
        typeCol.append(typeLabel, select);

        const positionCol = document.createElement('div');
        positionCol.className = 'col-md-3';
        const positionLabel = document.createElement('label');
        positionLabel.className = 'form-label small mb-1';
        positionLabel.textContent = 'Machine Position';
        const position = document.createElement('input');
        position.className = 'form-control form-control-sm text-uppercase';
        position.type = 'text';
        position.maxLength = 30;
        position.required = true;
        position.dataset.position = '';
        position.placeholder = 'T01';
        position.value = requirement.position || '';
        position.addEventListener('input', () => {
            const start = position.selectionStart;
            position.value = position.value.toUpperCase();
            if (start !== null) position.setSelectionRange(start, start);
        });
        positionCol.append(positionLabel, position);

        const lifetimeCol = document.createElement('div');
        lifetimeCol.className = 'col-md-3';
        const lifetimeLabel = document.createElement('label');
        lifetimeLabel.className = 'form-label small mb-1';
        lifetimeLabel.textContent = 'Set Lifetime';
        const lifetime = document.createElement('input');
        lifetime.className = 'form-control form-control-sm';
        lifetime.type = 'number';
        lifetime.min = 1;
        lifetime.required = true;
        lifetime.dataset.lifetime = '';
        lifetime.placeholder = '1000';
        lifetime.value = requirement.set_lifetime || '';
        lifetimeCol.append(lifetimeLabel, lifetime);

        const actionCol = document.createElement('div');
        actionCol.className = 'col-md-2';
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-outline-danger btn-sm w-100';
        remove.innerHTML = '<i class="bi bi-trash me-1"></i>Remove';
        remove.addEventListener('click', () => {
            row.remove();
            reindex(list, no);
        });
        actionCol.append(remove);

        row.append(typeCol, positionCol, lifetimeCol, actionCol);
        list.append(row);
        reindex(list, no);
    }

    function render(save = true) {
        if (save) remember();
        host.replaceChildren();

        const isNextGrindingOnly = type.value === 'next_grinding';
        const match = type.value.match(/^process_(\d)/);
        const count = match ? Number(match[1]) : 1;
        document.getElementById('grindingWrap').hidden = count === 1;

        for (let no = 1; no <= count; no++) {
            const old = draft[no] || {};
            const section = document.createElement('section');
            section.dataset.process = no;
            section.className = 'border rounded p-3 mb-3';

            const isGrinding = isNextGrindingOnly || (grind.checked && no === count);
            const title = document.createElement('h3');
            title.className = 'fs-6 mb-3';
            title.textContent = isGrinding
                ? 'Next Grinding'
                : (type.value.startsWith('single') ? 'Single Process' : `Process ${no}`);

            const timingRow = document.createElement('div');
            timingRow.className = 'row g-2 mb-3';
            [
                ['Machine Time', 'machine', 'process_machine_time_seconds', old.machine_time_target_ms],
                ['Loading Time', 'loading', 'process_loading_time_seconds', old.loading_time_target_ms],
            ].forEach(([label, key, name, value]) => {
                const col = document.createElement('div');
                col.className = 'col-md-4';
                const inputLabel = document.createElement('label');
                inputLabel.className = 'form-label small';
                inputLabel.textContent = `${label} (sec)`;
                const input = document.createElement('input');
                input.type = 'number';
                input.min = 0;
                input.max = 25200;
                input.step = '0.001';
                input.required = true;
                input.className = 'form-control form-control-sm';
                input.name = `${name}[${no}]`;
                input.dataset[key] = '';
                input.value = (value || 0) / 1000;
                input.addEventListener('input', updatePlan);
                col.append(inputLabel, input);
                timingRow.append(col);
            });

            const plan = document.createElement('div');
            plan.className = 'col-md-4 small align-self-end text-secondary';
            timingRow.append(plan);

            const requirementTitle = document.createElement('div');
            requirementTitle.className = 'd-flex justify-content-between align-items-center mb-2';
            requirementTitle.innerHTML = '<div><strong>Required Tool Types</strong><div class="small text-secondary">Dalam process yang sama Tool Type dan Position harus unik.</div></div>';

            const list = document.createElement('div');
            list.dataset.requirementList = '';
            (old.requirements || [{}]).forEach((row) => requirement(list, no, row));

            const add = document.createElement('button');
            add.type = 'button';
            add.className = 'btn btn-outline-secondary btn-sm';
            add.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add Required Tool Type';
            add.addEventListener('click', () => requirement(list, no));

            section.append(title, timingRow, requirementTitle, list, add);
            host.append(section);

            function updatePlan() {
                const cycleMs = Math.round(Number(section.querySelector('[data-machine]').value) * 1000)
                    + Math.round(Number(section.querySelector('[data-loading]').value) * 1000);
                const cycle = cycleMs / 1000;
                const qty = cycleMs > 0 ? Math.floor(25200000 / cycleMs) : 0;
                plan.textContent = `Cycle ${cycle.toFixed(3)}s · Plan ${qty}/shift · ${qty * Number(shifts.value)}/day`;
            }
            updatePlan();
        }
    }

    type.addEventListener('change', () => render());
    grind.addEventListener('change', () => render());
    shifts.addEventListener('change', () => render());

    function open(part) {
        form.reset();
        draft = {};
        form.action = cfg.base + (part ? `/${part.id}/update` : '');
        if (part) {
            ['part_number', 'name', 'customer_id', 'material_id', 'process_type', 'shifts_per_day'].forEach((key) => {
                form.elements[key].value = part[key] ?? '';
            });
            grind.checked = Boolean(Number(part.next_grinding));
            part.processes.forEach((process) => {
                draft[process.process_no] = process;
            });
        }
        render(false);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('partModal')).show();
    }

    document.getElementById('addPart').addEventListener('click', () => open(null));
    document.querySelectorAll('[data-edit-part]').forEach((button) => {
        button.addEventListener('click', () => open(cfg.parts.find((part) => Number(part.id) === Number(button.dataset.editPart))));
    });

    render(false);
});
