<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('inventory.view');

$errors = [];
$f = ['product_id' => '', 'type' => 'stock_in', 'quantity' => '', 'reason' => ''];
$types = ['stock_in' => 'Stock in', 'stock_out' => 'Stock out', 'adjustment_in' => 'Adjustment (increase)', 'adjustment_out' => 'Adjustment (decrease)'];

if (is_post()) {
    require_permission('inventory.record');
    csrf_verify();
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $qty = parse_decimal($f['quantity']);
    if (!isset($types[$f['type']])) {
        $errors['type'] = 'Choose a movement type.';
    }
    if ((int)$f['product_id'] <= 0) {
        $errors['product_id'] = 'Choose a product.';
    }
    if ($qty === null || (float)$qty <= 0) {
        $errors['quantity'] = 'Enter a quantity greater than 0.';
    }
    if ($f['reason'] === '' || mb_strlen($f['reason']) > 255) {
        $errors['reason'] = 'A reason is required (max 255 characters).';
    }
    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $isAdj = str_starts_with($f['type'], 'adjustment');
            $bal = stock_apply($pdo, (int)$f['product_id'], $f['type'], (float)$qty, $f['reason'], 'manual', null, (int)current_user()['id'], $isAdj ? 'pending' : 'not_required');
            $pdo->commit();
            audit('stock_' . $f['type'], 'product', (int)$f['product_id'], fmt_qty($qty) . ' — ' . $f['reason'] . ' (balance ' . fmt_qty($bal) . ')');
            flash('success', 'Movement recorded. New balance: ' . fmt_qty($bal) . '.' . ($isAdj ? ' The adjustment is flagged for review.' : ''));
            redirect('modules/inventory/index.php');
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $errors['_form'] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('stock movement failed: ' . $e->getMessage());
            $errors['_form'] = 'The movement could not be saved. Nothing was changed.';
        }
    }
}

$pid = (int)($_GET['product_id'] ?? 0);
$type = (string)($_GET['type'] ?? '');
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$rev = (string)($_GET['review'] ?? '');
$where = ['1=1'];
$p = [];
if ($pid > 0) { $where[] = 'm.product_id = ?'; $p[] = $pid; }
if ($type !== '') { $where[] = 'm.movement_type = ?'; $p[] = $type; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'm.created_at >= ?'; $p[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'm.created_at < ?'; $p[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'; }
if ($rev === 'pending') { $where[] = "m.review_status = 'pending'"; }
$W = implode(' AND ', $where);
$pg = paginate((int)db_scalar("SELECT COUNT(*) FROM stock_movements m WHERE $W", $p), 20);
$rows = db_all("SELECT m.*, p.product_code, p.name, p.unit, u.full_name, rv.full_name AS reviewer
                  FROM stock_movements m JOIN products p ON p.id = m.product_id JOIN users u ON u.id = m.created_by LEFT JOIN users rv ON rv.id = m.reviewed_by
                 WHERE $W ORDER BY m.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $p);
$products = db_all("SELECT id, product_code, name, unit, quantity FROM products WHERE status <> 'archived' ORDER BY name");
$allTypes = ['stock_in', 'stock_out', 'adjustment_in', 'adjustment_out', 'damaged', 'sale', 'delivery_receipt', 'sale_void'];

$pageTitle = 'Stock movements';
$activeMenu = 'inventory';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<?php if (can('inventory.record')): ?>
<div class="card mb-3"><div class="card-header">Record a movement <small class="text-muted">Supplier deliveries are received under Receiving; damaged items under Damaged &amp; Refunds; sales under Sales.</small></div>
<div class="card-body"><form method="post" class="row g-2 needs-validation" novalidate><?= csrf_field() ?>
  <div class="col-md-3"><select class="form-select<?= inv($errors, 'product_id') ?>" name="product_id" required><option value="">Product…</option>
    <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $f['product_id'] === (string)$pr['id'] ? 'selected' : '' ?>><?= e($pr['product_code'] . ' — ' . $pr['name'] . ' (' . fmt_qty($pr['quantity']) . ' ' . $pr['unit'] . ')') ?></option><?php endforeach; ?></select><?= fb($errors, 'product_id') ?></div>
  <div class="col-md-2"><select class="form-select" name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $f['type'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><input class="form-control<?= inv($errors, 'quantity') ?>" name="quantity" inputmode="decimal" placeholder="Quantity" value="<?= e($f['quantity']) ?>" required><?= fb($errors, 'quantity') ?></div>
  <div class="col-md-3"><input class="form-control<?= inv($errors, 'reason') ?>" name="reason" maxlength="255" placeholder="Reason (required)" value="<?= e($f['reason']) ?>" required><?= fb($errors, 'reason') ?></div>
  <div class="col-md-2"><button class="btn btn-success w-100" type="submit"><i class="bi bi-check-lg"></i> Record</button></div>
</form></div></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="get">
  <div class="col-auto"><select class="form-select" name="product_id"><option value="">All products</option>
    <?php foreach ($products as $pr): ?><option value="<?= (int)$pr['id'] ?>" <?= $pid === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['product_code'] . ' — ' . $pr['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><select class="form-select" name="type"><option value="">All types</option><?php foreach ($allTypes as $t): ?><option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= e(role_label($t)) ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><input class="form-control" type="date" name="from" value="<?= e($from) ?>"></div>
  <div class="col-auto"><input class="form-control" type="date" name="to" value="<?= e($to) ?>"></div>
  <div class="col-auto form-check pt-2"><input class="form-check-input" type="checkbox" name="review" value="pending" id="rv" <?= $rev === 'pending' ? 'checked' : '' ?>><label class="form-check-label" for="rv">Needs review</label></div>
  <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>When</th><th>Product</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Reason</th><th>By</th><th>Review</th></tr></thead><tbody>
  <?php foreach ($rows as $m): $out = in_array($m['movement_type'], STOCK_OUT_TYPES, true); ?>
    <tr><td class="text-nowrap"><?= e(fmt_dt($m['created_at'])) ?></td><td><?= e($m['product_code']) ?> <small><?= e($m['name']) ?></small></td><td><?= e(role_label($m['movement_type'])) ?></td>
      <td class="text-end <?= $out ? 'text-danger' : 'text-success' ?>"><?= $out ? '−' : '+' ?><?= e(fmt_qty($m['quantity'])) ?></td><td class="text-end"><?= e(fmt_qty($m['balance_after'])) ?></td>
      <td class="small"><?= e($m['reason'] ?? '') ?></td><td><?= e($m['full_name']) ?></td>
      <td><?php if ($m['review_status'] === 'pending'): ?>
            <?php if (can('adjustments.review')): ?><form method="post" action="review.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-success">Mark reviewed</button></form>
            <?php else: ?><span class="badge text-bg-warning">Pending</span><?php endif; ?>
          <?php elseif ($m['review_status'] === 'reviewed'): ?><span class="badge text-bg-success" title="<?= e($m['reviewer'] ?? '') ?>">Reviewed</span><?php else: ?>—<?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="text-muted p-3">No movements match.</td></tr><?php endif; ?></tbody></table></div></div>
<?= pager($pg) ?>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
