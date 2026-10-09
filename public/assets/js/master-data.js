document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const backdrop = document.getElementById('sidebarBackdrop');

    const closeSidebar = () => {
        sidebar?.classList.remove('show');
        backdrop?.classList.remove('show');
    };

    toggle?.addEventListener('click', () => {
        sidebar?.classList.toggle('show');
        backdrop?.classList.toggle('show');
    });

    backdrop?.addEventListener('click', closeSidebar);

    const clock = document.getElementById('liveClock');
    if (clock) {
        setInterval(() => {
            const now = new Date();
            clock.textContent = now.toLocaleString('en-GB', {
                day: '2-digit',
                month: 'short',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).replace(',', '');
        }, 1000);
    }

    document.querySelectorAll('[data-filter-table]').forEach(input => {
        const targetSelector = input.dataset.filterTable;
        const table = document.querySelector(targetSelector);
        if (!table) return;

        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            table.querySelectorAll('tbody tr').forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    });

    document.querySelectorAll('[data-fill-modal]').forEach(button => {
        button.addEventListener('click', () => {
            const target = document.querySelector(button.dataset.bsTarget);
            if (!target) return;

            Object.entries(button.dataset).forEach(([key, value]) => {
                if (!key.startsWith('field')) return;
                const fieldName = key.replace('field', '').replace(/^./, c => c.toLowerCase());
                const el = target.querySelector(`[name="${fieldName}"], [data-field="${fieldName}"]`);
                if (!el) return;
                if ('value' in el) el.value = value;
                else el.textContent = value;
            });
        });
    });
});
