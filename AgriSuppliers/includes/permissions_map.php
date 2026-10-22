<?php
// Server-side permission map: permission => roles allowed. Every protected page and action checks this.
$all = ['administrator', 'supplier_staff', 'approving_officer'];
return [
    'dashboard.view'    => $all,
    'suppliers.view'    => $all,
    'suppliers.manage'  => ['administrator', 'supplier_staff'],
    'catalog.view'      => $all,
    'catalog.manage'    => ['administrator', 'supplier_staff'],
    'catalog.approve'   => ['administrator', 'approving_officer'],
    'po.view'           => $all,
    'po.create'         => ['administrator', 'supplier_staff'],
    'po.approve'        => ['administrator', 'approving_officer'],
    'deliveries.view'   => $all,
    'deliveries.manage' => ['administrator', 'supplier_staff'],
    'performance.view'  => $all,
    'feedback.record'   => $all,
    'refunds.view'      => $all,
    'refunds.manage'    => ['administrator', 'supplier_staff', 'approving_officer'],
    'refunds.decide'    => ['administrator', 'approving_officer'],
    'reports.view'      => $all,
    'users.manage'      => ['administrator'],
    'api.manage'        => ['administrator'],
    'audit.view'        => ['administrator'],
];
