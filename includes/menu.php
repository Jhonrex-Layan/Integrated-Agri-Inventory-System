<?php
// Sidebar items. An item is shown only if the user has the permission AND the page file exists.
return [
    'dashboard'  => ['label' => 'Dashboard',   'icon' => 'speedometer2', 'path' => 'modules/dashboard/index.php',  'perm' => 'dashboard.view'],
    'products'   => ['label' => 'Products',    'icon' => 'box-seam',     'path' => 'modules/products/index.php',   'perm' => 'products.view',   'group' => 'Catalog'],
    'categories' => ['label' => 'Categories',  'icon' => 'tags',         'path' => 'modules/categories/index.php', 'perm' => 'categories.manage','group' => 'Catalog'],
    'inventory'  => ['label' => 'Stock Movements', 'icon' => 'arrow-left-right', 'path' => 'modules/inventory/index.php', 'perm' => 'inventory.view', 'group' => 'Operations'],
    'damages'    => ['label' => 'Damaged & Refunds', 'icon' => 'clipboard2-pulse', 'path' => 'modules/inventory/damaged.php', 'perm' => 'damages.view', 'group' => 'Operations'],
    'receiving'  => ['label' => 'Receiving',   'icon' => 'truck',        'path' => 'modules/receiving/index.php',  'perm' => 'receiving.view',  'group' => 'Operations'],
    'sales'      => ['label' => 'Sales',       'icon' => 'cart-check',   'path' => 'modules/sales/index.php',      'perm' => 'sales.view',      'group' => 'Operations'],
    'suppliers'  => ['label' => 'Suppliers',   'icon' => 'building',     'path' => 'modules/suppliers/index.php',  'perm' => 'suppliers.view',  'group' => 'Integration'],
    'reports'    => ['label' => 'Reports',     'icon' => 'bar-chart',    'path' => 'modules/reports/index.php',    'perm' => 'reports.view',    'group' => 'Insights'],
    'sync'       => ['label' => 'Sync Logs',   'icon' => 'arrow-repeat', 'path' => 'modules/admin/sync_logs.php',  'perm' => 'sync.view',       'group' => 'Administration'],
    'users'      => ['label' => 'Users',       'icon' => 'people',       'path' => 'modules/admin/users.php',      'perm' => 'users.manage',    'group' => 'Administration'],
    'audit'      => ['label' => 'Audit Logs',  'icon' => 'journal-text', 'path' => 'modules/admin/audit_logs.php', 'perm' => 'audit.view',      'group' => 'Administration'],
];
