<?php
$sidebarGroups = [
    [
        'id'    => 'menuOperations',
        'label' => 'Operations',
        'icon'  => 'bi-bar-chart-line',
        'items' => [
            ['label' => 'Production',        'path' => 'production',         'icon' => 'bi-activity'],
            ['label' => 'Cycle Time Report', 'path'=>'reports/cycle-time','icon'=>'bi-graph-up'],
            ['label' => 'Machine Utility',   'path' => 'machine-utility',    'icon' => 'bi-bar-chart-steps'],
            ['label' => 'Planning Employee', 'path' => 'planning-employees', 'icon' => 'bi-calendar-week'],
        ],
    ],
    [
        'id'    => 'menuEquipment',
        'label' => 'Machines & Devices',
        'icon'  => 'bi-gear-wide-connected',
        'items' => [
            ['label' => 'Machines',           'path' => 'master-data/machines',           'icon' => 'bi-gear'],
            ['label' => 'TPMS Devices',       'path' => 'master-data/tpms',               'icon' => 'bi-router'],
            ['label' => 'Device Assignments', 'path' => 'master-data/device-assignments', 'icon' => 'bi-arrow-left-right'],
        ],
    ],
    [
        'id'    => 'menuProducts',
        'label' => 'Products & Customers',
        'icon'  => 'bi-box-seam',
        'items' => [
            ['label' => 'Customers', 'path' => 'master-data/customers', 'icon' => 'bi-buildings'],
            ['label' => 'Materials', 'path' => 'master-data/materials', 'icon' => 'bi-flask'],
            ['label' => 'Parts',     'path' => 'master-data/parts',     'icon' => 'bi-puzzle'],
        ],
    ],
    [
        'id'    => 'menuTooling',
        'label' => 'Tooling',
        'icon'  => 'bi-tools',
        'items' => [
            ['label' => 'Tool Types', 'path' => 'master-data/tool-types', 'icon' => 'bi-tags'],
            ['label' => 'Tools',      'path' => 'master-data/tools',      'icon' => 'bi-tools'],
        ],
    ],
    [
        'id'    => 'menuLogs',
        'label' => 'Logs',
        'icon'  => 'bi-journal-text',
        'items' => [
            ['label' => 'TPMS Logs',    'path' => 'logs/tpms',     'icon' => 'bi-router'],
            ['label' => 'Tools Logs',   'path' => 'logs/tools',    'icon' => 'bi-tools'],
            ['label' => 'Machine Logs', 'path' => 'logs/machines', 'icon' => 'bi-gear-wide-connected'],
        ],
    ],
];

$isSidebarActive = static fn(string $path): bool =>
url_is($path) || url_is($path . '/*');

$isAdmin = session()->get('role') === 'admin';
?>

<aside class="app-sidebar" id="appSidebar">
    <a
        class="sidebar-brand"
        href="<?= esc(site_url('dashboard'), 'attr') ?>"
        aria-label="TPMS Dashboard">
        <span class="brand-mark">
            <i class="bi bi-cpu" aria-hidden="true"></i>
        </span>
        <span>TPMS</span>
    </a>

    <nav class="sidebar-scroll" aria-label="Navigasi utama">
        <div class="sidebar-caption">OVERVIEW</div>

        <a
            class="sidebar-link <?= $isSidebarActive('dashboard') ? 'active' : '' ?>"
            href="<?= esc(site_url('dashboard'), 'attr') ?>"
            <?= $isSidebarActive('dashboard') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-grid-1x2" aria-hidden="true"></i>
            <span>Dashboard</span>
        </a>

        <a
            class="sidebar-link"
            href="<?= esc(site_url('monitoring'), 'attr') ?>"
            target="_blank"
            rel="noopener noreferrer">
            <i class="bi bi-display" aria-hidden="true"></i>
            <span>Monitoring</span>
            <i class="bi bi-box-arrow-up-right sidebar-external" aria-hidden="true"></i>
            <span class="visually-hidden">Buka di tab baru</span>
        </a>

        <div class="sidebar-caption mt-4">WORKSPACE</div>

        <div id="sidebarMenuGroups">
            <?php foreach ($sidebarGroups as $group): ?>
                <?php
                $groupActive = false;

                foreach ($group['items'] as $item) {
                    if ($isSidebarActive($item['path'])) {
                        $groupActive = true;
                        break;
                    }
                }
                ?>

                <div class="sidebar-group">
                    <button
                        type="button"
                        class="sidebar-link sidebar-group-toggle <?= $groupActive ? 'group-active' : 'collapsed' ?>"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= esc($group['id'], 'attr') ?>"
                        aria-expanded="<?= $groupActive ? 'true' : 'false' ?>"
                        aria-controls="<?= esc($group['id'], 'attr') ?>">
                        <i class="bi <?= esc($group['icon'], 'attr') ?>" aria-hidden="true"></i>
                        <span><?= esc($group['label']) ?></span>
                        <i class="bi bi-chevron-down sidebar-chevron" aria-hidden="true"></i>
                    </button>

                    <div
                        class="collapse <?= $groupActive ? 'show' : '' ?>"
                        id="<?= esc($group['id'], 'attr') ?>"
                        data-bs-parent="#sidebarMenuGroups">
                        <div class="sidebar-submenu">
                            <?php foreach ($group['items'] as $item): ?>
                                <?php $itemActive = $isSidebarActive($item['path']); ?>

                                <a
                                    class="sidebar-link sidebar-sublink <?= $itemActive ? 'active' : '' ?>"
                                    href="<?= esc(site_url($item['path']), 'attr') ?>"
                                    <?= $itemActive ? 'aria-current="page"' : '' ?>>
                                    <i class="bi <?= esc($item['icon'], 'attr') ?>" aria-hidden="true"></i>
                                    <span><?= esc($item['label']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sidebar-caption mt-4">PEOPLE</div>

        <a
            class="sidebar-link <?= $isSidebarActive('master-data/employees') ? 'active' : '' ?>"
            href="<?= esc(site_url('master-data/employees'), 'attr') ?>"
            <?= $isSidebarActive('master-data/employees') ? 'aria-current="page"' : '' ?>>
            <i class="bi bi-people" aria-hidden="true"></i>
            <span>Employees</span>
        </a>

        <?php if ($isAdmin): ?>
            <div class="sidebar-caption mt-4">ADMINISTRATION</div>

            <a
                class="sidebar-link <?= $isSidebarActive('rfid-capture') ? 'active' : '' ?>"
                href="<?= esc(site_url('rfid-capture'), 'attr') ?>"
                <?= $isSidebarActive('rfid-capture') ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-broadcast-pin" aria-hidden="true"></i>
                <span>RFID Capture</span>
            </a>

            <a
                class="sidebar-link <?= $isSidebarActive('users') ? 'active' : '' ?>"
                href="<?= esc(site_url('users'), 'attr') ?>"
                <?= $isSidebarActive('users') ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-person-gear" aria-hidden="true"></i>
                <span>Users</span>
            </a>
        <?php endif; ?>
    </nav>
</aside>

<style>
    #appSidebar .sidebar-link {
        display: flex;
        align-items: center;
        gap: 12px;
        min-height: 44px;
    }

    #appSidebar .sidebar-link>span:not(.visually-hidden) {
        flex: 1;
        min-width: 0;
    }

    #appSidebar .sidebar-group {
        margin-bottom: 4px;
    }

    #appSidebar .sidebar-group-toggle {
        width: 100%;
        border: 0;
        background: transparent;
        font-family: inherit;
        font-size: inherit;
        text-align: left;
        cursor: pointer;
    }

    #appSidebar .sidebar-group-toggle.group-active {
        color: var(--bs-primary, #4361ee);
        font-weight: 600;
    }

    #appSidebar .sidebar-chevron {
        flex: 0 0 auto;
        width: auto;
        margin-left: auto;
        font-size: 11px;
        transition: transform 180ms ease;
    }

    #appSidebar .sidebar-group-toggle[aria-expanded="true"] .sidebar-chevron {
        transform: rotate(180deg);
    }

    #appSidebar .sidebar-submenu {
        margin: 4px 0 10px 23px;
        padding: 2px 0 2px 12px;
        border-left: 1px solid rgba(128, 139, 160, 0.25);
    }

    #appSidebar .sidebar-sublink {
        min-height: 38px;
        margin: 3px 0;
        padding: 9px 10px;
        border-radius: 9px;
        font-size: 12px;
        line-height: 1.4;
    }

    #appSidebar .sidebar-sublink>i {
        flex: 0 0 16px;
        width: 16px;
        font-size: 14px;
        text-align: center;
    }

    #appSidebar .sidebar-external {
        width: auto;
        margin-left: auto;
        font-size: 11px;
        opacity: 0.6;
    }

    #appSidebar .sidebar-link:focus-visible {
        outline: 2px solid var(--bs-primary, #4361ee);
        outline-offset: 2px;
    }

    @media (prefers-reduced-motion: reduce) {
        #appSidebar .sidebar-chevron {
            transition: none;
        }
    }
</style>