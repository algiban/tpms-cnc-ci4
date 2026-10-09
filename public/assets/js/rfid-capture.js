(() => {
    const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

    async function getJson(url) {
        const response = await fetch(url, {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok || body.ok === false) {
            throw new Error(body.message || `HTTP ${response.status}`);
        }
        return body;
    }

    async function readRfid(button) {
        if (button.dataset.rfidBusy === '1') return;

        const targetSelector = button.dataset.rfidTarget;
        const infoSelector = button.dataset.rfidInfo;
        const purpose = button.dataset.rfidPurpose || 'generic';
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        const info = infoSelector ? document.querySelector(infoSelector) : null;
        if (!target) return;

        const base = window.TPMS_RFID_CAPTURE?.latestUrl || '/admin/rfid/latest';
        const original = button.innerHTML;
        button.dataset.rfidBusy = '1';
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Waiting RFID';
        if (info) info.textContent = 'Silakan scan RFID pada TPMS/ESP32...';

        try {
            const baselineUrl = `${base}?baseline=1&purpose=${encodeURIComponent(purpose)}&_=${Date.now()}`;
            const baseline = await getJson(baselineUrl);
            const afterId = Number(baseline?.data?.latest_id || 0);
            const deadline = Date.now() + 30000;

            while (Date.now() < deadline) {
                await sleep(700);
                const url = `${base}?after_id=${afterId}&purpose=${encodeURIComponent(purpose)}&_=${Date.now()}`;
                const result = await getJson(url);
                const scan = result?.data;
                if (scan?.id && scan?.uid) {
                    target.value = scan.uid;
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                    if (info) info.textContent = `RFID terbaca: ${scan.uid} · ${scan.mac_address || 'TPMS'}`;
                    return;
                }
            }
            throw new Error('Timeout 30 detik. Klik Baca RFID lalu scan kembali.');
        } catch (error) {
            if (info) info.textContent = error.message || 'Gagal membaca RFID.';
            alert(error.message || 'Gagal membaca RFID.');
        } finally {
            button.dataset.rfidBusy = '0';
            button.disabled = false;
            button.innerHTML = original;
        }
    }

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-rfid-read]');
        if (!button) return;
        event.preventDefault();
        readRfid(button);
    });
})();
