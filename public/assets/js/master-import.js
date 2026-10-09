(() => {
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    async function fileToRows(file) {
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (ext === 'csv') {
            const text = await file.text();
            const wb = XLSX.read(text, { type: 'string' });
            return XLSX.utils.sheet_to_json(wb.Sheets[wb.SheetNames[0]], { defval: '', raw: false });
        }
        const buffer = await file.arrayBuffer();
        const wb = XLSX.read(buffer, { type: 'array', cellDates: false });
        const name = wb.SheetNames.includes('Import') ? 'Import' : wb.SheetNames[0];
        return XLSX.utils.sheet_to_json(wb.Sheets[name], { defval: '', raw: false });
    }

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-master-import-run]');
        if (!button) return;
        const modal = button.closest('.modal');
        const input = modal?.querySelector('[data-master-import-file]');
        const status = modal?.querySelector('[data-master-import-status]');
        const resource = button.dataset.resource;
        const file = input?.files?.[0];
        if (!file) {
            if (status) status.innerHTML = '<span class="text-danger">Pilih file terlebih dahulu.</span>';
            return;
        }

        const original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing';
        if (status) status.innerHTML = '<span class="text-secondary">Membaca file...</span>';

        try {
            if (typeof XLSX === 'undefined') throw new Error('Excel reader belum tersedia. Pastikan browser dapat memuat SheetJS.');
            const rows = await fileToRows(file);
            if (!rows.length) throw new Error('File tidak memiliki data.');
            if (rows.length > 2000) throw new Error('Maksimal 2.000 baris per file.');
            if (status) status.innerHTML = `<span class="text-secondary">Mengirim ${rows.length} baris...</span>`;

            const response = await fetch(`${window.TPMS_MASTER_IMPORT_BASE}/${encodeURIComponent(resource)}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ rows }),
            });
            const body = await response.json().catch(() => ({}));
            if (!response.ok || body.ok === false) throw new Error(body.message || `HTTP ${response.status}`);
            if (status) status.innerHTML = `<span class="text-success">${body.message || 'Import berhasil.'}</span>`;
            setTimeout(() => location.reload(), 900);
        } catch (error) {
            if (status) status.innerHTML = `<span class="text-danger">${String(error.message || error)}</span>`;
        } finally {
            button.disabled = false;
            button.innerHTML = original;
        }
    });
})();
