<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\IncomingRequest;

final class TableListing
{
    public static function paginate(
        BaseBuilder $builder,
        IncomingRequest $request,
        array $sortColumns,
        string $defaultSort,
        string $primaryKey,
        string $prefix = '',
        string $defaultDir = 'asc'
    ): array {
        if (!array_key_exists($defaultSort, $sortColumns)) {
            throw new \InvalidArgumentException('Default sort tidak terdaftar.');
        }

        $get = static fn (string $key) => $request->getGet($prefix . $key);
        $perPage = (int) ($get('per_page') ?: 10);
        if (!in_array($perPage, [10, 25, 50, 100], true)) {
            $perPage = 10;
        }

        $sort = (string) ($get('sort') ?: $defaultSort);
        if (!isset($sortColumns[$sort])) {
            $sort = $defaultSort;
        }
        $dir = strtolower((string) ($get('dir') ?: $defaultDir));
        $dir = $dir === 'desc' ? 'desc' : 'asc';

        $total = (clone $builder)->countAllResults();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($lastPage, max(1, (int) ($get('page') ?: 1)));

        $query = (clone $builder)->orderBy($sortColumns[$sort], strtoupper($dir), false);
        if ($sortColumns[$sort] !== $primaryKey) {
            $query->orderBy($primaryKey, 'ASC');
        }

        return [
            'rows' => $query->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray(),
            'total' => $total,
            'page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'sort' => $sort,
            'dir' => $dir,
            'prefix' => $prefix,
        ];
    }
}
