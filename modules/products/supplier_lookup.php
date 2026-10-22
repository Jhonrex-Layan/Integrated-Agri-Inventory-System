<?php
// JSON proxy for the product form. The browser talks only to this local script; the API key stays on the server.
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $a, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}
if (!current_user()) {
    out(['ok' => false, 'error' => 'Session expired. Sign in again.'], 401);
}
if (!can('products.manage')) {
    out(['ok' => false, 'error' => 'Not permitted.'], 403);
}
$client = SupplierApiClient::load();
if (!$client) {
    out(['ok' => false, 'error' => 'The Supplier API is not configured (config/api_client.php).']);
}
$type = (string)($_GET['type'] ?? '');
if ($type === 'suppliers') {
    $r = $client->request('GET', 'suppliers?status=active&per_page=200', null, null, false);
    if (!$r['ok']) {
        out(['ok' => false, 'error' => 'Supplier system unavailable: ' . $r['error']]);
    }
    $rows = [];
    foreach (($r['data']['data'] ?? []) as $s) {
        $rows[] = ['id' => (int)$s['id'], 'supplier_code' => (string)($s['supplier_code'] ?? ''), 'business_name' => (string)($s['business_name'] ?? '')];
    }
    out(['ok' => true, 'data' => $rows]);
}
if ($type === 'products') {
    $sid = (int)($_GET['supplier_id'] ?? 0);
    if ($sid <= 0) {
        out(['ok' => false, 'error' => 'Missing supplier.'], 400);
    }
    $r = $client->request('GET', 'suppliers/' . $sid . '/products', null, null, false);
    if (!$r['ok']) {
        out(['ok' => false, 'error' => 'Supplier system unavailable: ' . $r['error']]);
    }
    $rows = [];
    foreach (($r['data']['data'] ?? []) as $s) {
        $rows[] = ['id' => (int)$s['id'], 'supplier_sku' => (string)($s['supplier_sku'] ?? ''), 'product_name' => (string)($s['product_name'] ?? ''), 'unit' => (string)($s['unit'] ?? '')];
    }
    out(['ok' => true, 'data' => $rows]);
}
out(['ok' => false, 'error' => 'Unknown lookup.'], 400);
