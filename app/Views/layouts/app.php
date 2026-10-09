<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= csrf_hash() ?>">
    <title><?= esc($title ?? 'TPMS') ?> - TPMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= base_url('assets/css/master-data.css') ?>">
</head>
<body>
    <?= $this->include('components/sidebar') ?>

    <main class="app-main">
        <nav class="app-topbar">
            <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button">
                <i class="bi bi-list"></i>
            </button>

            <div class="ms-auto d-flex align-items-center gap-2 gap-md-3">
                <a href="<?= site_url('/monitoring') ?>" target="_blank" class="btn btn-light border d-none d-md-inline-flex align-items-center gap-2">
                    <i class="bi bi-display"></i>
                    Monitoring
                </a>

                <div class="topbar-time d-none d-md-flex">
                    <i class="bi bi-clock"></i>
                    <span id="liveClock"><?= date('d M Y, H:i:s') ?></span>
                </div>

                <div class="dropdown">
                    <button class="user-chip" data-bs-toggle="dropdown" type="button">
                        <span class="user-avatar"><i class="bi bi-person"></i></span>
                        <span class="d-none d-sm-block text-start">
                            <strong><?= esc(session()->get('username') ?? 'khanif-admin') ?></strong>
                            <small><?= ucfirst(esc(session()->get('role') ?? 'admin')) ?></small>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                        <li>
                            <form action="<?= site_url('/logout') ?>" method="post">
                                <?= csrf_field() ?>
                                <button class="dropdown-item text-danger" type="submit">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <div class="page-shell">
            <?php if (session()->getFlashdata('success')): ?>
                <div class="alert alert-success"><?= esc(session()->getFlashdata('success')) ?></div>
            <?php endif; ?>

            <?php if (session()->getFlashdata('error')): ?>
                <div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div>
            <?php endif; ?>

            <?= $this->renderSection('content') ?>
        </div>
    </main>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= base_url('assets/js/master-data.js') ?>"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <script>window.TPMS_MASTER_IMPORT_BASE = <?= json_encode(site_url('master-data/import')) ?>;</script>
    <script src="<?= base_url('assets/js/master-import.js') ?>"></script>
    <?php if (session()->get('role') === 'admin'): ?>
        <script>window.TPMS_RFID_CAPTURE = { latestUrl: <?= json_encode(site_url('admin/rfid/latest')) ?> };</script>
        <script src="<?= base_url('assets/js/rfid-capture.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
