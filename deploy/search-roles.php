<?php
declare(strict_types=1);

// Action-level policy, intentionally no built-in all_access/read/write groups.
// Runtime and reconciliation follow the explicitly selected live index only.
$index = getenv('OPENSEARCH_PRODUCTS_INDEX') ?: 'shopkmp-products';
if (!preg_match('/^shopkmp-products(?:-[a-z0-9][a-z0-9-]{0,79})?$/D', $index)) {
    throw new RuntimeException('Invalid product index name');
}
$documents = ['indices:admin/get', 'indices:data/read/search*',
    // OpenSearch 2.19 executes even a single document PUT via its bulk transport.
    // Index-scoped bulk transport does not grant foreign-index or mapping rights.
    'indices:data/write/index', 'indices:data/write/delete', 'indices:data/write/bulk*'];
$role = static fn(array $patterns, array $actions, array $cluster = ['indices:data/write/bulk']): array => [
    'cluster_permissions' => $cluster,
    'index_permissions' => [['index_patterns' => $patterns, 'fls' => [], 'masked_fields' => [], 'allowed_actions' => $actions]],
    'tenant_permissions' => [],
];
return [
    'runtime' => $role([$index], $documents),
    'reconcile' => $role([$index], [...$documents, 'indices:admin/refresh*']),
    'indexer' => $role(['shopkmp-products', 'shopkmp-products-*'],
        [...$documents, 'indices:admin/create', 'indices:admin/mapping/put', 'indices:admin/refresh*'],
        ['indices:data/write/bulk', 'indices:data/read/scroll', 'indices:data/read/scroll/clear']),
];
