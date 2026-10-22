<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once APP_ROOT . '/includes/integration_ops.php';
require_permission('damages.view');

$errors = [];
$f = ['product_id' => '', 'quantity' => '', 'reason' => '', 'request_refund' => ''];

if (is_post()) {
    require_permission('inventory.record');
    csrf_verify();
    $action = (string)($_POST['action'] ?? 'create');
    $uid = (int)current_user()['id'];

    if ($action === 'sync') {
        $r = sync_refunds();
        flash($r['ok'] ? 'success' : 'warning', $r['message']);
        redirect('modules/inventory/damaged.php');
    }
    if ($action === 'resubmit' || $action === 'request_refund') {
        $rid = 0;
        if ($action === 'request_refund') {
            $dr = db_row('SELECT dr.*, p.external_supplier_id, p.external_supplier_product_id FROM damage_reports dr JOIN products p ON p.id = dr.product_id WHERE dr.id = ?', [(int)($_POST['id'] ?? 0)]);
            if (!$dr || !$dr['external_supplier_id'] || db_scalar('SELECT COUNT(*) FROM refund_requests WHERE damage_report_id = ?', [(int)$dr['id']])) {
                flash('danger', 'A refund can only be requested once, and only for products linked to a supplier.');
                redirect('modules/inventory/damaged.php');
            }
            db()->prepare('INSERT INTO refund_requests (damage_report_id, request_ref, external_supplier_id, external_supplier_product_id, amount, requested_by) VALUES (?,?,?,?,?,?)')
                ->execute([(int)$dr['id'], 'RFD-' . date('ymdHis') . '-' . bin2hex(random_bytes(3)), (int)$dr['external_supplier_id'], $dr['external_supplier_product_id'], $dr['total_cost'], $uid]);
            $rid = (int)db()->lastInsertId();
            db()->prepare("UPDATE damage_reports SET status = 'refund_requested' WHERE id = ?")->execute([(int)$dr['id']]);
        } else {
            $rid = (int)($_POST['id'] ?? 0);
        }
        $res = submit_refund($rid);
        audit('refund_submit', 'refund_request', $rid, $res['message']);
        flash($res['ok'] ? 'success' : 'warning', $res['message']);
        redirect('modules/inventory/damaged.php');
    }
    if ($action === 'write_off') {
        db()->prepare("UPDATE damage_reports SET status = 'written_off' WHERE id = ? AND status IN ('recorded','refund_requested')")->execute([(int)($_POST['id'] ?? 0)]);
        audit('damage_written_off', 'damage_report', (int)($_POST['id'] ?? 0));
        flash('success', 'Damage written off.');
        redirect('modules/inventory/damaged.php');
    }

    foreach (['product_id', 'quantity', 'reason'] as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $f['request_refund'] = isset($_POST['request_refund']) ? '1' : '';
    $qty = parse_decimal($f['quantity']);
    $prod = db_row("SELECT * FROM products WHERE id = ? AND status <> 'archived'", [(int)$f['product_id']]);
    if (!$prod) { $errors['product_id'] = 'Choose a product.'; }
    if ($qty === null || (float)$qty <= 0) { $errors['quantity'] = 'Enter a quantity greater than 0.'; }
    if ($f['reason'] === '' || mb_strlen($f['reason']) > 255) { $errors['reason'] = 'Describe the damage (max 255 characters).'; }
    if ($f['request_refund'] && $prod && !$prod['external_supplier_id']) {
        $errors['request_refund'] = 'This product is not linked to a supplier, so a supplier refund cannot be requested.';
    }
    if (!$errors) {
        $pdo = db();
        $rid = 0;
        try {
            $pdo->beginTransaction();
            $cost = round((float)$prod['cost_price'] * (float)$qty, 2);
            $pdo->prepare('INSERT INTO damage_reports (product_id, quantity, unit_cost, total_cost, reason, status, reported_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([(int)$prod['id'], $qty, $prod['cost_price'], $cost, $f['reason'], $f['request_refund'] ? 'refund_requested' : 'recorded', $uid]);
            $dId = (int)$pdo->lastInsertId();
            stock_apply($pdo, (int)$prod['id'], 'damaged', (float)$qty, 'Damaged: ' . $f['reason'], 'damage', $dId, $uid);
            if ($f['request_refund']) {
                $pdo->prepare('INSERT INTO refund_requests (damage_report_id, request_ref, external_supplier_id, external_supplier_product_id, amount, requested_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$dId, 'RFD-' . date('ymdHis') . '-' . bin2hex(random_bytes(3)), (int)$prod['external_supplier_id'], $prod['external_supplier_product_id'], $cost, $uid]);
                $rid = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
            audit('damage_reported', 'damage_report', $dId, $prod['product_code'] . ' x ' . $qty . ': ' . $f['reason']);
            $msg = 'Damage recorded; stock reduced. Loss at cost: ' . money($cost) . '.';
            if ($rid) {
                $res = submit_refund($rid);
                $msg .= ' ' . $res['message'];
            }
            flash('success', $msg);
            redirect('modules/inventory/damaged.php');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors['_form'] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            app_log('damage failed: ' . $e->getMessage());
            $errors['_form'] = 'The damage could not be recorded. Nothing was changed.';
        }
    }
}

$pg = paginate((int)db_scalar('SELECT COUNT(*) FROM damage_reports'), 15);
$rows = db_all("SELECT dr.*, p.product_code, p.name, p.unit, p.external_supplier_id, u.full_name,
                       rq.id AS rq_id, rq.request_ref, rq.submit_status, rq.remote_status, rq.remote_note, rq.last_error, rq.last_synced_at
                  FROM damage_reports dr JOIN products p ON p.id = dr.product_id JOIN users u ON u.id = dr.reported_by LEFT JOIN refund_requests rq ON rq.damage_report_id = dr.id
                 ORDER BY dr.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}");
$totals = db_row("SELECT COALESCE(SUM(total_cost),0) AS total, COALESCE(SUM(CASE WHEN status='refund_requested' THEN total_cost END),0) AS claimed,
                         COALESCE(SUM(CASE WHEN status='resolved' THEN total_cost END),0) AS recovered FROM damage_reports");
$products = db_all("SELECT id, product_code, name, unit, quantity, external_supplier_id FROM products WHERE status <> 'archived' AND quantity > 0 ORDER BY name");

$pageTitle = 'Damaged goods & refunds';
$activeMenu = 'damages';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card danger"><div class="card-body"><div class="stat-label">Total damaged (at cost)</div><div class="stat-value"><?= e(money($totals['total'])) ?></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card warn"><div class="card-body"><div class="stat-label">Claimed from suppliers, pending</div><div class="stat-value"><?= e(money($totals['claimed'])) ?></div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="stat-label">Refunded by suppliers</div><div class="stat-value"><?= e(money($totals['recovered'])) ?></div></div></div></div>
</div>
<?php if (can('inventory.record')): ?>
<div class="card mb-3"><div class="card-header">Report damaged items</div><div class="card-body">
<form method="post" class="row g-2 needs-validation" novalidate><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="col-md-4"><select class="form-select<?= inv($errors, 'product_id') ?>" name="product_id" required><option value="">Product…</option>
    <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $f['product_id'] === (string)$pr['id'] ? 'selected' : '' ?>><?= e($pr['product_code'] . ' — ' . $pr['name'] . ' (' . fmt_qty($pr['quantity']) . ' ' . $pr['unit'] . ')' . ($pr['external_supplier_id'] ? ' ⛓' : '')) ?></option><?php endforeach; ?></select><?= fb($errors, 'product_id') ?></div>
  <div class="col-md-2"><input class="form-control<?= inv($errors, 'quantity') ?>" name="quantity" inputmode="decimal" placeholder="Quantity" value="<?= e($f['quantity']) ?>" required><?= fb($errors, 'quantity') ?></div>
  <div class="col-md-4"><input class="form-control<?= inv($errors, 'reason') ?>" name="reason" maxlength="255" placeholder="What happened?" value="<?= e($f['reason']) ?>" required><?= fb($errors, 'reason') ?></div>
  <div class="col-md-2"><button class="btn btn-danger w-100" type="submit">Record damage</button></div>
  <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="request_refund" id="rr" value="1" <?= $f['request_refund'] ? 'checked' : '' ?>>
    <label class="form-check-label" for="rr">Also ask the supplier for a refund (⛓ = product is linked to a supplier; the claim is sent through the API)</label><?= fb($errors, 'request_refund') ?></div></div>
</form></div></div>
<?php endif; ?>
<div class="d-flex justify-content-between mb-2"><h2 class="h5 mb-0">Damage reports</h2>
  <?php if (can('inventory.record')): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-repeat"></i> Sync refund decisions</button></form><?php endif; ?></div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Date</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Cost</th><th>Reason</th><th>Status</th><th>Supplier refund</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($r['created_at'], 'M j, Y')) ?></td><td><?= e($r['product_code']) ?> <small><?= e($r['name']) ?></small></td>
      <td class="text-end"><?= e(fmt_qty($r['quantity'])) ?></td><td class="text-end"><?= e(money($r['total_cost'])) ?></td><td class="small"><?= e($r['reason']) ?></td><td><?= status_badge($r['status']) ?></td>
      <td class="small"><?php if ($r['rq_id']): ?><span class="font-monospace"><?= e($r['request_ref']) ?></span><br>
          <?php if ($r['submit_status'] === 'submitted'): ?><?= status_badge((string)($r['remote_status'] ?? 'received')) ?><?= $r['remote_note'] ? ' ' . e($r['remote_note']) : '' ?>
          <?php else: ?><span class="badge text-bg-warning">Not sent yet</span> <?= e($r['last_error'] ?? '') ?><?php endif; ?>
        <?php else: ?>—<?php endif; ?></td>
      <td class="text-end text-nowrap"><?php if (can('inventory.record')): ?>
        <?php if ($r['rq_id'] && $r['submit_status'] !== 'submitted'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="resubmit"><input type="hidden" name="id" value="<?= (int)$r['rq_id'] ?>"><button class="btn btn-sm btn-outline-warning">Resend</button></form><?php endif; ?>
        <?php if (!$r['rq_id'] && $r['external_supplier_id'] && in_array($r['status'], ['recorded'], true)): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="request_refund"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-success">Request refund</button></form><?php endif; ?>
        <?php if (in_array($r['status'], ['recorded', 'refund_requested'], true) && (!$r['rq_id'] || in_array((string)$r['remote_status'], ['rejected'], true))): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="write_off"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-outline-secondary" data-confirm="Write this damage off as a loss?">Write off</button></form><?php endif; ?>
      <?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No damaged items reported.</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
