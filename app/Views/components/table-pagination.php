<?php
$query = service('request')->getGet() ?? [];
$prefix = $pagination['prefix'] ?? '';
$page = $pagination['page'];
$last = $pagination['last_page'];
$total = $pagination['total'];
$perPage = $pagination['per_page'];
$url = static function (int $p) use ($query, $prefix): string {
    $query[$prefix . 'page'] = $p;
    return current_url() . '?' . http_build_query($query);
};
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-top">
    <form method="get" class="d-flex align-items-center gap-2">
        <?php foreach ($query as $key => $value): ?>
            <?php if ($key !== $prefix . 'page' && $key !== $prefix . 'per_page' && is_scalar($value)): ?>
                <input type="hidden" name="<?= esc($key, 'attr') ?>" value="<?= esc((string) $value, 'attr') ?>">
            <?php endif ?>
        <?php endforeach ?>
        <span class="small text-secondary">Tampilkan</span>
        <select name="<?= esc($prefix . 'per_page', 'attr') ?>" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ([10, 25, 50, 100] as $n): ?>
                <option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach ?>
        </select>
        <span class="small text-secondary">
            <?= $total ? (($page - 1) * $perPage + 1) : 0 ?>–<?= min($page * $perPage, $total) ?> dari <?= number_format($total) ?>
        </span>
    </form>
    <nav aria-label="Pagination">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($url(max(1, $page - 1)), 'attr') ?>">Previous</a></li>
            <?php for ($p = max(1, $page - 2); $p <= min($last, $page + 2); ++$p): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= esc($url($p), 'attr') ?>"><?= $p ?></a></li>
            <?php endfor ?>
            <li class="page-item <?= $page >= $last ? 'disabled' : '' ?>"><a class="page-link" href="<?= esc($url(min($last, $page + 1)), 'attr') ?>">Next</a></li>
        </ul>
    </nav>
</div>
