<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_permission('po.create');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$po = null;
$lines = [];
if ($id > 0) {
    $po = db_row('SELECT * FROM purchase_orders WHERE id = ?', [$id]);
    if (!$po) {
        abort_page(404, 'Purchase order not found.');
    }
    if (!in_array($po['status'], ['draft', 'rejected'], true)) {
        flash('warning', 'Only draft or rejected purchase orders can be edited. Approved orders are locked.');
        redirect('modules/purchase_orders/view.php?id=' . $id);
    }
    foreach (db_all('SELECT supplier_product_id, qty_ordered FROM purchase_order_items WHERE purchase_order_id = ? ORDER BY id', [$id]) as $l) {
        $lines[] = ['pid' => (string)$l['supplier_product_id'], 'qty' => fmt_qty($l['qty_ordered'])];
    }
}
$f = [
    'supplier_id'   => (string)($po['supplier_id'] ?? (int)($_GET['supplier_id'] ?? 0) ?: ''),
    'order_date'    => $po['order_date'] ?? date('Y-m-d'),
    'expected_date' => (string)($po['expected_date'] ?? ''),
    'notes'         => (string)($po['notes'] ?? ''),
];
$errors = [];

// approved, non-archived catalog items of active suppliers
$catalog = db_all("SELECT sp.id, sp.supplier_id, sp.product_name, sp.supplier_sku, sp.unit, sp.current_price, sp.min_order_qty, sp.availability
                     FROM supplier_products sp JOIN suppliers s ON s.id = sp.supplier_id
                    WHERE sp.is_approved = 1 AND sp.is_archived = 0 AND s.status = 'active' ORDER BY sp.product_name");
$catById = [];
foreach ($catalog as $c) {
    $catById[(int)$c['id']] = $c;
}

if (is_post()) {
    csrf_verify();
    $f['supplier_id']   = trim((string)($_POST['supplier_id'] ?? ''));
    $f['order_date']    = trim((string)($_POST['order_date'] ?? ''));
    $f['expected_date'] = trim((string)($_POST['expected_date'] ?? ''));
    $f['notes']         = trim((string)($_POST['notes'] ?? ''));
    if ($po) {
        $f['supplier_id'] = (string)$po['supplier_id'];
    }
    $pids = (array)($_POST['product_id'] ?? []);
    $qtys = (array)($_POST['qty'] ?? []);
    $lines = [];
    $clean = [];
    $seen = [];
    foreach ($pids as $i => $pid) {
        $pid = (int)$pid;
        $qtyRaw = trim((string)($qtys[$i] ?? ''));
        if ($pid === 0 && $qtyRaw === '') {
            continue;
        }
        $lines[] = ['pid' => (string)$pid, 'qty' => $qtyRaw];
        $item = $catById[$pid] ?? null;
        $qty = parse_decimal($qtyRaw);
        if (!$item || (int)$item['supplier_id'] !== (int)$f['supplier_id']) {
            $errors['lines'] = 'Every line needs an approved catalog product from the selected supplier.';
        } elseif ($item['availability'] === 'unavailable') {
            $errors['lines'] = $item['product_name'] . ' is currently marked unavailable by the supplier.';
        } elseif ($qty === null || (float)$qty <= 0) {
            $errors['lines'] = 'Enter a valid quantity greater than 0 for every line.';
        } elseif ((float)$qty < (float)$item['min_order_qty']) {
            $errors['lines'] = $item['product_name'] . ': minimum order quantity is ' . fmt_qty($item['min_order_qty']) . '.';
        } elseif (isset($seen[$pid])) {
            $errors['lines'] = 'A product can appear only once per order. Combine the quantities.';
        } else {
            $seen[$pid] = true;
            $clean[] = [$item, $qty];
        }
    }
    if (!$clean && !isset($errors['lines'])) {
        $errors['lines'] = 'Add at least one product line.';
    }
    $sup = db_row('SELECT id, status FROM suppliers WHERE id = ?', [(int)$f['supplier_id']]);
    if (!$sup || $sup['status'] !== 'active') {
        $errors['supplier_id'] = 'Choose an active supplier.';
    }
    $od = DateTime::createFromFormat('Y-m-d', $f['order_date']);
    if (!$od || $od->format('Y-m-d') !== $f['order_date']) {
        $errors['order_date'] = 'Enter a valid order date.';
    }
    if ($f['expected_date'] !== '') {
        $ed = DateTime::createFromFormat('Y-m-d', $f['expected_date']);
        if (!$ed || $ed->format('Y-m-d') !== $f['expected_date']) {
            $errors['expected_date'] = 'Enter a valid expected delivery date.';
        } elseif ($od && $ed < $od) {
            $errors['expected_date'] = 'Expected delivery cannot be before the order date.';
        }
    }
    if (mb_strlen($f['notes']) > 2000) {
        $errors['notes'] = 'Notes are too long (max 2000 characters).';
    }
    if (!$errors) {
        $pdo = db();
        $submit = isset($_POST['submit_for_approval']);
        $uid = (int)current_user()['id'];
        try {
            $pdo->beginTransaction();
            if ($po) {
                $pdo->prepare("UPDATE purchase_orders SET order_date=?, expected_date=?, notes=?, status=?, rejection_reason=NULL, submitted_at=? WHERE id=?")
                    ->execute([$f['order_date'], $f['expected_date'] ?: null, $f['notes'] ?: null, $submit ? 'pending_approval' : 'draft', $submit ? date('Y-m-d H:i:s') : null, $id]);
                $pdo->prepare('DELETE FROM purchase_order_items WHERE purchase_order_id = ?')->execute([$id]);
                $poId = $id;
            } else {
                $pdo->prepare("INSERT INTO purchase_orders (po_number, supplier_id, status, order_date, expected_date, notes, submitted_at, created_by) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(['TMP' . bin2hex(random_bytes(8)), (int)$f['supplier_id'], $submit ? 'pending_approval' : 'draft', $f['order_date'], $f['expected_date'] ?: null,
                               $f['notes'] ?: null, $submit ? date('Y-m-d H:i:s') : null, $uid]);
                $poId = (int)$pdo->lastInsertId();
                $pdo->prepare('UPDATE purchase_orders SET po_number = ? WHERE id = ?')->execute([po_number_for($poId), $poId]);
            }
            $ins = $pdo->prepare('INSERT INTO purchase_order_items (purchase_order_id, supplier_product_id, product_name, supplier_sku, unit, qty_ordered, unit_price, line_total) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($clean as [$item, $qty]) {
                $price = (float)$item['current_price'];           // price is snapshotted from the catalog at this moment
                $ins->execute([$poId, (int)$item['id'], $item['product_name'], $item['supplier_sku'], $item['unit'], $qty, $price, round($price * (float)$qty, 2)]);
            }
            po_recalc_total($pdo, $poId);
            $pdo->commit();
            $num = (string)db_scalar('SELECT po_number FROM purchase_orders WHERE id = ?', [$poId]);
            audit($po ? 'po_updated' : 'po_created', 'purchase_order', $poId, $num . ($submit ? ' submitted for approval' : ' saved as draft'));
            flash('success', 'Purchase order ' . $num . ($submit ? ' submitted for approval.' : ' saved as draft.'));
            redirect('modules/purchase_orders/view.php?id=' . $poId);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            app_log('po save failed: ' . $e->getMessage());
            $errors['_form'] = 'The purchase order could not be saved. Details were written to the log.';
        }
    }
}

$suppliers = db_all("SELECT id, business_name FROM suppliers WHERE status = 'active' ORDER BY business_name");
$supOpts = [];
foreach ($suppliers as $s) {
    $supOpts[$s['id']] = $s['business_name'];
}
if (!$lines) {
    $lines[] = ['pid' => '', 'qty' => ''];
}
$pageTitle = $po ? 'Edit ' . $po['po_number'] : 'New purchase order';
$activeMenu = 'po';
$pageScripts = '<script>(function(){'
  . 'var cat=' . json_for_script($catalog) . ',lines=' . json_for_script($lines) . ';'
  . 'var sup=document.getElementById("f_supplier_id"),body=document.getElementById("lineBody"),totalEl=document.getElementById("grandTotal");'
  . 'function money(n){return "₱"+Number(n).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}'
  . 'function opts(sid,sel){var h="<option value=\"\">Select product…</option>";cat.forEach(function(c){if(String(c.supplier_id)!==String(sid))return;'
  . 'h+="<option value=\""+c.id+"\""+(String(c.id)===String(sel)?" selected":"")+">"+c.supplier_sku+" — "+c.product_name+" ("+c.unit+")"+(c.availability==="limited"?" [limited]":"")+"</option>";});return h;}'
  . 'function find(id){for(var i=0;i<cat.length;i++)if(String(cat[i].id)===String(id))return cat[i];return null;}'
  . 'function recalc(){var t=0;body.querySelectorAll("tr").forEach(function(tr){var c=find(tr.querySelector("select").value),q=parseFloat(tr.querySelector("input").value)||0;'
  . 'var p=c?parseFloat(c.current_price):0;tr.querySelector(".price").textContent=c?money(p)+" / "+c.unit+" (min "+Number(c.min_order_qty)+")":"—";'
  . 'tr.querySelector(".lt").textContent=money(p*q);t+=p*q;});totalEl.textContent=money(t);}'
  . 'function addRow(pid,qty){var tr=document.createElement("tr");tr.innerHTML="<td><select name=\"product_id[]\" class=\"form-select\">"+opts(sup.value,pid)+"</select></td>"'
  . '+"<td style=\"width:130px\"><input name=\"qty[]\" class=\"form-control\" inputmode=\"decimal\" value=\""+(qty||"")+"\"></td><td class=\"price small text-muted\">—</td><td class=\"lt text-end\">₱0.00</td>"'
  . '+"<td class=\"text-end\"><button type=\"button\" class=\"btn btn-sm btn-outline-danger rm\"><i class=\"bi bi-x\"></i></button></td>";body.appendChild(tr);'
  . 'tr.querySelector("select").addEventListener("change",recalc);tr.querySelector("input").addEventListener("input",recalc);'
  . 'tr.querySelector(".rm").addEventListener("click",function(){if(body.children.length>1){tr.remove();recalc();}});}'
  . 'lines.forEach(function(l){addRow(l.pid,l.qty);});recalc();'
  . 'document.getElementById("addLine").addEventListener("click",function(){addRow("","");});'
  . 'if(sup.tagName==="SELECT"&&!sup.disabled){sup.addEventListener("change",function(){body.innerHTML="";addRow("","");recalc();});}'
  . '})();</script>';
require APP_ROOT . '/includes/layout_header.php';
echo errors_alert($errors);
?>
<?php if ($po && $po['status'] === 'rejected'): ?><div class="alert alert-warning"><strong>Rejected:</strong> <?= e($po['rejection_reason'] ?? '') ?> — edit and resubmit.</div><?php endif; ?>
<?php if (!$catalog): ?><div class="alert alert-info">There are no approved catalog products yet. Add products in the Product Catalog and have an approving officer approve them first.</div><?php endif; ?>
<form method="post" class="needs-validation" novalidate>
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="card mb-3"><div class="card-body row g-3">
    <?php if ($po): ?>
      <div class="col-md-4"><label class="form-label">Supplier</label><input class="form-control" value="<?= e($supOpts[$po['supplier_id']] ?? '') ?>" disabled>
        <input type="hidden" id="f_supplier_id" value="<?= (int)$po['supplier_id'] ?>"></div>
    <?php else: ?>
      <?= form_select('supplier_id', 'Supplier', $supOpts, $f['supplier_id'], $errors, ['required' => true, 'blank' => 'Select a supplier…', 'col' => 'col-md-4']) ?>
    <?php endif; ?>
    <?= form_input('order_date', 'Order date', $f, $errors, ['type' => 'date', 'required' => true, 'col' => 'col-md-3']) ?>
    <?= form_input('expected_date', 'Expected delivery', $f, $errors, ['type' => 'date', 'col' => 'col-md-3']) ?>
    <?= form_input('notes', 'Notes', $f, $errors, ['type' => 'textarea', 'rows' => 2, 'col' => 'col-12', 'maxlength' => 2000]) ?>
  </div></div>
  <div class="card mb-3"><div class="card-header">Order lines <small class="text-muted">(prices are taken from the approved catalog and frozen on the order)</small></div>
    <?php if (isset($errors['lines'])): ?><div class="alert alert-danger m-3 py-2"><?= e($errors['lines']) ?></div><?php endif; ?>
    <div class="table-responsive"><table class="table align-middle mb-0">
      <thead><tr><th>Product</th><th>Quantity</th><th>Price</th><th class="text-end">Line total</th><th></th></tr></thead>
      <tbody id="lineBody"></tbody>
      <tfoot><tr><td colspan="3" class="text-end fw-bold">Order total</td><td class="text-end fw-bold" id="grandTotal">₱0.00</td><td></td></tr></tfoot></table></div>
    <div class="card-body"><button type="button" class="btn btn-outline-success btn-sm" id="addLine"><i class="bi bi-plus-lg"></i> Add line</button></div></div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" type="submit"><i class="bi bi-save"></i> Save as draft</button>
    <button class="btn btn-success" type="submit" name="submit_for_approval" value="1"><i class="bi bi-send-check"></i> Save &amp; submit for approval</button>
    <a class="btn btn-light" href="<?= $po ? 'view.php?id=' . $id : 'index.php' ?>">Cancel</a>
  </div>
</form>
<?php require APP_ROOT . '/includes/layout_footer.php'; ?>
