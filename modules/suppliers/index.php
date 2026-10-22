<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/SupplierApiClient.php';
require_permission('suppliers.view');

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$rows = [];
$error = null;
$pg = ['page' => 1, 'pages' => 1, 'per' => 15, 'total' => 0, 'offset' => 0];

$client = SupplierApiClient::load();
if (!$client) {
    $error = 'The Supplier API is not configured. Copy config/api_client.sample.php to config/api_client.php and set the API key.';
} else {
    $r = $client->request('GET', 'suppliers?' . http_build_query(['q' => $q, 'page' => $page, 'per_page' => 15]), null, null, false);
    if ($r['ok']) {
        $rows = $r['data']['data'] ?? [];
        $meta = $r['data']['meta'] ?? [];
        $total = (int)($meta['total'] ?? count($rows));
        $pages = max(1, (int)ceil($total / 15));
        $pg = ['page' => min($page, $pages), 'pages' => $pages, 'per' => 15, 'total' => $total, 'offset' => 0];
    } else {
        $error = $r['error'];
    }
}
// local products that reference each supplier (IDs only)
$linked = [];
if ($rows) {
    $ids = array_map(fn($s) => (int)$s['id'], $rows);
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (db_all("SELECT external_supplier_id AS sid, COUNT(*) AS n FROM products WHERE status <> 'archived' AND external_supplier_id IN ($in) GROUP BY external_supplier_id", $ids) as $l) {
        $linked[(int)$l['sid']] = (int)$l['n'];
    }
}
$api = integration_status();

$pageTitle = 'Suppliers';
$activeMenu = 'suppliers';
require APP_ROOT . '/includes/layout_header.php';
?>
<?php if ($error): ?>
<div class="alert alert-warning"><i class="bi bi-plug"></i> <strong>Supplier Management System unavailable.</strong> <?= e($error) ?>
  <div class="small mt-1"><?= e($api['detail']) ?> Inventory operations that do not need supplier data continue to work.</div></div>
<?php endif; ?>
<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search suppliers"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Search</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
  <thead><tr><th>Code</th><th>Supplier</th><th>Contact</th><th>Telephone</th><th>Email</th><th class="text-center">Linked products</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $s): $sid = (int)$s['id']; ?>
    <tr><td><?= e($s['supplier_code'] ?? '') ?></td><td><a href="view.php?id=<?= $sid ?>"><?= e($s['business_name'] ?? '') ?></a></td>
      <td><?= e($s['contact_person'] ?? '') ?></td><td><?= e($s['telephone'] ?? '') ?></td><td><?= e($s['email'] ?? '') ?></td>
      <td class="text-center"><?= $linked[$sid] ?? 0 ?></td><td><?= status_badge((string)($s['status'] ?? 'active')) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows && !$error): ?><tr><td colspan="7" class="text-muted p-3">No suppliers found.</td></tr><?php endif; ?>
  <?php if ($error): ?><tr><td colspan="7" class="text-muted p-3">Supplier list not available right now.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<p class="small text-muted mt-2">Supplier records are owned by the Supplier Management System and are displayed live; no copy is stored here.</p>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
