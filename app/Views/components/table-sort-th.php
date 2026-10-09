<?php
$query = service('request')->getGet() ?? [];
$prefix = $pagination['prefix'] ?? '';
$active = $pagination['sort'] === $key;
$query[$prefix . 'sort'] = $key;
$query[$prefix . 'dir'] = $active && $pagination['dir'] === 'asc' ? 'desc' : 'asc';
$query[$prefix . 'page'] = 1;
$url = current_url() . '?' . http_build_query($query);
$icon = !$active ? 'bi-arrow-down-up' : ($pagination['dir'] === 'asc' ? 'bi-arrow-up' : 'bi-arrow-down');
?>
<th>
    <a href="<?= esc($url, 'attr') ?>" class="text-decoration-none text-reset d-inline-flex gap-2 align-items-center">
        <?= esc($label) ?> <i class="bi <?= esc($icon, 'attr') ?>"></i>
    </a>
</th>
