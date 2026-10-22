<?php
// Sidebar items. An item is shown only if the user has the permission AND the page file exists.
return [
    'dashboard'  => ['label' => 'Dashboard',        'icon' => 'speedometer2', 'path' => 'modules/dashboard/index.php',        'perm' => 'dashboard.view'],
    'suppliers'  => ['label' => 'Suppliers',        'icon' => 'building',     'path' => 'modules/suppliers/index.php',        'perm' => 'suppliers.view', 'group' => 'Suppliers'],
    'catalog'    => ['label' => 'Product Catalog',  'icon' => 'boxes',        'path' => 'modules/catalog/index.php',          'perm' => 'catalog.view',   'group' => 'Suppliers'],
    'po'         => ['label' => 'Purchase Orders',  'icon' => 'file-earmark-text', 'path' => 'modules/purchase_orders/index.php', 'perm' => 'po.view',     'group' => 'Orders'],
    'deliveries' => ['label' => 'Deliveries',       'icon' => 'truck',        'path' => 'modules/deliveries/index.php',       'perm' => 'deliveries.view','group' => 'Orders'],
    'refunds'    => ['label' => 'Refund Requests',  'icon' => 'cash-coin',    'path' => 'modules/refunds/index.php',          'perm' => 'refunds.view',   'group' => 'Orders'],
    'performance'=> ['label' => 'Performance',      'icon' => 'graph-up',     'path' => 'modules/performance/index.php',      'perm' => 'performance.view','group' => 'Insights'],
    'reports'    => ['label' => 'Reports',          'icon' => 'bar-chart',    'path' => 'modules/reports/index.php',          'perm' => 'reports.view',   'group' => 'Insights'],
    'users'      => ['label' => 'Users',            'icon' => 'people',       'path' => 'modules/admin/users.php',            'perm' => 'users.manage',   'group' => 'Administration'],
    'api'        => ['label' => 'API Clients',      'icon' => 'key',          'path' => 'modules/admin/api_clients.php',      'perm' => 'api.manage',     'group' => 'Administration'],
    'audit'      => ['label' => 'Audit Logs',       'icon' => 'journal-text', 'path' => 'modules/admin/audit_logs.php',       'perm' => 'audit.view',     'group' => 'Administration'],
];
