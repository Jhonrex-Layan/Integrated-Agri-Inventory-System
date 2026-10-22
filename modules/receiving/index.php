<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/integration_ops.php';
require_permission('receiving.view');

// 1) Pull confirmed deliveries from the Supplier system (live) and 2) retry acknowledgments that did not get through.
$incoming = [];
$apiError = null;
$retried = [];
$client = SupplierApiClient::load();
if (!$client) {
    $apiError = 'The Supplier API is not configured. Copy config/api_client.sample.php to config/api_client.php and set the API key.';
} else {
    $res = $client->incomingDeliveries(100);
    if ($res['ok']) {
        $known = [];
        foreach (db_all('SELECT external_delivery_id FROM stock_receipts') as $k) {
            $known[(int)$k['external_delivery_id']] = true;
        }
        foreach (($res['data']['data'] ?? []) as $d) {
            if (!isset($known[(int)$d['id']])) {
                $incoming[] = $d;
            }
        }
        setting_set('incoming_deliveries_count', (string)count($incoming));
        setting_set('incoming_checked_at', date('Y-m-d H:i:s'));
        if (can('receiving.accept')) {           // safe, idempotent retries of queued acknowledgments
            foreach (db_all("SELECT id FROM stock_receipts WHERE ack_status IN ('pending','failed') AND ack_attempts < 10 ORDER BY id LIMIT 5") as $q) {
                $retried[] = ack_receipt((int)$q['id']);
            }
        }
    } else {
        $apiError = $res['error'];
    }
}
$needAck = db_all("SELECT * FROM stock_receipts WHERE ack_status IN ('pending','failed') ORDER BY id DESC");
$pg = paginate((int)db_scalar('SELECT COUNT(*) FROM stock_receipts'), 15);
$history = db_all("SELECT r.*, u.full_name, (SELECT COALESCE(SUM(qty_accepted),0) FROM stock_receipt_items WHERE receipt_id = r.id) AS q_acc
                     FROM stock_receipts r JOIN users u ON u.id = r.received_by ORDER BY r.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$api = integration_status();

$pageTitle = 'Receiving';
$activeMenu = 'receiving';
require APP_ROOT . '/includes/layout_header.php';
?>
<?php if ($apiError): ?>
<div class="alert alert-warning"><i class="bi bi-plug"></i> <strong>Cannot load supplier deliveries.</strong> <?= e($apiError) ?>
  <div class="small mt-1"><?= e($api['detail']) ?> Stock already received stays valid; acknowledgments are queued and retried automatically.</div></div>
<?php endif; ?>
<?php foreach ($retried as $r): if ($r['ok']): ?><div class="alert alert-success py-2">A queued acknowledgment was delivered to the Supplier system.</div><?php endif; endforeach; ?>

<div class="card mb-3"><div class="card-header">Confirmed deliveries waiting for verification <span class="badge text-bg-warning float-end"><?= count($incoming) ?></span></div>
  <div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Delivery</th><th>Purchase order</th><th>Supplier</th><th>Arrived</th><th class="text-center">Lines</th><th></th></tr></thead><tbody>
    <?php foreach ($incoming as $d): ?>
      <tr><td><?= e($d['delivery_ref']) ?></td><td><?= e($d['po_number']) ?></td><td><?= e($d['supplier_name']) ?></td><td><?= e(fmt_dt($d['arrival_date'], 'M j, Y')) ?></td>
        <td class="text-center"><?= (int)$d['item_count'] ?></td>
        <td class="text-end"><?php if (can('receiving.accept')): ?><a class="btn btn-sm btn-success" href="accept.php?delivery_id=<?= (int)$d['id'] ?>"><i class="bi bi-clipboard-check"></i> Verify &amp; accept</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$incoming): ?><tr><td colspan="6" class="text-muted p-3"><?= $apiError ? 'Unavailable while the Supplier system cannot be reached.' : 'No confirmed supplier deliveries are waiting.' ?></td></tr><?php endif; ?>
    </tbody></table></div></div>

<?php if ($needAck): ?>
<div class="card border-warning mb-3"><div class="card-header">Acknowledgments not yet delivered to the Supplier system</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Receipt</th><th>Delivery</th><th>Status</th><th>Attempts</th><th>Last error</th><th></th></tr></thead><tbody>
  <?php foreach ($needAck as $n): ?>
    <tr><td><a href="view.php?id=<?= (int)$n['id'] ?>"><?= e($n['receipt_no']) ?></a></td><td><?= e($n['external_delivery_ref']) ?></td><td><?= status_badge($n['ack_status']) ?></td>
      <td><?= (int)$n['ack_attempts'] ?></td><td class="small"><?= e($n['ack_last_error'] ?? '') ?></td>
      <td class="text-end"><?php if (can('receiving.accept')): ?><form method="post" action="retry.php"><?= csrf_field() ?><input type="hidden" name="receipt_id" value="<?= (int)$n['id'] ?>"><button class="btn btn-sm btn-outline-warning">Retry now</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>

<div class="card"><div class="card-header">Receipt history</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Receipt</th><th>Delivery / PO</th><th>Received</th><th class="text-end">Accepted qty</th><th>Result</th><th>Supplier ack</th><th>By</th></tr></thead><tbody>
  <?php foreach ($history as $h): ?>
    <tr><td><a href="view.php?id=<?= (int)$h['id'] ?>"><?= e($h['receipt_no']) ?></a></td><td><?= e($h['external_delivery_ref']) ?> <small class="text-muted"><?= e($h['external_po_number']) ?></small></td>
      <td><?= e(fmt_dt($h['received_at'])) ?></td><td class="text-end"><?= e(fmt_qty($h['q_acc'])) ?></td><td><?= status_badge($h['status']) ?></td>
      <td><?= status_badge($h['ack_status'] === 'sent' ? 'acknowledged' : $h['ack_status']) ?></td><td><?= e($h['full_name']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$history): ?><tr><td colspan="7" class="text-muted p-3">No deliveries have been received yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
