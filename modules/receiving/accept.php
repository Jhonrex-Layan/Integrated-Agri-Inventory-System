<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/integration_ops.php';
require_permission('receiving.accept');

$did = (int)($_GET['delivery_id'] ?? $_POST['delivery_id'] ?? 0);
$local = db_row('SELECT id FROM stock_receipts WHERE external_delivery_id = ?', [$did]);
if ($local) {
    flash('info', 'This delivery has already been received.');
    redirect('modules/receiving/view.php?id=' . (int)$local['id']);
}
$client = SupplierApiClient::load();
$error = null;
$d = null;
if (!$client) {
    $error = 'The Supplier API is not configured.';
} else {
    $res = $client->delivery($did);
    if (!$res['ok']) {
        $error = $res['error'];
    } else {
        $d = $res['data']['data'] ?? null;
        if (!$d || ($d['status'] ?? '') !== 'confirmed') {
            $error = 'This delivery is not available for receiving (status: ' . ($d['status'] ?? 'unknown') . ').';
            $d = null;
        }
    }
}
$errors = [];
$products = db_all("SELECT id, product_code, name, unit, external_supplier_product_id FROM products WHERE status <> 'archived' ORDER BY name");
$pre = [];
if ($d) {
    foreach ($d['items'] as $it) {
        foreach ($products as $p) {
            if ($p['external_supplier_product_id'] !== null && (int)$p['external_supplier_product_id'] === (int)$it['supplier_product_id']) {
                $pre[(int)$it['delivery_item_id']] = (int)$p['id'];
                break;
            }
        }
    }
}
$post = ['product' => [], 'accept' => [], 'notes' => '', 'update_cost' => false];

if ($d && is_post()) {
    csrf_verify();
    $rejectAll = isset($_POST['reject_all']);
    $post['product'] = (array)($_POST['product'] ?? []);
    $post['accept'] = (array)($_POST['accept'] ?? []);
    $post['notes'] = trim((string)($_POST['notes'] ?? ''));
    $post['update_cost'] = isset($_POST['update_cost']);
    $lines = [];
    $acceptedTotal = 0.0;
    foreach ($d['items'] as $it) {                       // quantities are validated against the live supplier data, not the form
        $iid = (int)$it['delivery_item_id'];
        $max = (float)$it['qty_delivered'] - (float)$it['qty_rejected'];
        $qty = 0.0;
        $pid = (int)($post['product'][$iid] ?? 0);
        if (!$rejectAll) {
            $raw = trim((string)($post['accept'][$iid] ?? '0')) ?: '0';
            $parsed = parse_decimal($raw);
            if ($parsed === null) {
                $errors['lines'] = 'Accepted quantities must be numbers (0 or more).';
                continue;
            }
            $qty = (float)$parsed;
            if ($qty > $max + 0.0001) {
                $errors['lines'] = $it['product_name'] . ': you cannot accept more than ' . fmt_qty($max) . ' (delivered minus rejected by the supplier).';
            }
            if ($qty > 0 && (!$pid || !db_row("SELECT id FROM products WHERE id = ? AND status <> 'archived'", [$pid]))) {
                $errors['lines'] = 'Choose the local product for "' . $it['product_name'] . '" or set its accepted quantity to 0.';
            }
        }
        $lines[] = ['it' => $it, 'qty' => $qty, 'pid' => $pid];
        $acceptedTotal += $qty;
    }
    if (!$errors && !$rejectAll && $acceptedTotal <= 0) {
        $errors['lines'] = 'Nothing is being accepted. Use "Reject entire delivery" if the whole delivery is refused.';
    }
    if (mb_strlen($post['notes']) > 2000) {
        $errors['notes'] = 'Notes are too long.';
    }
    if (!$errors) {
        $pdo = db();
        $uid = (int)current_user()['id'];
        $receiptId = 0;
        try {
            $pdo->beginTransaction();
            $ref = 'RCP-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $pdo->prepare('INSERT INTO stock_receipts (receipt_no, receipt_ref, external_delivery_id, external_delivery_ref, external_po_id, external_po_number, external_supplier_id,
                            status, ack_status, notes, received_by, received_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(['TMP' . bin2hex(random_bytes(8)), $ref, $did, $d['delivery_ref'], (int)$d['purchase_order_id'], $d['po_number'], (int)$d['supplier_id'],
                           $acceptedTotal > 0 ? 'accepted' : 'rejected', 'pending', $post['notes'] ?: null, $uid, date('Y-m-d H:i:s')]);
            $receiptId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE stock_receipts SET receipt_no = ? WHERE id = ?')->execute([sprintf('RCV-%s-%05d', date('Y'), $receiptId), $receiptId]);
            $ins = $pdo->prepare('INSERT INTO stock_receipt_items (receipt_id, product_id, external_delivery_item_id, external_po_item_id, external_supplier_product_id, item_name,
                                   qty_delivered_reported, qty_rejected_reported, qty_accepted, unit_cost) VALUES (?,?,?,?,?,?,?,?,?,?)');
            usort($lines, fn($a, $b) => $a['pid'] <=> $b['pid']);          // consistent lock order
            foreach ($lines as $l) {
                $it = $l['it'];
                $ins->execute([$receiptId, $l['pid'] ?: null, (int)$it['delivery_item_id'], (int)$it['po_item_id'], (int)$it['supplier_product_id'], $it['product_name'],
                               $it['qty_delivered'], $it['qty_rejected'], $l['qty'], $it['unit_price']]);
                if ($l['qty'] > 0) {
                    stock_apply($pdo, $l['pid'], 'delivery_receipt', $l['qty'], 'Delivery ' . $d['delivery_ref'] . ' (' . $d['po_number'] . ')', 'receipt', $receiptId, $uid);
                    // link an unlinked product to the supplier product it was just received as
                    $pdo->prepare('UPDATE products SET external_supplier_id = ?, external_supplier_product_id = ?, external_supplier_sku = ?
                                    WHERE id = ? AND external_supplier_product_id IS NULL')
                        ->execute([(int)$d['supplier_id'], (int)$it['supplier_product_id'], $it['supplier_sku'], $l['pid']]);
                    if ($post['update_cost']) {
                        $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ?')->execute([$it['unit_price'], $l['pid']]);
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && is_duplicate_key($e)) {
                flash('warning', 'This delivery was already received (duplicate receipt blocked). Stock was not increased again.');
                $again = db_row('SELECT id FROM stock_receipts WHERE external_delivery_id = ?', [$did]);
                redirect($again ? 'modules/receiving/view.php?id=' . (int)$again['id'] : 'modules/receiving/index.php');
            }
            app_log('receipt failed: ' . $e->getMessage());
            $errors['_form'] = $e instanceof RuntimeException ? $e->getMessage() : 'The receipt could not be saved. Nothing was changed.';
        }
        if ($receiptId && !$errors) {
            audit($acceptedTotal > 0 ? 'delivery_received' : 'delivery_rejected', 'stock_receipt', $receiptId, $d['delivery_ref'] . ' accepted ' . $acceptedTotal);
            $ack = ack_receipt($receiptId);                                      // stock is committed first; the acknowledgment follows
            flash($ack['ok'] ? 'success' : 'warning', ($acceptedTotal > 0 ? 'Stock updated from delivery ' . $d['delivery_ref'] . '. ' : 'Delivery rejected. ') . $ack['message']);
            redirect('modules/receiving/view.php?id=' . $receiptId);
        }
    }
}

$pageTitle = 'Verify delivery';
$activeMenu = 'receiving';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<a class="btn btn-light mb-3" href="index.php"><i class="bi bi-arrow-left"></i> Receiving</a>
<?php if (!$d): ?>
  <div class="alert alert-warning"><i class="bi bi-plug"></i> <?= e($error ?? 'Delivery unavailable.') ?></div>
<?php else: ?>
<div class="card mb-3"><div class="card-body"><dl class="row mb-0">
  <dt class="col-sm-2">Delivery</dt><dd class="col-sm-4"><?= e($d['delivery_ref']) ?></dd>
  <dt class="col-sm-2">Purchase order</dt><dd class="col-sm-4"><?= e($d['po_number']) ?></dd>
  <dt class="col-sm-2">Supplier</dt><dd class="col-sm-4"><?= e($d['supplier_name']) ?></dd>
  <dt class="col-sm-2">Arrived</dt><dd class="col-sm-4"><?= e(fmt_dt($d['arrival_date'], 'M j, Y')) ?></dd>
</dl></div></div>
<form method="post" class="needs-validation" novalidate><?= csrf_field() ?><input type="hidden" name="delivery_id" value="<?= $did ?>">
  <div class="card mb-3"><div class="card-header">Count what actually arrived, then accept into stock <small class="text-muted">Only the accepted quantity increases inventory.</small></div>
    <?php if (isset($errors['lines'])): ?><div class="alert alert-danger m-3 py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Supplier item</th><th class="text-end">Delivered</th><th class="text-end">Rejected by supplier</th><th class="text-end">Unit cost</th><th style="min-width:230px">Local product</th><th style="width:130px">Accept qty</th></tr></thead><tbody>
      <?php foreach ($d['items'] as $it): $iid = (int)$it['delivery_item_id']; $max = (float)$it['qty_delivered'] - (float)$it['qty_rejected'];
          $selP = (int)($post['product'][$iid] ?? ($pre[$iid] ?? 0));
          $val = $post['accept'][$iid] ?? fmt_qty($max); ?>
        <tr><td><?= e($it['product_name']) ?><br><small class="text-muted"><?= e($it['supplier_sku']) ?> · <?= e($it['unit']) ?></small></td>
          <td class="text-end"><?= e(fmt_qty($it['qty_delivered'])) ?></td><td class="text-end"><?= e(fmt_qty($it['qty_rejected'])) ?></td><td class="text-end"><?= e(money($it['unit_price'])) ?></td>
          <td><select class="form-select" name="product[<?= $iid ?>]"><option value="">— select product —</option>
            <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $selP === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['product_code'] . ' — ' . $p['name'] . ' (' . $p['unit'] . ')') ?></option><?php endforeach; ?></select>
            <?php if (!isset($pre[$iid])): ?><div class="form-text">No linked product yet — choose one; it will be linked to this supplier item.</div><?php endif; ?></td>
          <td><input class="form-control" name="accept[<?= $iid ?>]" inputmode="decimal" value="<?= e($val) ?>"><div class="form-text">max <?= e(fmt_qty($max)) ?></div></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
  <div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-8"><label class="form-label">Receiving notes</label><textarea class="form-control" name="notes" rows="2" maxlength="2000"><?= e($post['notes']) ?></textarea></div>
    <div class="col-md-4"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="update_cost" id="uc" value="1" <?= $post['update_cost'] ? 'checked' : '' ?>><label class="form-check-label" for="uc">Update product cost price to the delivered unit cost</label></div></div>
  </div></div>
  <div class="d-flex gap-2">
    <button class="btn btn-success" type="submit"><i class="bi bi-box-arrow-in-down"></i> Accept into stock</button>
    <button class="btn btn-outline-danger" type="submit" name="reject_all" value="1" data-confirm="Reject the entire delivery? No stock will be added and the supplier is told 0 units were accepted."><i class="bi bi-x-octagon"></i> Reject entire delivery</button>
  </div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
