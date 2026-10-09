<?php
$transfers = $transfers ?? [];
$isAdmin = session()->get('role') === 'admin';
?>
<?php if ($transfers): ?>
<div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
    <?php foreach ($transfers as $item): ?>
        <?php
        $resource = (string) ($item['resource'] ?? '');
        $label = (string) ($item['label'] ?? ucfirst($resource));
        $importable = (bool) ($item['import'] ?? true);
        $modalId = 'import' . preg_replace('/[^A-Za-z0-9]/', '', ucwords(str_replace('-', ' ', $resource)));
        $template = (string) ($item['template'] ?? ($resource . '_import.xlsx'));
        ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('master-data/export/' . $resource), 'attr') ?>">
            <i class="bi bi-download me-1"></i>Export <?= esc($label) ?>
        </a>
        <?php if ($isAdmin && $importable): ?>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#<?= esc($modalId, 'attr') ?>">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Import <?= esc($label) ?>
            </button>

            <div class="modal fade" id="<?= esc($modalId, 'attr') ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Import <?= esc($label) ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="alert alert-light border small">
                                Import bersifat <strong>insert-only</strong> dan satu file diproses dalam satu transaksi. Jika satu baris gagal, seluruh file dibatalkan.
                            </div>
                            <a class="btn btn-light border btn-sm mb-3" href="<?= esc(base_url('templates/import/' . $template), 'attr') ?>" download>
                                <i class="bi bi-file-earmark-arrow-down me-1"></i>Download Template
                            </a>
                            <input type="file" class="form-control" accept=".xlsx,.xls,.csv" data-master-import-file>
                            <div class="form-text">Maksimal 2.000 baris. Gunakan sheet pertama / sheet <strong>Import</strong>.</div>
                            <div class="small mt-3" data-master-import-status></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" data-master-import-run data-resource="<?= esc($resource, 'attr') ?>">
                                <i class="bi bi-upload me-1"></i>Import Data
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>
