<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('deliveries.manage');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$del = null;
if ($id > 0) {
    $del = db_row('SELECT * FROM deliveries WHERE id = ?', [$id]);
    if (!$del) {
        abort_page(404, 'Delivery not found.');
    }
    if ($del['status'] !== 'expected') {
        flash('warning', 'Only expected deliveries can be edited. Confirmed deliveries are locked once they are sent to the Inventory system.');
        redirect('modules/deliveries/view.php?id=' . $id);
    }
}
$poId = (int)($del['purchase_order_id'] ?? $_GET['po_id'] ?? $_POST['purchase_order_id'] ?? 0);
$openPOs = db_all("SELECT po.id, po.po_number, s.business_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id
                    WHERE po.status IN ('approved','sent','partially_received') ORDER BY po.id DESC");
$po = $poId > 0 ? db_row('SELECT po.*, s.business_name FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id WHERE po.id = ?', [$poId]) : null;
if ($po && !in_array($po['status'], PO_DELIVERABLE, true)) {
    flash('danger', 'Deliveries can only be recorded for approved, sent or partially received orders.');
    redirect('modules/purchase_orders/view.php?id=' . $poId);
}
$items = $po ? db_all('SELECT * FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id', [$poId]) : [];
foreach ($items as &$it) {
    $it['available'] = po_line_available((int)$it['id']);
}
unset($it);

$existing = [];
if ($del) {
    foreach (db_all('SELECT po_item_id, qty_delivered, qty_rejected FROM delivery_items WHERE delivery_id = ?', [$id]) as $x) {
        $existing[(int)$x['po_item_id']] = $x;
    }
}
$count = (int)db_scalar('SELECT COUNT(*) FROM deliveries') + 1;
$f = [
    'delivery_ref'  => $del['delivery_ref'] ?? 'DLV-' . date('Ymd') . '-' . str_pad((string)$count, 3, '0', STR_PAD_LEFT),
    'expected_date' => (string)($del['expected_date'] ?? $po['expected_date'] ?? ''),
    'arrival_date'  => $del && $del['arrival_date'] ? date('Y-m-d', strtotime($del['arrival_date'])) : date('Y-m-d'),
    'notes'         => (string)($del['notes'] ?? ''),
];
$errors = [];
$posted = [];

if (is_post()) {
    csrf_verify();
    foreach (['delivery_ref', 'expected_date', 'arrival_date', 'notes'] as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $confirm = isset($_POST['confirm_delivery']);
    if (!$po) {
        $errors['purchase_order_id'] = 'Choose a purchase order.';
    }
    if ($f['delivery_ref'] === '' || mb_strlen($f['delivery_ref']) > 40) {
        $errors['delivery_ref'] = 'Delivery reference is required (max 40 characters).';
    } elseif ((int)db_scalar('SELECT COUNT(*) FROM deliveries WHERE delivery_ref = ? AND id <> ?', [$f['delivery_ref'], $id]) > 0) {
        $errors['delivery_ref'] = 'This delivery reference is already used.';
    }
    foreach (['expected_date', 'arrival_date'] as $k) {
        if ($f[$k] !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $f[$k]);
            if (!$d || $d->format('Y-m-d') !== $f[$k]) {
                $errors[$k] = 'Enter a valid date.';
            }
        }
    }
    if ($confirm && $f['arrival_date'] === '') {
        $errors['arrival_date'] = 'Arrival date is required to confirm a delivery.';
    }
    if ($confirm && $f['arrival_date'] !== '' && $f['arrival_date'] > date('Y-m-d')) {
        $errors['arrival_date'] = 'Arrival date cannot be in the future.';
    }
    $delivered = (array)($_POST['delivered'] ?? []);
    $rejected  = (array)($_POST['rejected'] ?? []);
    $rows = [];
    $anyDelivered = false;
    foreach ($items as $it) {
        $iid = (int)$it['id'];
        $dRaw = trim((string)($delivered[$iid] ?? '')) ?: '0';
        $rRaw = trim((string)($rejected[$iid] ?? '')) ?: '0';
        $posted[$iid] = ['d' => $dRaw === '0' ? '' : $dRaw, 'r' => $rRaw === '0' ? '' : $rRaw];
        $dq = parse_decimal($dRaw);
        $rq = parse_decimal($rRaw);
        if ($dq === null || $rq === null) {
            $errors['lines'] = 'Quantities must be numbers (0 or more, up to 2 decimals).';
            continue;
        }
        if ((float)$rq > (float)$dq) {
            $errors['lines'] = $it['product_name'] . ': rejected quantity cannot exceed delivered quantity.';
        } elseif ((float)$dq - (float)$rq > (float)$it['available'] + 0.0001) {
            $errors['lines'] = $it['product_name'] . ': accepted quantity ' . fmt_qty((float)$dq - (float)$rq) . ' exceeds the outstanding ' . fmt_qty($it['available'])
                             . '. Record any over-delivery as rejected.';
        }
        if ((float)$dq > 0 || (float)$rq > 0) {
            $rows[] = [$iid, $dq, $rq];
        }
        if ((float)$dq > 0) {
            $anyDelivered = true;
        }
    }
    if ($confirm && !$anyDelivered && !isset($errors['lines'])) {
        $errors['lines'] = 'Enter the delivered quantity for at least one line to confirm the delivery.';
    }
    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            // lock the PO so two people cannot over-allocate the same lines
            $lock = $pdo->prepare('SELECT status FROM purchase_orders WHERE id = ? FOR UPDATE');
            $lock->execute([$poId]);
            if (!in_array((string)$lock->fetchColumn(), PO_DELIVERABLE, true)) {
                throw new RuntimeException('The purchase order is no longer open for deliveries.');
            }
            if ($confirm) {                 // re-validate against fresh numbers inside the lock
                foreach ($rows as [$iid, $dq, $rq]) {
                    if ((float)$dq - (float)$rq > po_line_available($iid) + 0.0001) {
                        throw new RuntimeException('Another delivery was confirmed meanwhile; reload and re-enter the quantities.');
                    }
                }
            }
            $status = $confirm ? 'confirmed' : 'expected';
            $arrival = $f['arrival_date'] !== '' ? $f['arrival_date'] . ' 00:00:00' : null;
            if ($del) {
                $pdo->prepare('UPDATE deliveries SET delivery_ref=?, status=?, expected_date=?, arrival_date=?, notes=?, confirmed_at=? WHERE id=?')
                    ->execute([$f['delivery_ref'], $status, $f['expected_date'] ?: null, $confirm ? $arrival : null, $f['notes'] ?: null, $confirm ? date('Y-m-d H:i:s') : null, $id]);
                $pdo->prepare('DELETE FROM delivery_items WHERE delivery_id = ?')->execute([$id]);
                $dId = $id;
            } else {
                $pdo->prepare('INSERT INTO deliveries (delivery_ref, purchase_order_id, status, expected_date, arrival_date, notes, recorded_by, confirmed_at) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$f['delivery_ref'], $poId, $status, $f['expected_date'] ?: null, $confirm ? $arrival : null, $f['notes'] ?: null, (int)current_user()['id'], $confirm ? date('Y-m-d H:i:s') : null]);
                $dId = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO delivery_items (delivery_id, po_item_id, qty_delivered, qty_rejected) VALUES (?,?,?,?)');
            foreach ($rows as [$iid, $dq, $rq]) {
                $ins->execute([$dId, $iid, $dq, $rq]);
            }
            $pdo->commit();
            audit($confirm ? 'delivery_confirmed' : 'delivery_saved', 'delivery', $dId, $f['delivery_ref'] . ' for ' . $po['po_number']);
            flash('success', $confirm ? 'Delivery confirmed. It is now available to the Inventory system for verification.' : 'Expected delivery saved.');
            redirect('modules/deliveries/view.php?id=' . $dId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e instanceof RuntimeException ? $e->getMessage() : 'The delivery could not be saved.';
            if ($e instanceof PDOException) {
                app_log('delivery save failed: ' . $e->getMessage());
                $msg = is_duplicate_key($e) ? 'This delivery reference is already used.' : $msg;
            }
            $errors['_form'] = $msg;
        }
    }
}

$pageTitle = $del ? 'Edit expected delivery' : 'Record delivery';
$activeMenu = 'deliveries';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<?php if (!$po): ?>
  <div class="card" style="max-width:640px"><div class="card-body">
    <form method="get"><label class="form-label">Purchase order</label>
      <div class="input-group"><select class="form-select" name="po_id" required><option value="">Select an approved / sent / partially received order…</option>
        <?php foreach ($openPOs as $o): ?><option value="<?= (int)$o['id'] ?>"><?= e($o['po_number'] . ' — ' . $o['business_name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn-success">Continue</button></div>
      <?php if (!$openPOs): ?><div class="form-text text-danger">There are no orders open for delivery. Orders must be approved first.</div><?php endif; ?></form>
  </div></div>
<?php else: ?>
<form method="post" class="needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="purchase_order_id" value="<?= $poId ?>">
  <div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-4"><label class="form-label">Purchase order</label><input class="form-control" disabled value="<?= e($po['po_number'] . ' — ' . $po['business_name']) ?>"></div>
    <?= form_input('delivery_ref', 'Delivery reference', $f, $errors, ['required' => true, 'maxlength' => 40, 'col' => 'col-md-3', 'help' => 'Supplier delivery receipt number or an internal reference.']) ?>
    <?= form_input('expected_date', 'Expected date', $f, $errors, ['type' => 'date', 'col' => 'col-md-2']) ?>
    <?= form_input('arrival_date', 'Actual arrival date', $f, $errors, ['type' => 'date', 'col' => 'col-md-3']) ?>
    <?= form_input('notes', 'Notes', $f, $errors, ['type' => 'textarea', 'rows' => 2, 'col' => 'col-12', 'maxlength' => 2000]) ?>
  </div></div>
  <div class="card mb-3"><div class="card-header">Quantities <small class="text-muted">Delivered = physically arrived. Rejected = damaged/wrong. Only delivered minus rejected can be accepted into stock.</small></div>
    <?php if (isset($errors['lines'])): ?><div class="alert alert-danger m-3 py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Product</th><th class="text-end">Ordered</th><th class="text-end">Already received</th><th class="text-end">Outstanding</th><th style="width:130px">Delivered</th><th style="width:130px">Rejected</th></tr></thead><tbody>
      <?php foreach ($items as $it): $iid = (int)$it['id'];
          $dv = $posted[$iid]['d'] ?? (isset($existing[$iid]) ? fmt_qty($existing[$iid]['qty_delivered']) : '');
          $rj = $posted[$iid]['r'] ?? (isset($existing[$iid]) ? fmt_qty($existing[$iid]['qty_rejected']) : ''); ?>
        <tr><td><?= e($it['product_name']) ?> <small class="text-muted"><?= e($it['supplier_sku']) ?> · <?= e($it['unit']) ?></small></td>
          <td class="text-end"><?= e(fmt_qty($it['qty_ordered'])) ?></td><td class="text-end"><?= e(fmt_qty($it['qty_received'])) ?></td>
          <td class="text-end fw-bold"><?= e(fmt_qty($it['available'])) ?></td>
          <td><input class="form-control" name="delivered[<?= $iid ?>]" inputmode="decimal" value="<?= e($dv === '0' ? '' : $dv) ?>" <?= (float)$it['available'] <= 0 && $dv === '' ? 'placeholder="0"' : '' ?>></td>
          <td><input class="form-control" name="rejected[<?= $iid ?>]" inputmode="decimal" value="<?= e($rj === '0' ? '' : $rj) ?>"></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" type="submit"><i class="bi bi-save"></i> Save as expected</button>
    <button class="btn btn-success" type="submit" name="confirm_delivery" value="1" data-confirm="Confirm this delivery? It will be sent to the Inventory system for verification and can no longer be edited."><i class="bi bi-check2-circle"></i> Confirm delivery</button>
    <a class="btn btn-light" href="index.php">Cancel</a>
  </div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
