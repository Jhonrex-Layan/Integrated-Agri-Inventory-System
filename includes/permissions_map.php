<?php
// Server-side permission map: permission => roles allowed. Every protected page and action checks this.
$all = ['administrator', 'inventory_staff', 'approving_officer'];
return [
    'dashboard.view'     => $all,
    'products.view'      => $all,
    'products.manage'    => ['administrator', 'inventory_staff'],
    'categories.manage'  => ['administrator', 'inventory_staff'],
    'inventory.view'     => $all,
    'inventory.record'   => ['administrator', 'inventory_staff'],
    'adjustments.review' => ['administrator', 'approving_officer'],
    'sales.view'         => $all,
    'sales.create'       => ['administrator', 'inventory_staff'],
    'sales.void'         => ['administrator', 'approving_officer'],
    'damages.view'       => ['administrator', 'inventory_staff', 'approving_officer'],
    'receiving.view'     => $all,
    'receiving.accept'   => ['administrator', 'inventory_staff'],
    'suppliers.view'     => $all,
    'reports.view'       => $all,
    'sync.view'          => ['administrator', 'inventory_staff'],
    'sync.check'         => ['administrator', 'inventory_staff'],
    'users.manage'       => ['administrator'],
    'settings.manage'    => ['administrator'],
    'audit.view'         => ['administrator'],
];
