<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
require_permission('sync.check');

if (!is_post()) {
    redirect('modules/dashboard/index.php');
}
csrf_verify();

$client = SupplierApiClient::load();
if ($client === null) {
    SupplierApiClient::record_status('not_configured', false);
    flash('warning', 'The Supplier API is not configured. Copy config/api_client.sample.php to config/api_client.php and set the API key.');
} else {
    $res = $client->ping();
    audit('api_check', 'integration', null, $res['ok'] ? 'connected' : (string)$res['error']);
    if ($res['ok']) {
        flash('success', 'Connected to the Supplier Management System.');
    } else {
        flash('danger', 'Connection failed: ' . $res['error'] . ' Local inventory operations are unaffected.');
    }
}
redirect('modules/dashboard/index.php');
